<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\Finance\Expense;
use App\Models\User;
use App\Models\PurchaseOrderReceipt;
use App\Enums\ApprovalStatus;
use App\Enums\NotificationType;
use Illuminate\Support\Facades\DB;

class ExpenseApprovalService
{
    public function __construct(
        private ApprovalService $approvalService,
        private NotificationService $notificationService,
        private ShopOwnerApprovalPolicyService $approvalPolicyService
    ) {}

    public function submitProcurementExpense(
        PurchaseOrderReceipt $receipt,
        User $creator,
        float $amount
    ): Expense {
        $purchaseOrder = $receipt->purchaseOrder()->with('supplier')->firstOrFail();
        $dueDate = $this->deriveSupplierDueDate($purchaseOrder->payment_terms, $receipt->received_at);

        $expense = Expense::firstOrCreate(
            ['procurement_receipt_id' => $receipt->id],
            [
                'reference' => "PROC-RCV-{$receipt->id}",
                'date' => $receipt->received_at->toDateString(),
                'due_date' => $dueDate,
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
            try {
                $this->notificationService->notifyExpenseSubmitted((int) $purchaseOrder->shop_owner_id, [
                    'reference' => $expense->reference,
                    'amount' => number_format((float) $expense->amount, 2),
                    'category' => $expense->category,
                    'expense_id' => $expense->id,
                    'source' => 'procurement_receipt',
                ]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $expense->fresh();
    }

    private function deriveSupplierDueDate(?string $paymentTerms, $receivedAt): ?string
    {
        $terms = trim((string) $paymentTerms);
        if ($terms === '' || ! preg_match('/^Net\s+([1-9]\d{0,2})$/i', $terms, $matches)) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($receivedAt)
            ->addDays((int) $matches[1])
            ->toDateString();
    }

    /**
     * Convert legacy procurement receipt workflows to Finance-only review.
     */
    public function clearProcurementApprovalWorkflow(Expense $expense): void
    {
        if (!$expense->procurement_receipt_id || !$expense->approval_id) {
            return;
        }

        DB::transaction(function () use ($expense): void {
            $approval = Approval::query()
                ->lockForUpdate()
                ->find($expense->approval_id);

            if ($approval?->status === ApprovalStatus::PENDING) {
                $approval->update([
                    'status' => ApprovalStatus::CANCELLED,
                    'comments' => 'Cancelled because procurement receipt expenses are reviewed by Finance only.',
                ]);
            }

            $expense->update([
                'approval_id' => null,
                'current_approval_level' => null,
            ]);
        });
    }

    public function rejectForVoidedReceipt(Expense $expense, PurchaseOrderReceipt $receipt): void
    {
        if ($expense->status === 'submitted') {
            $expense->update([
                'status' => 'rejected',
                'approval_notes' => "System rejected after procurement receipt #{$receipt->id} was voided.",
            ]);
        }

        $approval = $expense->approval_id
            ? Approval::query()->lockForUpdate()->find($expense->approval_id)
            : $expense->approval()->lockForUpdate()->first();
        if ($approval?->status === ApprovalStatus::PENDING) {
            $approval->update([
                'status' => ApprovalStatus::CANCELLED,
                'comments' => "Cancelled because procurement receipt #{$receipt->id} was voided.",
            ]);
        }
    }

    /**
     * Create the smallest manual approval workflow for an expense.
     * The binary owner policy is frozen in Approval::approval_roles; later
     * actions use that stored role map rather than live settings.
     */
    public function createExpenseApproval(Expense $expense, User $shopOwner): Approval
    {
        $source = is_array($expense->meta) ? (string) ($expense->meta['source'] ?? '') : '';
        if ($expense->procurement_receipt_id || in_array($source, ['procurement_receipt', 'payroll'], true)) {
            throw new \LogicException('Operational expenses are not routed through manual approval.');
        }

        $approvalRoles = $this->approvalPolicyService->requiresOwnerApprovalForExpense(
            (int) $shopOwner->id,
            (float) $expense->amount
        )
            ? ['1' => 'finance', '2' => 'shop_owner']
            : ['1' => 'finance'];

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
    public function approveExpense(Expense $expense, object $approver, ?string $comments = null): array
    {
        $result = DB::transaction(function () use ($expense, $approver, $comments): array {
            $lockedExpense = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->first();
            if (! $lockedExpense?->approval_id) {
                return ['success' => false, 'code' => 'APPROVAL_STATE_CONFLICT', 'message' => 'No approval workflow found for this expense.'];
            }

            $approval = Approval::query()->whereKey($lockedExpense->approval_id)->lockForUpdate()->first();
            if (! $approval) {
                return ['success' => false, 'code' => 'APPROVAL_STATE_CONFLICT', 'message' => 'Approval record not found.'];
            }
            if ($approval->status !== ApprovalStatus::PENDING || $lockedExpense->status !== 'submitted') {
                return ['success' => false, 'code' => 'APPROVAL_STATE_CONFLICT', 'message' => 'This expense has already advanced or is no longer pending.', 'approval' => $approval];
            }

            $previousLevel = (int) $approval->current_level;
            $transition = $this->approvalService->approve($approval, $approver, $comments);
            if (! ($transition['success'] ?? false)) {
                return [...$transition, 'code' => 'APPROVAL_STATE_CONFLICT', 'approval' => $approval];
            }

            $isFinal = (bool) ($transition['is_final'] ?? false);
            $lockedExpense->update([
                'status' => $isFinal ? 'approved' : 'submitted',
                'approved_by' => $isFinal ? ($approver->id ?? null) : $lockedExpense->approved_by,
                'approved_at' => $isFinal ? now() : $lockedExpense->approved_at,
                'approval_notes' => $comments,
                'current_approval_level' => $approval->current_level,
            ]);

            return [
                ...$transition,
                'expense' => $lockedExpense->fresh(),
                'approval' => $approval->fresh(),
                'previous_level' => $previousLevel,
            ];
        }, 3);

        if ($result['success'] ?? false) {
            $this->dispatchExpenseApprovalNotifications(
                expense: $result['expense'],
                approval: $result['approval'],
                approver: $approver,
                previousLevel: (int) ($result['previous_level'] ?? 1),
                result: $result
            );
        }

        return $result;
    }

    /**
     * Reject an expense at current approval level
     */
    public function rejectExpense(Expense $expense, object $rejector, string $comments = ''): array
    {
        $result = DB::transaction(function () use ($expense, $rejector, $comments): array {
            $lockedExpense = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->first();
            if (! $lockedExpense?->approval_id) {
                return ['success' => false, 'code' => 'APPROVAL_STATE_CONFLICT', 'message' => 'No approval workflow found for this expense.'];
            }

            $approval = Approval::query()->whereKey($lockedExpense->approval_id)->lockForUpdate()->first();
            if (! $approval) {
                return ['success' => false, 'code' => 'APPROVAL_STATE_CONFLICT', 'message' => 'Approval record not found.'];
            }
            if ($approval->status !== ApprovalStatus::PENDING || $lockedExpense->status !== 'submitted') {
                return ['success' => false, 'code' => 'APPROVAL_STATE_CONFLICT', 'message' => 'This expense has already advanced or is no longer pending.', 'approval' => $approval];
            }

            $transition = $this->approvalService->reject($approval, $rejector, $comments);
            if (! ($transition['success'] ?? false)) {
                return [...$transition, 'code' => 'APPROVAL_STATE_CONFLICT', 'approval' => $approval];
            }

            $lockedExpense->update([
                'status' => 'rejected',
                'approval_notes' => $comments,
                'current_approval_level' => $approval->current_level,
            ]);

            return [...$transition, 'expense' => $lockedExpense->fresh(), 'approval' => $approval->fresh()];
        }, 3);

        if ($result['success'] ?? false) {
            $this->dispatchExpenseRejectionNotifications($result['expense'], $result['approval'], $comments);
        }

        return $result;
    }

    private function dispatchExpenseApprovalNotifications(
        Expense $expense,
        Approval $approval,
        object $approver,
        int $previousLevel,
        array $result
    ): void {
        $shopOwnerId = (int) $approval->shop_owner_id;
        $expenseData = $this->buildExpenseNotificationData($expense, $approver, null);
        $financeActionUrl = "/finance?section=expense-tracking&expense={$expense->id}";
        $shopOwnerActionUrl = $this->notificationService->ownerApprovalActionUrl('expense', $expense->id);

        if ($result['is_final'] ?? false) {
            $requesterId = $this->resolveRequesterId($expense, $approval);
            if ($requesterId) {
                $this->notificationService->sendToUser(
                    userId: $requesterId,
                    type: NotificationType::EXPENSE_APPROVAL,
                    title: 'Expense Approved',
                    message: "Your expense {$expenseData['reference']} for ₱{$expenseData['amount']} has been approved.",
                    data: $expenseData,
                    actionUrl: $financeActionUrl,
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
                actionUrl: $shopOwnerActionUrl,
                priority: 'medium'
            );

            return;
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

    private function buildExpenseNotificationData(Expense $expense, ?object $actor = null, ?string $reason = null): array
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
            default => 'Unknown'
        };
    }
}
