<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Models\HR\LeaveBalance;
use App\Models\HR\LeaveRequest;
use App\Models\User;
use App\Notifications\HR\LeaveRequestApproved;
use App\Notifications\HR\LeaveRequestRejected;
use App\Notifications\HR\LeaveRequestSubmitted;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class LeaveApprovalService
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function notifyLeaveSubmitted(LeaveRequest $leaveRequest, Employee $employee, ?array $approverInfo = null): void
    {
        try {
            $this->notificationService->notifyLeaveSubmitted((int) $leaveRequest->shop_owner_id, [
                'leave_request_id' => $leaveRequest->id,
                'employee_name' => trim((string) ($employee->first_name ?? '') . ' ' . (string) ($employee->last_name ?? '')),
                'leave_type' => (string) ($leaveRequest->leave_type ?? $leaveRequest->leaveType),
                'no_of_days' => (float) ($leaveRequest->no_of_days ?? $leaveRequest->noOfDays ?? 0),
                'start_date' => optional($leaveRequest->start_date ?? $leaveRequest->startDate)->toDateString(),
                'end_date' => optional($leaveRequest->end_date ?? $leaveRequest->endDate)->toDateString(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send leave submitted live notification', [
                'leave_request_id' => $leaveRequest->id,
                'error' => $e->getMessage(),
            ]);
        }

        if (! $approverInfo || ! isset($approverInfo['approver'])) {
            return;
        }

        try {
            $approver = $approverInfo['approver'];
            Notification::send($approver, new LeaveRequestSubmitted($leaveRequest, $employee, $approverInfo));

            AuditLog::createLog([
                'employee_id' => $leaveRequest->employee_id,
                'module' => AuditLog::MODULE_LEAVE,
                'action' => 'notification_sent',
                'entity_type' => LeaveRequest::class,
                'entity_id' => $leaveRequest->id,
                'description' => 'Leave approval notification sent to ' . $approver->name,
                'severity' => AuditLog::SEVERITY_INFO,
                'tags' => ['notification', 'leave_approval'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send leave approver notification', [
                'leave_request_id' => $leaveRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function approveLeaveRequest(LeaveRequest $leaveRequest, User $approver): LeaveRequest
    {
        if ((string) $leaveRequest->status !== 'pending') {
            throw new \RuntimeException('Leave request is not pending');
        }

        DB::beginTransaction();

        try {
            $leaveRequest->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approval_date' => now(),
            ]);

            $leaveBalance = LeaveBalance::forEmployee((int) $leaveRequest->employee_id)
                ->forYear((int) optional($leaveRequest->start_date)->year)
                ->forShopOwner((int) $leaveRequest->shop_owner_id)
                ->first();

            if (! $leaveBalance) {
                $leaveBalance = LeaveBalance::createForNewEmployee(
                    (int) $leaveRequest->employee_id,
                    (int) $leaveRequest->shop_owner_id,
                    (int) optional($leaveRequest->start_date)->year
                );
            }

            $leaveBalance->deductForType((string) $leaveRequest->leave_type, (float) ($leaveRequest->no_of_days ?? 0));

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $freshLeaveRequest = $leaveRequest->fresh(['employee', 'approver']);
        $this->dispatchLeaveApprovedNotifications($freshLeaveRequest, $approver);

        return $freshLeaveRequest;
    }

    public function rejectLeaveRequest(LeaveRequest $leaveRequest, User $rejector, string $reason): LeaveRequest
    {
        if ((string) $leaveRequest->status !== 'pending') {
            throw new \RuntimeException('Leave request is not pending');
        }

        $leaveRequest->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        Log::info('Leave request rejected', [
            'rejector_id' => $rejector->id,
            'leave_request_id' => $leaveRequest->id,
            'employee_id' => $leaveRequest->employee_id,
            'rejection_reason' => $reason,
        ]);

        $freshLeaveRequest = $leaveRequest->fresh(['employee', 'approver']);
        $this->dispatchLeaveRejectedNotifications($freshLeaveRequest, $rejector, $reason);

        return $freshLeaveRequest;
    }

    private function dispatchLeaveApprovedNotifications(LeaveRequest $leaveRequest, User $approver): void
    {
        $employeeUser = $this->resolveEmployeeUser($leaveRequest->employee);
        if (! $employeeUser) {
            Log::warning('Leave approved but no linked ERP user found for requester notification', [
                'employee_id' => $leaveRequest->employee?->id,
                'employee_email' => $leaveRequest->employee?->email,
                'shop_owner_id' => $leaveRequest->employee?->shop_owner_id,
                'leave_request_id' => $leaveRequest->id,
            ]);
            return;
        }

        $this->notificationService->notifyLeaveApproved($employeeUser->id, (int) $leaveRequest->shop_owner_id, [
            'leave_request_id' => $leaveRequest->id,
            'leave_type' => $leaveRequest->leave_type,
            'start_date' => optional($leaveRequest->start_date)->toDateString(),
            'end_date' => optional($leaveRequest->end_date)->toDateString(),
        ]);

        $employeeUser->notify(new LeaveRequestApproved($leaveRequest, $approver));
    }

    private function dispatchLeaveRejectedNotifications(LeaveRequest $leaveRequest, User $rejector, string $reason): void
    {
        $employeeUser = $this->resolveEmployeeUser($leaveRequest->employee);
        if (! $employeeUser) {
            Log::warning('Leave rejected but no linked ERP user found for requester notification', [
                'employee_id' => $leaveRequest->employee?->id,
                'employee_email' => $leaveRequest->employee?->email,
                'shop_owner_id' => $leaveRequest->employee?->shop_owner_id,
                'leave_request_id' => $leaveRequest->id,
            ]);
            return;
        }

        $this->notificationService->notifyLeaveRejected($employeeUser->id, (int) $leaveRequest->shop_owner_id, [
            'leave_request_id' => $leaveRequest->id,
            'leave_type' => $leaveRequest->leave_type,
            'start_date' => optional($leaveRequest->start_date)->toDateString(),
            'end_date' => optional($leaveRequest->end_date)->toDateString(),
            'reason' => $reason,
        ]);

        $employeeUser->notify(new LeaveRequestRejected($leaveRequest, $rejector));
    }

    private function resolveEmployeeUser(?Employee $employee): ?User
    {
        if (! $employee) {
            return null;
        }

        $email = trim((string) $employee->email);
        if ($email === '') {
            return null;
        }

        return User::query()
            ->where('email', $email)
            ->where('shop_owner_id', $employee->shop_owner_id)
            ->first();
    }
}
