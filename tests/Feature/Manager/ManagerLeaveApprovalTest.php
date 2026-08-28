<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\Employee;
use App\Models\HR\LeaveBalance;
use App\Models\HR\LeaveRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ManagerLeaveApprovalTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shop;

    private User $manager;

    private Employee $employee;

    private User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Role::findOrCreate('Manager', 'user');
        Permission::findOrCreate('access-manager-leave-approvals', 'user');
        Permission::findOrCreate('decide-manager-leave-approvals', 'user');
        $this->shop = ShopOwner::factory()->approved()->create();
        $this->manager = User::factory()->for($this->shop)->create([
            'role' => 'MANAGER',
            'status' => 'active',
        ]);
        $this->manager->assignRole('Manager');
        $this->manager->givePermissionTo([
            'access-manager-leave-approvals',
            'decide-manager-leave-approvals',
        ]);

        $this->employeeUser = User::factory()->for($this->shop)->create([
            'role' => 'STAFF',
            'status' => 'active',
        ]);
        $this->employee = Employee::factory()->active()->for($this->shop)->create([
            'email' => $this->employeeUser->email,
        ]);

        LeaveBalance::createForNewEmployee($this->employee->id, $this->shop->id, now()->year);
    }

    public function test_manager_approval_is_terminal_deducts_once_and_records_history(): void
    {
        $leaveRequest = $this->createLeaveRequest();
        $before = (int) LeaveBalance::where('employee_id', $this->employee->id)->value('used_vacation');

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/hr/leave-requests/{$leaveRequest->id}/approve", [
                'reason' => 'Coverage confirmed for the requested dates.',
            ])
            ->assertOk()
            ->assertJsonPath('leaveRequest.status', 'approved')
            ->assertJsonPath('leaveRequest.approved_by', $this->manager->id);

        $this->assertSame($before + 2, (int) LeaveBalance::where('employee_id', $this->employee->id)->value('used_vacation'));
        $this->assertDatabaseHas('hr_audit_logs', [
            'shop_owner_id' => $this->shop->id,
            'user_id' => $this->manager->id,
            'module' => 'leave',
            'action' => 'approved',
            'entity_id' => $leaveRequest->id,
        ]);

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/hr/leave-requests/{$leaveRequest->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('code', 'LEAVE_REQUEST_ALREADY_DECIDED');

        $this->assertSame($before + 2, (int) LeaveBalance::where('employee_id', $this->employee->id)->value('used_vacation'));
    }

    public function test_manager_rejection_requires_reason_and_does_not_deduct_balance(): void
    {
        $leaveRequest = $this->createLeaveRequest([
            'start_date' => now()->next('Monday')->addWeeks(2),
            'end_date' => now()->next('Monday')->addWeeks(2)->addDay(),
        ]);
        $before = (int) LeaveBalance::where('employee_id', $this->employee->id)->value('used_vacation');

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/hr/leave-requests/{$leaveRequest->id}/reject", [])
            ->assertUnprocessable();

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/hr/leave-requests/{$leaveRequest->id}/reject", [
                'reason' => 'Not enough coverage for the requested dates.',
            ])
            ->assertOk()
            ->assertJsonPath('leaveRequest.status', 'rejected')
            ->assertJsonPath('leaveRequest.rejection_reason', 'Not enough coverage for the requested dates.');

        $this->assertSame($before, (int) LeaveBalance::where('employee_id', $this->employee->id)->value('used_vacation'));
        $this->assertDatabaseHas('hr_audit_logs', [
            'shop_owner_id' => $this->shop->id,
            'user_id' => $this->manager->id,
            'module' => 'leave',
            'action' => 'rejected',
            'entity_id' => $leaveRequest->id,
        ]);
    }

    public function test_only_the_requesting_employee_can_cancel_own_pending_leave(): void
    {
        $leaveRequest = $this->createLeaveRequest();
        $otherUser = User::factory()->for($this->shop)->create([
            'role' => 'STAFF',
            'status' => 'active',
        ]);
        Employee::factory()->active()->for($this->shop)->create(['email' => $otherUser->email]);

        $this->actingAs($otherUser, 'user')
            ->deleteJson("/api/staff/leave/{$leaveRequest->id}/cancel")
            ->assertNotFound();

        $this->actingAs($this->manager, 'user')
            ->deleteJson("/api/staff/leave/{$leaveRequest->id}/cancel")
            ->assertNotFound();

        Permission::findOrCreate('access-employee-directory', 'user');
        $hr = User::factory()->for($this->shop)->create(['role' => 'HR']);
        $hr->givePermissionTo('access-employee-directory');

        $this->actingAs($hr, 'user')
            ->deleteJson("/api/leave/{$leaveRequest->id}/cancel")
            ->assertNotFound();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->employeeUser, 'user')
            ->deleteJson("/api/staff/leave/{$leaveRequest->id}/cancel")
            ->assertOk();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'rejected',
            'rejection_reason' => 'Cancelled by employee',
        ]);
    }

    public function test_employee_directory_access_does_not_grant_leave_decision_authority(): void
    {
        $leaveRequest = $this->createLeaveRequest();
        Permission::findOrCreate('access-employee-directory', 'user');
        $hr = User::factory()->for($this->shop)->create(['role' => 'HR']);
        $hr->givePermissionTo('access-employee-directory');

        $this->actingAs($hr, 'user')
            ->postJson("/api/hr/leave-requests/{$leaveRequest->id}/approve")
            ->assertForbidden();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'pending',
        ]);
    }

    public function test_legacy_and_canonical_leave_lists_use_the_same_scoped_shape(): void
    {
        $leaveRequest = $this->createLeaveRequest();
        $otherShop = ShopOwner::factory()->approved()->create();
        $otherEmployee = Employee::factory()->active()->for($otherShop)->create();
        $otherRequest = LeaveRequest::create([
            'employee_id' => $otherEmployee->id,
            'shop_owner_id' => $otherShop->id,
            'leave_type' => 'vacation',
            'start_date' => now()->next('Monday'),
            'end_date' => now()->next('Monday')->addDay(),
            'no_of_days' => 2,
            'reason' => 'Other shop leave',
            'status' => 'pending',
        ]);

        $legacy = $this->actingAs($this->manager, 'user')
            ->getJson('/api/leave?status=pending&per_page=10')
            ->assertOk();
        $canonical = $this->actingAs($this->manager, 'user')
            ->getJson('/api/hr/leave-requests?status=pending&per_page=10')
            ->assertOk();

        $this->assertSame(
            $canonical->json('data.0.id'),
            $legacy->json('data.0.id'),
        );
        $this->assertSame($leaveRequest->id, (int) $canonical->json('data.0.id'));
        $this->assertFalse(collect($legacy->json('data'))->pluck('id')->contains($otherRequest->id));
        $this->assertSame(
            collect($canonical->json('data.0'))->keys()->sort()->values()->all(),
            collect($legacy->json('data.0'))->keys()->sort()->values()->all(),
        );
    }

    private function createLeaveRequest(array $overrides = []): LeaveRequest
    {
        $startDate = now()->next('Monday');

        return LeaveRequest::create(array_merge([
            'employee_id' => $this->employee->id,
            'shop_owner_id' => $this->shop->id,
            'leave_type' => 'vacation',
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addDay(),
            'reason' => 'Planned personal leave',
            'status' => 'pending',
            'approval_level' => 1,
        ], $overrides));
    }
}
