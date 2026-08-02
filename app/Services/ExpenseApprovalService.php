<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\Finance\Expense;
use App\Models\User;
use App\Models\PurchaseOrderReceipt;
use App\Enums\ApprovalStatus;
use App\Enums\NotificationType;

class ExpenseApprovalService
{
    public function __construct(
        private ApprovalService $approvalService,
        private NotificationService $notificationService
    ) {}

    public function submitProcurementExpense(
        PurchaseOrderReceipt $receipt,
        User $creator,
        float $amount
    ): Expense {
        $purchaseOrder = $receipt->purchaseOrder()->with('supplier')->firstOrFail();

        $expense = Expense::firstOrCreate(
            ['procurement_receipt_id' => $receipt->id],
            [
                'reference' => "PROC-RCV-{$receipt->id}",
                'date' => $receipt->received_at->toDateString(),
                'category' => 'Procurement',
                'vendor' => $purchaseOrder->supplier?->name,
                'description' => "Receipt for purchase order {$purchaseOrder->po_number}",
                'amount' => $amount,
                'tax_amount' => 0,
                'status' => 'submitted',
                'shop_id' => $purchaseOrder->shop_owner_id,
                'created_by' => $creator->id,
                'meta' => [
                    'source' => 'procurement_receipt',
                    'purchase_order_id' => $purchaseOrder->id,
                    'po_number' => $purchaseOrder->po_number,
                    'receipt_id' => $receipt->id,
                    'created_by' => $creator->id,
                ],
            ]
        );

        if ($expense->wasRecentlyCreated) {
            $this->notificationService->notifyExpenseSubmitted((int) $purchaseOrder->shop_owner_id, [
                'reference' => $expense->reference,
                'amount' => number_format((float) $expense->amount, 2),
                'category' => $expense->category,
                'expense_id' => $expense->id,
            ]);
        }

        return $expense;
    }

    public function rejectForVoidedReceipt(Expense $expense, PurchaseOrderReceipt $receipt): void
    {
        if ($expense->status === 'submitted') {
            $expense->update([
                'status' => 'rejected',
                'approval_notes' => "System rejected after procurement receipt #{$receipt->id} was voided.",
            ]);
        }

        $approval = $expense->approval()->lockForUpdate()->first();
        if ($approval?->status === ApprovalStatus::PENDING) {
            $approval->update([
                'status' => ApprovalStatus::CANCELLED,
                'comments' => "Cancelled because procurement receipt #{$receipt->id} was voided.",
            ]);
        }
    }

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

        $previousLevel = (int) $approval->current_level;

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

        $this->dispatchExpenseApprovalNotifications(
            expense: $expense,
            approval: $approval,
            approver: $approver,
            previousLevel: $previousLevel,
            result: $result
        );

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

        $this->dispatchExpenseRejectionNotifications($expense, $approval, $comments);

        return $result;
    }

    private function dispatchExpenseApprovalNotifications(
        Expense $expense,
        Approval $approval,
        User $approver,
        int $previousLevel,
        array $result
    ): void {
        $shopOwnerId = (int) $approval->shop_owner_id;
        $expenseData = $this->buildExpenseNotificationData($expense, $approver, null);

        if ($result['is_final'] ?? false) {
            $requesterId = $this->resolveRequesterId($expense, $approval);
            if ($requesterId) {
                $this->notificationService->sendToUser(
                    userId: $requesterId,
                    type: NotificationType::EXPENSE_APPROVAL,
                    title: 'Expense Approved',
                    message: "Your expense {$expenseData['reference']} for ₱{$expenseData['amount']} has been approved.",
                    data: $expenseData,
                    actionUrl: '/erp/finance/expenses',
                    shopId: $shopOwnerId
                );
            }

            return;
        }

        if ($previousLevel === 1) {
            $this->notificationService->sendToShopOwner(
                shopOwnerId: $shopOwnerId,
                type: NotificationType::EXPENSE_REQUEST_PENDING,
                title: 'Expense Awaiting Your Approval',
                message: "Expense {$expenseData['reference']} for ₱{$expenseData['amount']} now requires shop owner approval.",
                data: $expenseData,
                actionUrl: '/shop-owner/expenses',
                priority: 'medium'
            );

            return;
        }

        if (in_array($previousLevel, [2, 3], true)) {
            $title = $previousLevel === 2
                ? 'Expense Returned To Finance'
                : 'Expense Awaiting Final Finance Approval';
            $message = $previousLevel === 2
                ? "Expense {$expenseData['reference']} was approved by shop owner and is back to Finance for review."
                : "Expense {$expenseData['reference']} passed Finance review and needs final Finance approval.";

            $this->notificationService->sendToErpRole(
                roleName: 'Finance',
                shopId: $shopOwnerId,
                type: NotificationType::EXPENSE_REQUEST_PENDING,
                title: $title,
                message: $message,
                data: $expenseData,
                actionUrl: '/erp/finance/expenses',
                priority: 'medium'
            );
        }
    }

    private function dispatchExpenseRejectionNotifications(Expense $expense, Approval $approval, string $comments): void
    {
        $requesterId = $this->resolveRequesterId($expense, $approval);
        if (!$requesterId) {
            return;
        }

        $this->notificationService->notifyExpenseRejected(
            userId: $requesterId,
            shopId: (int) $approval->shop_owner_id,
            expenseData: $this->buildExpenseNotificationData($expense, null, $comments)
        );
    }

    private function buildExpenseNotificationData(Expense $expense, ?User $actor = null, ?string $reason = null): array
    {
        $meta = is_array($expense->meta) ? $expense->meta : [];

        return [
            'expense_id' => $expense->id,
            'reference' => $expense->reference,
            'amount' => number_format((float) $expense->amount, 2),
            'category' => $expense->category,
            'vendor' => $expense->vendor,
            'submitted_by' => $actor?->name ?? ($meta['submitted_by_name'] ?? 'Staff'),
            'rejection_reason' => $reason,
        ];
    }

    private function resolveRequesterId(Expense $expense, Approval $approval): ?int
    {
        $meta = is_array($expense->meta) ? $expense->meta : [];

        $candidates = [
            $expense->created_by ?? null,
            $meta['created_by'] ?? null,
            $approval->requested_by ?? null,
        ];

        foreach ($candidates as $candidate) {
            $userId = (int) $candidate;
            if ($userId > 0) {
                return $userId;
            }
        }

        return null;
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
