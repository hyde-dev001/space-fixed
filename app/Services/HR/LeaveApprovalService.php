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

    public function approveLeaveRequest(LeaveRequest $leaveRequest, User $approver, ?string $reason = null): LeaveRequest
    {
        $leaveRequestId = (int) $leaveRequest->id;
        $shopOwnerId = (int) $leaveRequest->shop_owner_id;

        DB::transaction(function () use ($leaveRequestId, $shopOwnerId, $approver, $reason): void {
            $lockedLeaveRequest = LeaveRequest::query()
                ->whereKey($leaveRequestId)
                ->where('shop_owner_id', $shopOwnerId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedLeaveRequest->status !== 'pending') {
                throw new \RuntimeException('Leave request has already been decided.', 409);
            }

            $year = (int) optional($lockedLeaveRequest->start_date)->year ?: (int) now()->year;
            $leaveBalance = LeaveBalance::forEmployee((int) $lockedLeaveRequest->employee_id)
                ->forYear($year)
                ->forShopOwner($shopOwnerId)
                ->lockForUpdate()
                ->first();

            if (! $leaveBalance) {
                $leaveBalance = LeaveBalance::createForNewEmployee(
                    (int) $lockedLeaveRequest->employee_id,
                    $shopOwnerId,
                    $year,
                );
            }

            $days = (float) ($lockedLeaveRequest->no_of_days ?? 0);
            if (! $leaveBalance->hasSufficientBalance((string) $lockedLeaveRequest->leave_type, $days)) {
                throw new \RuntimeException('Insufficient leave balance for this approval.', 422);
            }

            $approvalDate = now();
            $lockedLeaveRequest->forceFill([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approver_id' => $approver->id,
                'approval_date' => $approvalDate,
                'approver_comments' => $reason,
            ])->save();

            $leaveBalance->deductForType((string) $lockedLeaveRequest->leave_type, $days);

            AuditLog::createLog([
                'shop_owner_id' => $shopOwnerId,
                'user_id' => $approver->id,
                'employee_id' => $lockedLeaveRequest->employee_id,
                'module' => AuditLog::MODULE_LEAVE,
                'action' => AuditLog::ACTION_APPROVED,
                'entity_type' => LeaveRequest::class,
                'entity_id' => $lockedLeaveRequest->id,
                'description' => 'Leave request approved by '.$approver->name,
                'old_values' => ['status' => 'pending'],
                'new_values' => [
                    'status' => 'approved',
                    'approved_by' => $approver->id,
                    'approval_date' => $approvalDate->toIso8601String(),
                    'reason' => $reason,
                    'reference_id' => 'leave:' . $lockedLeaveRequest->id,
                ],
                'severity' => AuditLog::SEVERITY_WARNING,
                'tags' => ['leave', 'approval', 'workflow'],
            ]);
        });

        $freshLeaveRequest = $leaveRequest->fresh(['employee', 'approver']);
        $this->dispatchLeaveApprovedNotifications($freshLeaveRequest, $approver);

        return $freshLeaveRequest;
    }

    public function rejectLeaveRequest(LeaveRequest $leaveRequest, User $rejector, string $reason): LeaveRequest
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('A rejection reason is required.', 422);
        }

        $leaveRequestId = (int) $leaveRequest->id;
        $shopOwnerId = (int) $leaveRequest->shop_owner_id;

        DB::transaction(function () use ($leaveRequestId, $shopOwnerId, $rejector, $reason): void {
            $lockedLeaveRequest = LeaveRequest::query()
                ->whereKey($leaveRequestId)
                ->where('shop_owner_id', $shopOwnerId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedLeaveRequest->status !== 'pending') {
                throw new \RuntimeException('Leave request has already been decided.', 409);
            }

            $lockedLeaveRequest->forceFill([
                'status' => 'rejected',
                'approved_by' => $rejector->id,
                'approver_id' => $rejector->id,
                'approval_date' => now(),
                'rejection_reason' => $reason,
            ])->save();

            AuditLog::createLog([
                'shop_owner_id' => $shopOwnerId,
                'user_id' => $rejector->id,
                'employee_id' => $lockedLeaveRequest->employee_id,
                'module' => AuditLog::MODULE_LEAVE,
                'action' => AuditLog::ACTION_REJECTED,
                'entity_type' => LeaveRequest::class,
                'entity_id' => $lockedLeaveRequest->id,
                'description' => 'Leave request rejected by '.$rejector->name,
                'old_values' => ['status' => 'pending'],
                'new_values' => [
                    'status' => 'rejected',
                    'rejected_by' => $rejector->id,
                    'rejection_reason' => $reason,
                    'reference_id' => 'leave:' . $lockedLeaveRequest->id,
                ],
                'severity' => AuditLog::SEVERITY_WARNING,
                'tags' => ['leave', 'rejection', 'workflow'],
            ]);
        });

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

        $scopedUser = User::query()
            ->where('email', $email)
            ->where('shop_owner_id', $employee->shop_owner_id)
            ->first();

        if ($scopedUser) {
            return $scopedUser;
        }

        return User::query()
            ->where('email', $email)
            ->first();
    }
}
