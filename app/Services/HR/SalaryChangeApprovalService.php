<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\HR\SalaryChange;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

class SalaryChangeApprovalService
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function notifySalaryChangeSubmitted(SalaryChange $change, Employee $employee, User $proposer, string $reason): void
    {
        $this->notificationService->notifySalaryChangeSubmittedToShopOwner(
            (int) $change->shop_owner_id,
            [
                'salary_change_id' => $change->id,
                'employee_id' => $employee->id,
                'employee_name' => trim((string) ($employee->first_name ?? '') . ' ' . (string) ($employee->last_name ?? '')),
                'previous_salary' => (float) $change->previous_salary,
                'new_salary' => (float) $change->new_salary,
                'effective_date' => $change->effective_date?->toDateString(),
                'proposed_by' => $proposer->id,
                'proposed_by_name' => $proposer->name,
                'reason' => $reason,
            ]
        );
    }

    public function approveSalaryChange(SalaryChange $change, ?User $approver, ?string $notes = null): SalaryChange
    {
        if ((string) $change->status !== SalaryChange::STATUS_PENDING) {
            throw new \RuntimeException('Salary change is not pending.');
        }

        DB::beginTransaction();

        try {
            $change->status = SalaryChange::STATUS_APPROVED;
            $change->approved_by = $approver?->id;
            $change->approved_at = now();
            $change->notes = $notes;
            $change->save();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $freshChange = $change->fresh(['employee:id,first_name,last_name', 'approver:id,name']);

        if ($freshChange->proposed_by) {
            $employee = $freshChange->employee;
            $this->notificationService->notifySalaryChangeApprovedToHr(
                (int) $freshChange->proposed_by,
                (int) $freshChange->shop_owner_id,
                [
                    'salary_change_id' => $freshChange->id,
                    'employee_id' => $employee?->id,
                    'employee_name' => trim((string) ($employee?->first_name ?? '') . ' ' . (string) ($employee?->last_name ?? '')),
                    'new_salary' => (float) $freshChange->new_salary,
                    'effective_date' => $freshChange->effective_date?->toDateString(),
                    'approved_by' => $approver?->id,
                    'approved_by_name' => $approver?->name,
                ]
            );
        }

        return $freshChange;
    }

    public function rejectSalaryChange(SalaryChange $change, ?User $rejector, string $notes): SalaryChange
    {
        if ((string) $change->status !== SalaryChange::STATUS_PENDING) {
            throw new \RuntimeException('Salary change is not pending.');
        }

        $change->status = SalaryChange::STATUS_REJECTED;
        $change->rejected_by = $rejector?->id;
        $change->rejected_at = now();
        $change->notes = $notes;
        $change->save();

        return $change->fresh(['employee:id,first_name,last_name', 'rejector:id,name']);
    }
}
