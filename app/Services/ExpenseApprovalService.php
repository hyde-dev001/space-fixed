<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\Finance\Expense;
use App\Models\User;
use App\Enums\ApprovalStatus;

class ExpenseApprovalService
{
    public function __construct(
        private ApprovalService $approvalService,
        private NotificationService $notificationService
    ) {}

    /**
     * Create a 4-step approval workflow for an expense
     * Finance (1) → Shop Owner (2) → Finance (3) → Finance Final (4)
     */
    public function createExpenseApproval(Expense $expense, User $shopOwner): Approval
    {
        $approvalRoles = [
            '1' => 'finance',           // Finance reviews first
            '2' => 'shop_owner',        // Shop owner approves
            '3' => 'finance',           // Finance reviews again
            '4' => 'finance_final'      // Finance Final sign-off
        ];

        // Create polymorphic approval record
        $approval = $this->approvalService->createApproval(
            approvable: $expense,
            approvalRoles: $approvalRoles,
            requestedBy: $expense->created_by ? User::find($expense->created_by) : $shopOwner,
            shopOwner: $shopOwner,
            reference: $expense->reference,
            description: "Expense: {$expense->category} - {$expense->vendor}",
            amount: (float)$expense->amount,
            metadata: [
                'expense_id' => $expense->id,
                'category' => $expense->category,
                'vendor' => $expense->vendor,
                'date' => $expense->date,
                'receipt_path' => $expense->receipt_path
            ]
        );

        // Link the expense to this approval
        $expense->update([
            'approval_id' => $approval->id,
            'current_approval_level' => 1,
            'status' => 'submitted'  // Keep backwards compatible status
        ]);

        return $approval;
    }

    /**
     * Approve an expense at current approval level
     */
    public function approveExpense(Expense $expense, User $approver, ?string $comments = null): array
    {
        if (!$expense->approval_id) {
            return [
                'success' => false,
                'message' => 'No approval workflow found for this expense'
            ];
        }

        $approval = Approval::find($expense->approval_id);
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

        // Keep intermediate states compatible with existing expense enum values
        $statusMapping = [
            1 => 'submitted',
            2 => 'submitted',
            3 => 'submitted',
            4 => 'approved'
        ];

        if ($result['is_final'] ?? false) {
            $expense->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'approval_notes' => $comments,
                'current_approval_level' => $approval->current_level
            ]);
        } else {
            $nextLevel = $approval->current_level;
            $expense->update([
                'current_approval_level' => $nextLevel,
                'status' => $statusMapping[$nextLevel] ?? 'submitted'
            ]);
        }

        return $result;
    }

    /**
     * Reject an expense at current approval level
     */
    public function rejectExpense(Expense $expense, User $rejector, string $comments = ''): array
    {
        if (!$expense->approval_id) {
            return [
                'success' => false,
                'message' => 'No approval workflow found for this expense'
            ];
        }

        $approval = Approval::find($expense->approval_id);
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

        // Update expense to rejected status
        $expense->update([
            'status' => 'rejected',
            'approval_notes' => $comments,
            'current_approval_level' => $approval->current_level
        ]);

        // Send rejection notification to the requester
        $shopOwnerId = $approval->shop_owner_id;
        if ($expense->created_by) {
            $this->notificationService->notifyExpenseRejected($expense->created_by, $shopOwnerId, [
                'reference' => $expense->reference,
                'amount' => (float)$expense->amount,
                'category' => $expense->category,
                'rejection_reason' => $comments,
            ]);
        }

        return $result;
    }

    /**
     * Get pending expenses for a specific user/role
     */
    public function getPendingExpensesForUser(User $shopOwner, User $approver): \Illuminate\Database\Eloquent\Collection
    {
        // Get all pending Approval records for Expense type
        $approvals = Approval::where('shop_owner_id', $shopOwner->id)
            ->where('approvable_type', Expense::class)
            ->where('status', ApprovalStatus::PENDING)
            ->get();

        // Filter by user's ability to approve
        return $approvals->filter(function ($approval) use ($approver) {
            return $approval->canApprove($approver);
        })->values();
    }

    /**
     * Get the next approver information for an expense
     */
    public function getNextApproverInfo(Approval $approval): ?array
    {
        $nextLevel = $approval->current_level + 1;
        if ($nextLevel > $approval->total_levels) {
            return null;  // No more approvers
        }

        $nextRole = $approval->approval_roles[$nextLevel] ?? null;
        
        return [
            'level' => $nextLevel,
            'role' => $nextRole,
            'role_display' => $this->getRoleDisplay($nextRole)
        ];
    }

    /**
     * Format role name for display
     */
    private function getRoleDisplay(?string $role): string
    {
        return match ($role) {
            'finance' => 'Finance Team',
            'shop_owner' => 'Shop Owner',
            'finance_final' => 'Finance Manager (Final Approval)',
            default => 'Unknown'
        };
    }
}
