<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\HR\Payroll;
use App\Models\User;
use App\Enums\ApprovalStatus;
use App\Enums\NotificationType;
use Illuminate\Support\Facades\Log;

class PayslipApprovalService
{
    public function __construct(
        private ApprovalService $approvalService,
        private NotificationService $notificationService,
        private ShopOwnerApprovalPolicyService $approvalPolicyService
    ) {}

    /**
     * Create the snapshotted approval workflow for a payslip.
     */
    public function createPayslipApproval(Payroll $payslip, User $shopOwner, User $generatedBy): Approval
    {
        $approvalRoles = $this->approvalPolicyService->requiresOwnerApprovalForPayslip((int) $payslip->shop_owner_id)
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

        // Create polymorphic approval record
        $approval = $this->approvalService->createApproval(
            approvable: $payslip,
            approvalRoles: $approvalRoles,
            requestedBy: $generatedBy,
            shopOwner: $shopOwner,
            reference: "PAYROLL-{$payslip->id}",
            description: "Payroll: {$payslip->employee->first_name} {$payslip->employee->last_name} ({$payslip->payroll_period})",
            amount: (float)$payslip->gross_salary,
            metadata: [
                'payroll_id' => $payslip->id,
                'employee_id' => $payslip->employee_id,
                'pay_period' => $payslip->payroll_period,
                'gross_salary' => (float)$payslip->gross_salary,
                'net_salary' => (float)$payslip->net_salary,
                'generated_by' => $generatedBy->id
            ]
        );

        // Link the payslip to this approval
        $payslip->update([
            'approval_id' => $approval->id,
            'current_approval_level' => 1,
            'approval_workflow_version' => 'v4_multi_level'
        ]);

        $this->notifyPayslipApprovalRequested($payslip->fresh(), $generatedBy);

        return $approval;
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

        $approval = Approval::find($payslip->approval_id);
        if (!$approval) {
            return [
                'success' => false,
                'message' => 'Approval record not found'
            ];
        }

        // Use ApprovalService to transition
        $result = $this->approvalService->approve($approval, $approver, $comments);

        if (!$result['success']) {
            return $result;
        }

        if ($result['is_final'] ?? false) {
            // Final approval - payslip is ready for disbursement
            $payslip->update([
                'status' => 'approved',
                'approval_status' => 'approved',
                'final_approved_by' => $approver->id,
                'final_approved_at' => now(),
                'final_approval_notes' => $comments,
                'current_approval_level' => $approval->current_level
            ]);
        } else {
            // Intermediate approval - move to next level
            $nextLevel = $approval->current_level;
            $payslip->update([
                'current_approval_level' => $nextLevel,
                'status' => 'pending',
                'approval_status' => 'pending'
            ]);

            // Track intermediate approval
            if ($nextLevel === 2) {
                // After Finance level 1
                $payslip->update([
                    'approved_by' => $approver->id,
                    'approved_at' => now(),
                    'approval_notes' => $comments
                ]);
            } elseif ($nextLevel === 3) {
                // After the second decision - prepare for any remaining stage
                $payslip->update([
                    'final_approved_by' => null,  // Clear final, waiting for level 3
                ]);
            } elseif ($nextLevel === 4) {
                // After the secondary Finance decision - prepare for final Finance
                $payslip->update([
                    'payout_reference' => null,  // Clear previous payout state
                ]);
            }
        }

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

        $approval = Approval::find($payslip->approval_id);
        if (!$approval) {
            return [
                'success' => false,
                'message' => 'Approval record not found'
            ];
        }

        // Use ApprovalService to reject
        $result = $this->approvalService->reject($approval, $rejector, $comments);

        if (!$result['success']) {
            return $result;
        }

        // Update payslip to rejected status
        $payslip->update([
            'status' => 'pending',  // Return to pending for HR to correct
            'approval_status' => 'rejected',
            'approved_by' => $rejector->id,
            'approved_at' => now(),
            'approval_notes' => $comments,
            'current_approval_level' => $approval->current_level
        ]);

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
            requiresAction: true,
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
                requiresAction: true,
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
