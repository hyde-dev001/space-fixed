<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\HR\OvertimeRequest;
use App\Models\User;
use App\Notifications\HR\OvertimeRequestApproved;
use App\Services\NotificationService;

class OvertimeApprovalService
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function notifyOvertimeSubmitted(OvertimeRequest $overtimeRequest, Employee $employee): void
    {
        $this->notificationService->notifyOvertimeSubmitted((int) $overtimeRequest->shop_owner_id, [
            'overtime_request_id' => $overtimeRequest->id,
            'employee_name' => trim((string) ($employee->first_name ?? '') . ' ' . (string) ($employee->last_name ?? '')),
            'hours' => (float) ($overtimeRequest->hours ?? 0),
            'overtime_date' => optional($overtimeRequest->overtime_date)->toDateString() ?? (string) $overtimeRequest->overtime_date,
            'reason' => (string) ($overtimeRequest->reason ?? ''),
        ]);
    }

    public function approveOvertimeRequest(OvertimeRequest $overtimeRequest, User $approver, ?string $notes = null): OvertimeRequest
    {
        if ((string) $overtimeRequest->status !== 'pending') {
            throw new \RuntimeException('Only pending requests can be approved');
        }

        $overtimeRequest->approve($approver->id, $notes);
        $freshRequest = $overtimeRequest->fresh(['employee.user']);

        $this->dispatchApprovedNotifications($freshRequest, $approver);

        return $freshRequest;
    }

    public function rejectOvertimeRequest(OvertimeRequest $overtimeRequest, User $rejector, string $reason): OvertimeRequest
    {
        if ((string) $overtimeRequest->status !== 'pending') {
            throw new \RuntimeException('Only pending requests can be rejected');
        }

        $overtimeRequest->reject($rejector->id, $reason);
        $freshRequest = $overtimeRequest->fresh(['employee.user']);

        $this->dispatchRejectedNotifications($freshRequest, $reason);

        return $freshRequest;
    }

    private function dispatchApprovedNotifications(OvertimeRequest $overtimeRequest, User $approver): void
    {
        $employeeUserId = (int) ($overtimeRequest->employee?->user?->id ?? 0);

        if ($employeeUserId <= 0) {
            return;
        }

        $overtimeRequest->employee->user->notify(new OvertimeRequestApproved($overtimeRequest, $approver));

        $this->notificationService->notifyOvertimeApproved($employeeUserId, (int) $overtimeRequest->shop_owner_id, [
            'overtime_id' => $overtimeRequest->id,
            'date' => optional($overtimeRequest->overtime_date)->toDateString() ?? (string) $overtimeRequest->overtime_date,
            'hours' => (float) ($overtimeRequest->hours ?? 0),
        ]);
    }

    private function dispatchRejectedNotifications(OvertimeRequest $overtimeRequest, string $reason): void
    {
        $employeeUserId = (int) ($overtimeRequest->employee?->user?->id ?? 0);

        if ($employeeUserId <= 0) {
            return;
        }

        $this->notificationService->notifyOvertimeRejected($employeeUserId, (int) $overtimeRequest->shop_owner_id, [
            'overtime_request_id' => $overtimeRequest->id,
            'overtime_date' => optional($overtimeRequest->overtime_date)->toDateString() ?? (string) $overtimeRequest->overtime_date,
            'hours' => (float) ($overtimeRequest->hours ?? 0),
            'rejection_reason' => $reason,
        ]);
    }
}
