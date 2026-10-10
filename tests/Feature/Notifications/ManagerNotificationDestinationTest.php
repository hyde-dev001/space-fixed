<?php

namespace Tests\Feature\Notifications;

use App\Models\ShopOwner;
use App\Models\User;
use App\Models\Employee;
use App\Models\HR\LeaveRequest;
use App\Models\HR\OvertimeRequest;
use App\Notifications\HR\LeaveRequestApproved;
use App\Notifications\HR\LeaveRequestRejected;
use App\Notifications\HR\LeaveRequestSubmitted;
use App\Notifications\HR\OvertimeRequestApproved;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ManagerNotificationDestinationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_notifications_deep_link_to_canonical_operational_pages(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $manager = User::factory()->for($shop)->create(['role' => 'Manager']);
        $service = app(NotificationService::class);

        $service->notifyLeaveApproval(
            managerId: $manager->id,
            leaveData: [
                'leave_request_id' => 718,
                'employee_name' => 'Staff A',
                'start_date' => '2026-08-28',
                'end_date' => '2026-08-29',
            ],
            shopId: $shop->id,
        );
        $service->notifySuspensionRequestPending(
            managerId: $manager->id,
            suspensionData: ['employee_name' => 'Staff B'],
            shopId: $shop->id,
        );
        $service->notifyRepairRejectionReview(
            managerId: $manager->id,
            repairData: ['reason' => 'Repairer unavailable.'],
            shopId: $shop->id,
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $manager->id,
            'action_url' => '/erp/manager/leave-approvals?request=718',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $manager->id,
            'action_url' => '/erp/manager/suspension-approvals',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $manager->id,
            'action_url' => '/erp/manager/repair-jobs',
        ]);
    }

    public function test_submitted_leave_notifications_use_the_recipient_role_and_preserve_the_request_id(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $manager = User::factory()->for($shop)->create(['role' => 'Manager']);
        $hr = User::factory()->for($shop)->create(['role' => 'HR']);
        $manager->assignRole(Role::findOrCreate('Manager', 'user'));
        $hr->assignRole(Role::findOrCreate('HR', 'user'));

        app(NotificationService::class)->notifyLeaveSubmitted($shop->id, [
            'leave_request_id' => 912,
            'employee_name' => 'Staff A',
            'leave_type' => 'vacation',
            'no_of_days' => 2,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-06',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $manager->id,
            'action_url' => '/erp/manager/leave-approvals?request=912',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $hr->id,
            'action_url' => '/erp/hr?section=leaves&request=912',
        ]);
    }

    public function test_employee_leave_and_overtime_notifications_use_time_in_and_distinct_overtime_copy(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $employee = User::factory()->for($shop)->create(['role' => 'STAFF']);
        $service = app(NotificationService::class);

        $service->notifyLeaveApproved($employee->id, $shop->id, [
            'leave_request_id' => 401,
            'leave_type' => 'vacation',
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-06',
        ]);
        $service->notifyLeaveRejected($employee->id, $shop->id, [
            'leave_request_id' => 402,
            'leave_type' => 'vacation',
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-06',
            'reason' => 'Coverage is unavailable.',
        ]);
        $service->notifyOvertimeAssigned($employee->id, $shop->id, [
            'overtime_id' => 501,
            'date' => '2026-10-07',
            'hours' => 2,
        ]);
        $service->notifyOvertimeApproved($employee->id, $shop->id, [
            'overtime_id' => 502,
            'date' => '2026-10-08',
            'hours' => 2,
        ]);
        $service->notifyOvertimeRejected($employee->id, $shop->id, [
            'overtime_request_id' => 503,
            'overtime_date' => '2026-10-09',
            'hours' => 2,
            'rejection_reason' => 'Not approved.',
        ]);

        foreach ([401, 402] as $requestId) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $employee->id,
                'action_url' => "/erp/time-in?request={$requestId}",
            ]);
        }
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'overtime_assigned',
            'title' => 'Overtime Assigned',
            'message' => 'Overtime has been assigned to you for 2026-10-07.',
            'action_url' => '/erp/time-in?overtime=501',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'overtime_request_approved',
            'title' => 'Overtime Request Approved',
            'message' => 'Your overtime request for 2026-10-08 has been approved.',
            'action_url' => '/erp/time-in?overtime=502',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'overtime_request_rejected',
            'action_url' => '/erp/time-in?overtime=503',
        ]);
    }

    public function test_database_hr_notifications_keep_role_aware_and_employee_destinations(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $manager = User::factory()->for($shop)->create(['role' => 'Manager']);
        $manager->assignRole(Role::findOrCreate('Manager', 'user'));
        $employeeUser = User::factory()->for($shop)->create(['role' => 'STAFF']);
        $employee = Employee::factory()->active()->for($shop)->create([
            'email' => $employeeUser->email,
        ]);
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shop->id,
            'leave_type' => 'vacation',
            'start_date' => now()->addDays(2),
            'end_date' => now()->addDays(3),
            'no_of_days' => 2,
            'reason' => 'Planned leave',
            'status' => 'pending',
        ]);
        $leave->setAttribute('id', 914);

        $submitted = new LeaveRequestSubmitted($leave, $employee, [
            'approval_level' => 1,
            'is_delegated' => false,
        ]);
        $managerAction = parse_url($submitted->toArray($manager)['action_url'], PHP_URL_PATH)
            . '?' . parse_url($submitted->toArray($manager)['action_url'], PHP_URL_QUERY);
        $this->assertSame('/erp/manager/leave-approvals?request=914', $managerAction);

        $approved = (new LeaveRequestApproved($leave, $manager))->toArray($employeeUser);
        $rejected = (new LeaveRequestRejected($leave, $manager))->toArray($employeeUser);
        $this->assertSame('/erp/time-in?request=914', $approved['action_url']);
        $this->assertSame('/erp/time-in?request=914', $rejected['action_url']);

        $overtime = new OvertimeRequest([
            'id' => 915,
            'overtime_date' => now()->addDay(),
            'hours' => 2,
            'reason' => 'Scheduled support',
        ]);
        $overtime->setAttribute('id', 915);
        $overtimeData = (new OvertimeRequestApproved($overtime, $manager))->toArray($employeeUser);
        $this->assertSame('Overtime Request Approved', $overtimeData['title']);
        $this->assertSame('/erp/time-in?overtime=915', $overtimeData['action_url']);
    }
}
