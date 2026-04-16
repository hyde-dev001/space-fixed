<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\HR\OvertimeRequest;
use App\Models\User;
use App\Notifications\HR\OvertimeRequestApproved;
use App\Notifications\HR\OvertimeRequestRejected;
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
        $freshRequest = $overtimeRequest->fresh(['employee.user', 'approver']);

        $this->dispatchRejectedNotifications($freshRequest, $rejector, $reason);

        return $freshRequest;
    }

    private function dispatchApprovedNotifications(OvertimeRequest $overtimeRequest, User $approver): void
    {
        $employeeUser = $this->resolveEmployeeUser($overtimeRequest->employee);
        $employeeUserId = (int) ($employeeUser?->id ?? 0);

        if ($employeeUserId <= 0) {
            return;
        }

        $employeeUser->notify(new OvertimeRequestApproved($overtimeRequest, $approver));

        $this->notificationService->notifyOvertimeApproved($employeeUserId, (int) $overtimeRequest->shop_owner_id, [
            'overtime_id' => $overtimeRequest->id,
            'date' => optional($overtimeRequest->overtime_date)->toDateString() ?? (string) $overtimeRequest->overtime_date,
            'hours' => (float) ($overtimeRequest->hours ?? 0),
        ]);
    }

    private function dispatchRejectedNotifications(OvertimeRequest $overtimeRequest, User $rejector, string $reason): void
    {
        $employeeUser = $this->resolveEmployeeUser($overtimeRequest->employee);
        $employeeUserId = (int) ($employeeUser?->id ?? 0);

        if ($employeeUserId <= 0) {
            return;
        }

        $employeeUser->notify(new OvertimeRequestRejected($overtimeRequest, $rejector, $reason));

        $this->notificationService->notifyOvertimeRejected($employeeUserId, (int) $overtimeRequest->shop_owner_id, [
            'overtime_request_id' => $overtimeRequest->id,
            'overtime_date' => optional($overtimeRequest->overtime_date)->toDateString() ?? (string) $overtimeRequest->overtime_date,
            'hours' => (float) ($overtimeRequest->hours ?? 0),
            'rejection_reason' => $reason,
        ]);
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
