<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\HR\Payroll;
use App\Models\User;
use App\Enums\ApprovalStatus;

class PayslipApprovalService
{
    public function __construct(
        private ApprovalService $approvalService,
        private NotificationService $notificationService
    ) {}

    /**
     * Create a 4-step approval workflow for a payslip
     * Finance (1) → Shop Owner (2) → Finance (3) → Finance Final (4)
     */
    public function createPayslipApproval(Payroll $payslip, User $shopOwner, User $generatedBy): Approval
    {
        $approvalRoles = [
            '1' => 'finance',           // Finance checks first
            '2' => 'shop_owner',        // Shop owner reviews
            '3' => 'finance',           // Finance double-checks
            '4' => 'finance_final'      // Finance Manager final approval
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

        // Keep intermediate statuses compatible with current payroll status enum
        $statusMapping = [
            1 => 'pending',
            2 => 'pending',
            3 => 'pending',
            4 => 'approved'
        ];

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
                'status' => $statusMapping[$nextLevel] ?? 'pending',
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
                // After Shop Owner level 2 - prepare for Finance secondary
                $payslip->update([
                    'final_approved_by' => null,  // Clear final, waiting for level 3
                ]);
            } elseif ($nextLevel === 4) {
                // After Finance level 3 - prepare for Finance Manager final
                $payslip->update([
                    'payout_reference' => null,  // Clear previous payout state
                ]);
            }
        }

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

        // Send rejection notification to the employee
        $shopOwnerId = $approval->shop_owner_id;
        if ($payslip->employee_id) {
            $this->notificationService->notifyPayslipRejected($payslip->employee_id, $shopOwnerId, [
                'period' => $payslip->period,
                'rejection_reason' => $comments,
            ]);
        }

        return $result;
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
        return $approvals->filter(function ($approval) use ($approver) {
            return $approval->canApprove($approver);
        })->map(function ($approval) {
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
     * Migrate existing payslips from 2-step to 4-step workflow
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
            
            // Create new 4-step approval
            $this->createPayslipApproval($payslip, $shopOwner, $generatedBy);

            // If payslip was already partially approved in old workflow, 
            // fast-track to appropriate level
            if ($payslip->approval_status === 'approved' && $payslip->approved_by) {
                // Finance already checked - create 2nd level approval for shop owner
                $approval = $payslip->approval()->first();
                if ($approval) {
                    $approval->update([
                        'current_level' => 2,
                        'current_approver_role' => 'shop_owner'
                    ]);
                }
            }

            return true;
        } catch (\Exception $e) {
            \Log::error('Payslip workflow migration failed', [
                'payroll_id' => $payslip->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
