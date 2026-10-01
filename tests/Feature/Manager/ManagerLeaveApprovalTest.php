<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\Employee;
use App\Models\HR\AttendanceRecord;
use App\Models\HR\LeaveBalance;
use App\Models\HR\LeaveRequest;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
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
        $this->shop->update(['registration_type' => 'company', 'business_type' => 'both']);
        ShopOwnerModule::updateOrCreate(
            ['shop_owner_id' => $this->shop->id, 'module_key' => 'hr_employees'],
            ['enabled' => true],
        );
        config(['shop_modules.enforcement_enabled' => true]);
        $this->manager = User::factory()->for($this->shop)->create([
            'role' => 'MANAGER',
            'status' => 'active',
        ]);
        $this->manager->assignRole('Manager');
        $this->manager->givePermissionTo([
            'access-manager-leave-approvals',
            'decide-manager-leave-approvals',
        ]);
        $this->clockInUser($this->manager);

        $this->employeeUser = User::factory()->for($this->shop)->create([
            'role' => 'STAFF',
            'status' => 'active',
        ]);
        $this->employee = Employee::factory()->active()->for($this->shop)->create([
            'email' => $this->employeeUser->email,
        ]);
        $this->clockInUser($this->employeeUser);

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
        $this->clockInUser($otherUser);

        $this->actingAs($otherUser, 'user')
            ->deleteJson("/api/staff/leave/{$leaveRequest->id}/cancel")
            ->assertNotFound();

        $this->actingAs($this->manager, 'user')
            ->deleteJson("/api/staff/leave/{$leaveRequest->id}/cancel")
            ->assertNotFound();

        Permission::findOrCreate('access-employee-directory', 'user');
        $hr = User::factory()->for($this->shop)->create(['role' => 'HR']);
        $hr->givePermissionTo('access-employee-directory');
        $this->clockInUser($hr);

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
        $this->clockInUser($hr);

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

    public function test_request_id_filter_returns_only_the_linked_leave_request(): void
    {
        $this->createLeaveRequest();
        $target = $this->createLeaveRequest(['reason' => 'Linked leave request']);

        $this->actingAs($this->manager, 'user')
            ->getJson('/api/hr/leave-requests?status=pending&request_id=' . $target->id . '&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id);
    }

    public function test_hr_and_manager_approval_pages_require_the_hr_module_even_when_rbac_allows_them(): void
    {
        $this->shop->update([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::updateOrCreate(
            ['shop_owner_id' => $this->shop->id, 'module_key' => 'hr_employees'],
            ['enabled' => true],
        );
        config(['shop_modules.enforcement_enabled' => true]);

        Permission::findOrCreate('access-hr-dashboard', 'user');
        $hr = User::factory()->for($this->shop)->create([
            'role' => 'HR',
            'status' => 'active',
        ]);
        $hr->givePermissionTo('access-hr-dashboard');

        $this->actingAs($hr, 'user')->get('/erp/hr')->assertOk();
        $this->actingAs($this->manager, 'user')->get('/erp/manager/leave-approvals')->assertOk();

        ShopOwnerModule::query()
            ->where('shop_owner_id', $this->shop->id)
            ->where('module_key', 'hr_employees')
            ->update(['enabled' => false]);

        $this->actingAs($hr, 'user')->get('/erp/hr')->assertRedirect(url('/erp/staff/dashboard'));
        $this->actingAs($this->manager, 'user')->get('/erp/manager/leave-approvals')->assertRedirect(url('/erp/staff/dashboard'));
        $this->actingAs($hr, 'user')->getJson('/api/hr/leave-requests')->assertForbidden();
        $this->actingAs($this->manager, 'user')->getJson('/api/hr/leave-requests')->assertForbidden();
    }

    public function test_manager_role_can_read_and_decide_leave_without_legacy_hr_permission(): void
    {
        $leaveRequest = $this->createLeaveRequest();

        // The Manager capability middleware and LeaveController authorize the
        // modern Manager flow when the shop HR module is enabled. The Manager
        // capability remains distinct from the legacy HR permission mapping.
        $this->manager->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->manager, 'user')
            ->getJson('/api/hr/leave-requests?status=pending&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.id', $leaveRequest->id);

        $this->actingAs($this->manager, 'user')
            ->postJson("/api/hr/leave-requests/{$leaveRequest->id}/approve", [
                'reason' => 'Coverage confirmed for the requested dates.',
            ])
            ->assertOk()
            ->assertJsonPath('leaveRequest.status', 'approved');
    }

    public function test_user_without_leave_access_cannot_read_leave_queue(): void
    {
        $this->createLeaveRequest();
        $staff = User::factory()->for($this->shop)->create([
            'role' => 'STAFF',
            'status' => 'active',
        ]);

        $this->actingAs($staff, 'user')
            ->getJson('/api/hr/leave-requests?status=pending&per_page=10')
            ->assertForbidden();
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

    private function clockInUser(User $user): void
    {
        $employee = Employee::query()
            ->where('shop_owner_id', $this->shop->id)
            ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
            ->first()
            ?? Employee::factory()->active()->for($this->shop)->create(['email' => $user->email]);

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $this->shop->id,
            'date' => now(config('app.shop_timezone', 'Asia/Manila'))->toDateString(),
            'check_in_time' => '08:00:00',
            'status' => 'present',
        ]);
    }
}
