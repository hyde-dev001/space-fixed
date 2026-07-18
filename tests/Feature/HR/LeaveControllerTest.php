<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\HR\LeaveBalance;
use App\Models\HR\LeaveRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LeaveControllerTest extends TestCase
{
    use RefreshDatabase;

    protected ShopOwner $shopOwner;
    protected User $hrUser;
    protected User $managerUser;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-employee-directory', 'user');

        $this->shopOwner = ShopOwner::factory()->approved()->create();
        $this->hrUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'role' => 'HR',
        ]);
        $this->hrUser->givePermissionTo('access-employee-directory');

        $this->employee = Employee::factory()->active()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'email' => $this->hrUser->email,
        ]);

        $this->managerUser = User::factory()->create([
            'shop_owner_id' => $this->shopOwner->id,
            'role' => 'manager',
        ]);
        $this->managerUser->givePermissionTo('access-employee-directory');

        LeaveBalance::createForNewEmployee($this->employee->id, $this->shopOwner->id, now()->year);
    }

    #[Test]
    public function test_employee_can_apply_leave(): void
    {
        $startDate = now()->next('Monday');
        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/staff/leave/request', [
                'leave_type' => 'vacation',
                'start_date' => $startDate->toDateString(),
                'end_date' => $startDate->copy()->addDays(2)->toDateString(),
                'reason' => 'Family vacation',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'leave_request' => ['id', 'leave_type', 'status'],
            ]);

        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $this->employee->id,
            'status' => 'pending',
            'no_of_days' => 3,
        ]);
    }

    #[Test]
    public function test_leave_balance_validated(): void
    {
        $startDate = now()->next('Monday');
        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/staff/leave/request', [
                'leave_type' => 'vacation',
                'start_date' => $startDate->toDateString(),
                'end_date' => $startDate->copy()->addDays(29)->toDateString(),
                'reason' => 'Extended vacation',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('leave_requests', [
            'employee_id' => $this->employee->id,
        ]);
    }

    #[Test]
    public function test_manager_can_approve_team_leave(): void
    {
        $leaveRequest = $this->createLeaveRequest($this->employee);

        $response = $this->actingAs($this->hrUser, 'user')
            ->postJson("/api/hr/leave-requests/{$leaveRequest->id}/approve");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Leave request approved successfully']);

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'approved',
            'approved_by' => $this->hrUser->id,
        ]);
    }

    #[Test]
    public function test_manager_cannot_approve_other_team_leave(): void
    {
        $otherShopOwner = ShopOwner::factory()->create();
        $otherEmployee = Employee::factory()->create(['shop_owner_id' => $otherShopOwner->id]);
        $leaveRequest = $this->createLeaveRequest($otherEmployee);

        $response = $this->actingAs($this->managerUser, 'user')
            ->postJson("/api/hr/leave-requests/{$leaveRequest->id}/approve");

        $response->assertStatus(404);
        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function test_leave_deducted_from_balance_on_approval(): void
    {
        $startDate = now()->next('Monday');
        $leaveRequest = $this->createLeaveRequest($this->employee, [
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDays(4),
        ]);
        $initialUsedVacation = (int) LeaveBalance::where('employee_id', $this->employee->id)
            ->value('used_vacation');

        $this->actingAs($this->hrUser, 'user')
            ->postJson("/api/hr/leave-requests/{$leaveRequest->id}/approve")
            ->assertStatus(200);

        $updatedBalance = LeaveBalance::where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame($initialUsedVacation + 5, (int) $updatedBalance->used_vacation);
    }

    #[Test]
    public function test_can_reject_leave_request(): void
    {
        $leaveRequest = $this->createLeaveRequest($this->employee);

        $this->actingAs($this->hrUser, 'user')
            ->postJson("/api/hr/leave-requests/{$leaveRequest->id}/reject", [
                'reason' => 'Insufficient staffing',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'rejected',
            'rejection_reason' => 'Insufficient staffing',
        ]);
    }

    #[Test]
    public function test_can_get_employee_leave_balance(): void
    {
        $this->actingAs($this->hrUser, 'user')
            ->getJson("/api/hr/leave-requests/employee/{$this->employee->id}/balance")
            ->assertStatus(200)
            ->assertJsonStructure([
                'employee_id',
                'year',
                'vacation_days',
                'used_vacation',
            ]);
    }

    #[Test]
    public function test_cannot_apply_overlapping_leave(): void
    {
        $startDate = now()->next('Monday');
        $this->createLeaveRequest($this->employee, [
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDays(4),
            'status' => 'approved',
        ]);

        $this->actingAs($this->hrUser, 'user')
            ->postJson('/api/staff/leave/request', [
                'leave_type' => 'vacation',
                'start_date' => $startDate->copy()->addDays(2)->toDateString(),
                'end_date' => $startDate->copy()->addDays(6)->toDateString(),
                'reason' => 'Another vacation',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function test_can_list_all_leave_requests(): void
    {
        foreach (range(1, 5) as $offset) {
            $this->createLeaveRequest($this->employee, [
                'start_date' => now()->next('Monday')->addWeeks($offset),
                'end_date' => now()->next('Tuesday')->addWeeks($offset),
            ]);
        }

        $this->actingAs($this->hrUser, 'user')
            ->getJson('/api/hr/leave-requests')
            ->assertStatus(200)
            ->assertJsonCount(5, 'data');
    }

    private function createLeaveRequest(Employee $employee, array $overrides = []): LeaveRequest
    {
        $startDate = now()->next('Monday');

        return LeaveRequest::create(array_merge([
            'employee_id' => $employee->id,
            'shop_owner_id' => $employee->shop_owner_id,
            'leave_type' => 'vacation',
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDay(),
            'reason' => 'Planned leave',
            'status' => 'pending',
            'approval_level' => 1,
        ], $overrides));
    }
}
