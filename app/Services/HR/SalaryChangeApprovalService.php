<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\HR\SalaryChange;
use App\Models\ShopOwner;
use App\Models\User;
use App\Enums\NotificationType;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

class SalaryChangeApprovalService
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function notifySalaryChangeSubmitted(SalaryChange $change, Employee $employee, User $proposer, string $reason): void
    {
        if ($change->requires_owner_approval === false) {
            return;
        }

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

    public function approveSalaryChange(
        SalaryChange $change,
        ?User $approver,
        ?string $notes = null,
        ?ShopOwner $shopOwnerApprover = null
    ): SalaryChange
    {
        $savedChange = DB::transaction(function () use ($change, $approver, $notes, $shopOwnerApprover): SalaryChange {
            $lockedChange = SalaryChange::query()
                ->whereKey($change->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedChange->status !== SalaryChange::STATUS_PENDING) {
                throw new \RuntimeException('Salary change is not pending.');
            }

            if ($shopOwnerApprover && (int) $shopOwnerApprover->id !== (int) $lockedChange->shop_owner_id) {
                throw new \RuntimeException('Shop owner approver does not belong to this salary change.');
            }

            $lockedChange->status = SalaryChange::STATUS_APPROVED;
            $lockedChange->approved_by = $approver?->id;
            $lockedChange->approved_by_shop_owner_id = $shopOwnerApprover?->id;
            $lockedChange->approved_at = now();
            $lockedChange->notes = $notes;
            $lockedChange->save();

            return $lockedChange;
        }, 3);

        $freshChange = $savedChange->fresh([
            'employee:id,first_name,last_name',
            'approver:id,name',
            'shopOwnerApprover:id,first_name,last_name,business_name',
        ]);

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
                    'approved_by_shop_owner_id' => $shopOwnerApprover?->id,
                    'approved_by_name' => $approver?->name ?? $shopOwnerApprover?->name,
                ]
            );
        }

        return $freshChange;
    }

    public function rejectSalaryChange(
        SalaryChange $change,
        ?User $rejector,
        string $notes,
        ?ShopOwner $shopOwnerRejector = null
    ): SalaryChange
    {
        $savedChange = DB::transaction(function () use ($change, $rejector, $notes, $shopOwnerRejector): SalaryChange {
            $lockedChange = SalaryChange::query()
                ->whereKey($change->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedChange->status !== SalaryChange::STATUS_PENDING) {
                throw new \RuntimeException('Salary change is not pending.');
            }

            if ($shopOwnerRejector && (int) $shopOwnerRejector->id !== (int) $lockedChange->shop_owner_id) {
                throw new \RuntimeException('Shop owner rejector does not belong to this salary change.');
            }

            $lockedChange->status = SalaryChange::STATUS_REJECTED;
            $lockedChange->rejected_by = $rejector?->id;
            $lockedChange->rejected_by_shop_owner_id = $shopOwnerRejector?->id;
            $lockedChange->rejected_at = now();
            $lockedChange->notes = $notes;
            $lockedChange->save();

            return $lockedChange;
        }, 3);

        if ($savedChange->proposed_by) {
            $this->notificationService->sendToUser(
                userId: (int) $savedChange->proposed_by,
                type: NotificationType::SALARY_CHANGE_APPROVED,
                title: 'Salary Change Rejected',
                message: 'Your salary change request was rejected. Please review remarks.',
                data: [
                    'salary_change_id' => (int) $savedChange->id,
                    'reason' => $notes,
                ],
                actionUrl: '/erp/hr?section=salary-changes',
                shopId: (int) $savedChange->shop_owner_id,
                priority: 'high'
            );
        }

        return $savedChange->fresh([
            'employee:id,first_name,last_name',
            'rejector:id,name',
            'shopOwnerRejector:id,first_name,last_name,business_name',
        ]);
    }
}
