<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\HR\Payroll;
use App\Models\User;
use App\Enums\ApprovalStatus;
use App\Enums\NotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayslipApprovalService
{
    public function __construct(
        private ApprovalService $approvalService,
        private NotificationService $notificationService,
        private ShopOwnerApprovalPolicyService $approvalPolicyService,
        private ShopOwnerActorUserResolver $shopOwnerActorUserResolver,
    ) {}

    /**
     * Create the snapshotted approval workflow for a payslip.
     */
    public function createPayslipApproval(Payroll $payslip, User $shopOwner, User $generatedBy): Approval
    {
        $result = DB::transaction(function () use ($payslip, $generatedBy, $shopOwner): array {
            $lockedPayslip = Payroll::query()
                ->lockForUpdate()
                ->findOrFail($payslip->getKey());

            if ($lockedPayslip->approval_id && $lockedPayslip->approval_workflow_version === 'v4_multi_level') {
                return [
                    'approval' => Approval::query()->findOrFail($lockedPayslip->approval_id),
                    'created' => false,
                ];
            }

            $approvalRoles = $this->approvalPolicyService->requiresOwnerApprovalForPayslip((int) $lockedPayslip->shop_owner_id)
                ? [
                    '1' => 'finance',
                    '2' => 'shop_owner',
                    '3' => 'finance',
                    '4' => 'finance_final',
                ]
                : [
                    '1' => 'finance',
                    '2' => 'finance',
                    '3' => 'finance_final',
                ];

            $approval = $this->approvalService->createApproval(
                approvable: $lockedPayslip,
                approvalRoles: $approvalRoles,
                requestedBy: $generatedBy,
                shopOwner: $shopOwner,
                reference: "PAYROLL-{$lockedPayslip->id}",
                description: "Payroll: {$lockedPayslip->employee->first_name} {$lockedPayslip->employee->last_name} ({$lockedPayslip->payroll_period})",
                amount: (float) $lockedPayslip->gross_salary,
                metadata: [
                    'payroll_id' => $lockedPayslip->id,
                    'employee_id' => $lockedPayslip->employee_id,
                    'pay_period' => $lockedPayslip->payroll_period,
                    'gross_salary' => (float) $lockedPayslip->gross_salary,
                    'net_salary' => (float) $lockedPayslip->net_salary,
                    'generated_by' => $generatedBy->id,
                ]
            );

            $lockedPayslip->update([
                'approval_id' => $approval->id,
                'current_approval_level' => 1,
                'approval_workflow_version' => 'v4_multi_level'
            ]);

            return [
                'approval' => $approval,
                'created' => true,
            ];
        });

        // Payroll generation commits before this service is called. Keeping the
        // notification after the approval transaction prevents a failed
        // approval write from leaving an orphaned owner alert. Only the
        // transaction that created the approval sends the initial alert, so a
        // retry or concurrent callback cannot duplicate it.
        if ($result['created']) {
            try {
                $this->notifyPayslipApprovalRequested($payslip->fresh(), $generatedBy);
            } catch (\Throwable $exception) {
                Log::error('Payslip approval notification failed.', [
                    'payroll_id' => $payslip->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $result['approval'];
    }

    /**
     * Attach the canonical approval workflow after an HR payroll is generated.
     */
    public function createGeneratedPayrollApproval(Payroll $payslip, User $generatedBy): ?Approval
    {
        $shopOwner = \App\Models\ShopOwner::query()->find($payslip->shop_owner_id);
        if (! $shopOwner) {
            return null;
        }

        $shopOwnerUserId = $this->shopOwnerActorUserResolver->ensure($shopOwner);
        $shopOwnerUser = $shopOwnerUserId ? User::query()->find($shopOwnerUserId) : null;

        return $shopOwnerUser
            ? $this->createPayslipApproval($payslip, $shopOwnerUser, $generatedBy)
            : null;
    }

    /**
     * Approve a payslip at current approval level
     */
    public function approvePayslip(Payroll $payslip, User $approver, ?string $comments = null): array
    {
        if (!$payslip->approval_id) {
            return [
                'success' => false,
                'message' => 'No approval workflow found for this payslip'
            ];
        }

        $result = DB::transaction(function () use ($payslip, $approver, $comments): array {
            $lockedPayslip = Payroll::query()->lockForUpdate()->find($payslip->id);
            $approval = $lockedPayslip?->approval_id
                ? Approval::query()->lockForUpdate()->find($lockedPayslip->approval_id)
                : null;

            if (! $approval) {
                return [
                    'success' => false,
                    'message' => 'Approval record not found',
                ];
            }

            $result = $this->approvalService->approve($approval, $approver, $comments);
            if (! $result['success']) {
                return $result;
            }

            if ($result['is_final'] ?? false) {
                $lockedPayslip->update([
                    'status' => 'approved',
                    'approval_status' => 'approved',
                    'final_approved_by' => $approver->id,
                    'final_approved_at' => now(),
                    'final_approval_notes' => $comments,
                    'current_approval_level' => $approval->current_level,
                ]);
            } else {
                $nextLevel = $approval->current_level;
                $lockedPayslip->update([
                    'current_approval_level' => $nextLevel,
                    'status' => 'pending',
                    'approval_status' => 'pending',
                ]);

                if ($nextLevel === 2) {
                    $lockedPayslip->update([
                        'approved_by' => $approver->id,
                        'approved_at' => now(),
                        'approval_notes' => $comments,
                    ]);
                } elseif ($nextLevel === 3) {
                    $lockedPayslip->update(['final_approved_by' => null]);
                } elseif ($nextLevel === 4) {
                    $lockedPayslip->update(['payout_reference' => null]);
                }
            }

            return $result;
        });

        if (! $result['success']) {
            return $result;
        }

        $payslip->refresh();
        $approval = Approval::query()->findOrFail($payslip->approval_id);

        $this->dispatchPayslipApprovalNotifications(
            payslip: $payslip,
            approval: $approval,
            approver: $approver,
            comments: $comments,
            result: $result
        );

        return $result;
    }

    /**
     * Reject a payslip at current approval level
     */
    public function rejectPayslip(Payroll $payslip, User $rejector, string $comments = ''): array
    {
        if (!$payslip->approval_id) {
            return [
                'success' => false,
                'message' => 'No approval workflow found for this payslip'
            ];
        }

        $result = DB::transaction(function () use ($payslip, $rejector, $comments): array {
            $lockedPayslip = Payroll::query()->lockForUpdate()->find($payslip->id);
            $approval = $lockedPayslip?->approval_id
                ? Approval::query()->lockForUpdate()->find($lockedPayslip->approval_id)
                : null;

            if (! $approval) {
                return [
                    'success' => false,
                    'message' => 'Approval record not found',
                ];
            }

            $result = $this->approvalService->reject($approval, $rejector, $comments);
            if (! $result['success']) {
                return $result;
            }

            $lockedPayslip->update([
                'status' => 'pending',
                'approval_status' => 'rejected',
                'approved_by' => $rejector->id,
                'approved_at' => now(),
                'approval_notes' => $comments,
                'current_approval_level' => $approval->current_level,
            ]);

            return $result;
        });

        if (! $result['success']) {
            return $result;
        }

        $payslip->refresh();
        $approval = Approval::query()->findOrFail($payslip->approval_id);

        $this->dispatchPayslipRejectionNotifications($payslip, $approval, $comments);

        return $result;
    }

    public function notifyPayslipApprovalRequested(Payroll $payslip, User $generatedBy): void
    {
        $payload = $this->buildPayslipNotificationData($payslip, $generatedBy, null);

        $this->notificationService->sendToErpRole(
            roleName: 'Finance',
            shopId: (int) $payslip->shop_owner_id,
            type: NotificationType::PAYROLL_GENERATED,
            title: 'Payslip Approval Required',
            message: "Payroll {$payload['period']} for {$payload['employee_name']} needs Finance review.",
            data: $payload,
            actionUrl: $this->financePayslipActionUrl($payslip->id),
            priority: 'medium',
            groupKey: "payslip-approval-{$payslip->id}-finance-level-1",
            requiresAction: true,
            requiredPermission: 'access-payslip-approval',
        );
    }

    private function dispatchPayslipApprovalNotifications(
        Payroll $payslip,
        Approval $approval,
        User $approver,
        ?string $comments,
        array $result
    ): void {
        $payload = $this->buildPayslipNotificationData($payslip, $approver, $comments);
        $shopOwnerId = (int) $payslip->shop_owner_id;

        if ($result['is_final'] ?? false) {
            $generatedByUserId = (int) ($payslip->generated_by ?? 0);
            if ($generatedByUserId > 0) {
                $this->notificationService->sendToUser(
                    userId: $generatedByUserId,
                    type: NotificationType::PAYROLL_GENERATED,
                    title: 'Payslip Fully Approved',
                    message: "Payroll {$payload['period']} for {$payload['employee_name']} completed all approval levels.",
                    data: $payload,
                    actionUrl: $this->hrPayslipActionUrl($payslip->id),
                    shopId: $shopOwnerId
                );
            }

            $employeeUserId = (int) ($payslip->employee?->user?->id ?? 0);
            if ($employeeUserId > 0) {
                $this->notificationService->notifyPayslipReady($employeeUserId, $shopOwnerId, [
                    'payroll_id' => $payslip->id,
                    'period' => $payslip->payroll_period,
                    'net_salary' => number_format((float) $payslip->net_salary, 2),
                ]);
            }

            return;
        }

        $nextRole = $approval->current_approver_role;

        if ($nextRole === 'shop_owner') {
            $this->notificationService->sendToShopOwner(
                shopOwnerId: $shopOwnerId,
                type: NotificationType::PAYROLL_GENERATED,
                title: 'Payslip Awaiting Shop Owner Approval',
                message: "Payroll {$payload['period']} for {$payload['employee_name']} now requires your approval.",
                data: $payload,
                actionUrl: $this->notificationService->ownerApprovalActionUrl('payslip', $payslip->id),
                priority: 'medium',
                groupKey: "payslip-approval-{$payslip->id}-shop_owner-level-{$approval->current_level}",
                requiresAction: true,
            );

            return;
        }

        if (in_array($nextRole, ['finance', 'finance_final'], true)) {
            $title = $nextRole === 'finance_final'
                ? 'Payslip Awaiting Final Finance Approval'
                : 'Payslip Returned To Finance';
            $message = $nextRole === 'finance_final'
                ? "Payroll {$payload['period']} for {$payload['employee_name']} now needs final Finance approval."
                : "Payroll {$payload['period']} for {$payload['employee_name']} now requires another Finance review.";

            $this->notificationService->sendToErpRole(
                roleName: 'Finance',
                shopId: $shopOwnerId,
                type: NotificationType::PAYROLL_GENERATED,
                title: $title,
                message: $message,
                data: $payload,
                actionUrl: $this->financePayslipActionUrl($payslip->id),
                priority: 'medium',
                groupKey: "payslip-approval-{$payslip->id}-{$nextRole}-level-{$approval->current_level}",
                requiresAction: true,
                requiredPermission: 'access-payslip-approval',
            );
        }
    }

    private function dispatchPayslipRejectionNotifications(Payroll $payslip, Approval $approval, string $comments): void
    {
        $payload = $this->buildPayslipNotificationData($payslip, null, $comments);
        $shopOwnerId = (int) $approval->shop_owner_id;

        $generatedByUserId = (int) ($payslip->generated_by ?? 0);
        if ($generatedByUserId > 0) {
            $this->notificationService->sendToUser(
                userId: $generatedByUserId,
                type: NotificationType::PAYSLIP_REJECTED,
                title: 'Payslip Rejected In Approval Workflow',
                message: "Payroll {$payload['period']} for {$payload['employee_name']} was rejected. Reason: {$comments}",
                data: $payload,
                actionUrl: $this->hrPayslipActionUrl($payslip->id),
                shopId: $shopOwnerId
            );
        }

        $employeeUserId = (int) ($payslip->employee?->user?->id ?? 0);
        if ($employeeUserId > 0) {
            $this->notificationService->notifyPayslipRejected($employeeUserId, $shopOwnerId, [
                'period' => $payslip->payroll_period,
                'rejection_reason' => $comments,
            ]);
        }
    }

    private function financePayslipActionUrl(int|string $payrollId): string
    {
        return '/finance?section=payslip-approvals&payroll=' . urlencode((string) $payrollId);
    }

    private function hrPayslipActionUrl(int|string $payrollId): string
    {
        return '/erp/hr?section=payroll-view&payroll=' . urlencode((string) $payrollId);
    }

    private function buildPayslipNotificationData(Payroll $payslip, ?User $actor = null, ?string $reason = null): array
    {
        $employeeName = trim((string) ($payslip->employee?->first_name ?? '') . ' ' . (string) ($payslip->employee?->last_name ?? ''));

        return [
            'payroll_id' => $payslip->id,
            'employee_id' => $payslip->employee_id,
            'employee_name' => $employeeName !== '' ? $employeeName : 'Employee',
            'period' => $payslip->payroll_period,
            'gross_salary' => number_format((float) $payslip->gross_salary, 2),
            'net_salary' => number_format((float) $payslip->net_salary, 2),
            'approval_level' => $payslip->current_approval_level,
            'status' => $payslip->status,
            'acted_by' => $actor?->name,
            'rejection_reason' => $reason,
        ];
    }

    /**
     * Get pending payslips for a user based on their role in the approval chain
     */
    public function getPendingPayslipsForUser(User $shopOwner, User $approver): \Illuminate\Database\Eloquent\Collection
    {
        // Get all pending Approval records for Payroll type
        $approvals = Approval::where('shop_owner_id', $shopOwner->id)
            ->where('approvable_type', Payroll::class)
            ->where('status', ApprovalStatus::PENDING)
            ->get();

        // Filter by user's ability to approve
        return $approvals->filter(function (Approval $approval) use ($approver) {
            return $approval->canApprove($approver);
        })->map(function (Approval $approval) {
            // Load the associated payroll
            return $approval->approvable()->first();
        })->filter()
        ->values();
    }

    /**
     * Get approval summary for a payslip
     */
    public function getApprovalSummary(Approval $approval): array
    {
        $payslip = $approval->approvable;

        $next_info = $approval->current_level < $approval->total_levels
            ? [
                'level' => $approval->current_level + 1,
                'role' => $approval->approval_roles[$approval->current_level + 1] ?? 'Unknown',
            ]
            : null;

        return [
            'approval_id' => $approval->id,
            'payslip_id' => $payslip->id,
            'employee_name' => "{$payslip->employee->first_name} {$payslip->employee->last_name}",
            'pay_period' => $payslip->payroll_period,
            'gross_salary' => (float)$payslip->gross_salary,
            'net_salary' => (float)$payslip->net_salary,
            'current_level' => $approval->current_level,
            'total_levels' => $approval->total_levels,
            'current_approver_role' => $approval->current_approver_role,
            'next_approver' => $next_info,
            'status' => $approval->status,
            'approval_progress' => "{$approval->current_level}/{$approval->total_levels}",
            'level_history' => $this->formatLevelHistory($approval)
        ];
    }

    /**
     * Format approval level history for display
     */
    private function formatLevelHistory(Approval $approval): array
    {
        $history = [];
        
        if (!$approval->level_reviewers) {
            return $history;
        }

        foreach ($approval->level_reviewers as $level => $reviewer_data) {
            $history[] = [
                'level' => (int)$level,
                'role' => $approval->approval_roles[$level] ?? 'Unknown',
                'reviewer_id' => $reviewer_data['user_id'] ?? null,
                'action' => $reviewer_data['action'] ?? null,
                'comments' => $reviewer_data['comments'] ?? null,
                'reviewed_at' => $reviewer_data['reviewed_at'] ?? null
            ];
        }

        return $history;
    }

    /**
     * Migrate existing payslips from the legacy workflow
     * Useful for bulk migration of in-flight approvals
     */
    public function migrateToNewWorkflow(Payroll $payslip, User $shopOwner): bool
    {
        // Skip if already migrated or has new workflow
        if ($payslip->approval_workflow_version === 'v4_multi_level' || $payslip->approval_id) {
            return false;
        }

        try {
            $generatedBy = User::find($payslip->generated_by ?? $shopOwner->id) ?? $shopOwner;
            
            // Create a new snapshotted approval. Existing legacy records keep
            // their legacy controller path until this explicit migration runs.
            $this->createPayslipApproval($payslip, $shopOwner, $generatedBy);

            // If Finance already checked the legacy record, fast-track to the
            // second stored stage, whatever role that stage requires.
            if ($payslip->approval_status === 'approved' && $payslip->approved_by) {
                $approval = $payslip->approval()->first();
                if ($approval) {
                    $nextLevel = 2;
                    $approval->update([
                        'current_level' => $nextLevel,
                        'current_approver_role' => $approval->getApproverRoleForLevel($nextLevel),
                    ]);
                    $payslip->update(['current_approval_level' => $nextLevel]);
                }
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Payslip workflow migration failed', [
                'payroll_id' => $payslip->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
