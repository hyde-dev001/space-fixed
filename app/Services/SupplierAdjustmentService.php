<?php

namespace App\Services;

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptItem;
use App\Models\SupplierAdjustment;
use App\Models\SupplierPaymentAttempt;
use App\Models\User;
use App\Services\Finance\ExpenseSettlementService;
use App\Support\Finance\FinanceDomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class SupplierAdjustmentService
{
    private const MAX_EVIDENCE_BYTES = 10 * 1024 * 1024;

    private const EVIDENCE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const REFUND_EVIDENCE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public function __construct(private readonly ExpenseSettlementService $settlementService) {}

    public function reportReceivingDefect(
        PurchaseOrderReceiptItem $receiptItem,
        User $actor,
        array $data,
    ): SupplierAdjustment {
        $media = [];
        $lockedItem = PurchaseOrderReceiptItem::query()
            ->whereKey($receiptItem->getKey())
            ->lockForUpdate()
            ->firstOrFail();
        $receipt = PurchaseOrderReceipt::query()
            ->whereKey($lockedItem->purchase_order_receipt_id)
            ->lockForUpdate()
            ->firstOrFail();
        $purchaseOrder = $receipt->purchaseOrder()->lockForUpdate()->firstOrFail();

        $this->assertActorAndReceipt($actor, $receipt, $purchaseOrder);
        if ((string) $receipt->status !== 'posted' || $receipt->voided_at !== null) {
            throw ValidationException::withMessages(['receipt' => 'Only a posted, non-void receipt can record a supplier defect.']);
        }
        if ((int) $lockedItem->defective_quantity < 1) {
            throw ValidationException::withMessages(['defective_quantity' => 'A receiving defect requires a defective quantity.']);
        }

        $category = trim((string) ($data['reason_category'] ?? ''));
        $notes = trim((string) ($data['inventory_notes'] ?? ''));
        $evidence = $this->validatedEvidence($data['defect_evidence'] ?? []);
        $this->assertIssueDetails($category, $notes, $evidence);

        $shopId = (int) $receipt->shop_owner_id;
        $idempotencyKey = 'receiving:' . $lockedItem->id;
        $existing = SupplierAdjustment::query()
            ->where('shop_owner_id', $shopId)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            $this->assertSameIssue($existing, $lockedItem, (int) $lockedItem->defective_quantity, $category, $notes);

            return $existing->fresh();
        }

        try {
            $adjustment = SupplierAdjustment::create([
                'shop_owner_id' => $shopId,
                'purchase_order_receipt_item_id' => $lockedItem->id,
                'idempotency_key' => $idempotencyKey,
                'issue_stage' => SupplierAdjustment::ISSUE_STAGE_RECEIVING_DEFECT,
                'reported_quantity' => (int) $lockedItem->defective_quantity,
                'unit_cost_snapshot' => (string) $lockedItem->purchaseOrderItem()->value('unit_cost'),
                'reason_category' => $category,
                'inventory_notes' => $notes,
                'status' => SupplierAdjustment::STATUS_REPORTED,
                'reported_by' => $actor->id,
                'reported_at' => now(),
            ]);

            $media = $this->attachEvidence($adjustment, $evidence, 'defect_evidence');
            $this->recordActivity($adjustment, $actor, 'reported', [
                'issue_stage' => SupplierAdjustment::ISSUE_STAGE_RECEIVING_DEFECT,
                'receipt_item_id' => $lockedItem->id,
                'reported_quantity' => (int) $lockedItem->defective_quantity,
                'reason_category' => $category,
                'notes' => $notes,
            ]);

            DB::afterCommit(function () use ($shopId, $purchaseOrder, $adjustment): void {
                try {
                    app(NotificationService::class)->notifySupplierIssueReported($shopId, [
                        'adjustment_id' => $adjustment->id,
                        'po_number' => $purchaseOrder->po_number,
                    ]);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

            return $adjustment->fresh();
        } catch (Throwable $exception) {
            $this->deleteMedia($media);

            throw $exception;
        }
    }

    /** @return array{adjustment: SupplierAdjustment, replayed: bool} */
    public function reportPostPaymentIssue(
        PurchaseOrderReceiptItem $receiptItem,
        User $actor,
        array $data,
    ): array {
        $media = [];

        try {
            $result = DB::transaction(function () use ($receiptItem, $actor, $data, &$media): array {
                $lockedItem = PurchaseOrderReceiptItem::query()
                    ->whereKey($receiptItem->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $receipt = PurchaseOrderReceipt::query()
                    ->whereKey($lockedItem->purchase_order_receipt_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $purchaseOrder = $receipt->purchaseOrder()->lockForUpdate()->firstOrFail();
                $expense = $receipt->expense()->lockForUpdate()->first();

                $this->assertActorAndReceipt($actor, $receipt, $purchaseOrder);
                if ((string) $receipt->status !== 'posted' || $receipt->voided_at !== null) {
                    throw ValidationException::withMessages(['receipt' => 'Only a posted, non-void receipt can report a post-payment issue.']);
                }
                if ((int) $lockedItem->accepted_quantity < 1 || ! $expense) {
                    throw ValidationException::withMessages(['reported_quantity' => 'Only accepted, paid receipt units can be reported.']);
                }
                if ((string) $expense->status !== 'posted'
                    || $this->toCents(ExpenseSettlement::validSettledAmountForExpense((int) $expense->id)) < 1) {
                    throw ValidationException::withMessages(['receipt' => 'The receipt payable must be posted and fully paid before a post-payment issue is reported.']);
                }

                $category = trim((string) ($data['reason_category'] ?? ''));
                $notes = trim((string) ($data['inventory_notes'] ?? ''));
                $quantity = (int) ($data['reported_quantity'] ?? 0);
                $evidence = $this->validatedEvidence($data['defect_evidence'] ?? []);
                $this->assertIssueDetails($category, $notes, $evidence);
                if ($quantity < 1) {
                    throw ValidationException::withMessages(['reported_quantity' => 'Reported quantity must be greater than zero.']);
                }

                $shopId = (int) $receipt->shop_owner_id;
                $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
                if ($idempotencyKey === '') {
                    throw ValidationException::withMessages(['idempotency_key' => 'An idempotency key is required.']);
                }
                $existing = SupplierAdjustment::query()
                    ->where('shop_owner_id', $shopId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    $this->assertSameIssue($existing, $lockedItem, $quantity, $category, $notes);

                    return ['adjustment' => $existing->fresh(), 'replayed' => true];
                }

                $claimed = (int) SupplierAdjustment::query()
                    ->where('shop_owner_id', $shopId)
                    ->where('purchase_order_receipt_item_id', $lockedItem->id)
                    ->where('issue_stage', SupplierAdjustment::ISSUE_STAGE_POST_PAYMENT)
                    ->where('status', '<>', SupplierAdjustment::STATUS_RESOLVED)
                    ->lockForUpdate()
                    ->sum('reported_quantity');
                $remaining = (int) $lockedItem->accepted_quantity - $claimed;
                if ($quantity > $remaining) {
                    throw ValidationException::withMessages([
                        'reported_quantity' => 'The reported quantity exceeds the remaining paid accepted quantity.',
                    ]);
                }

                $adjustment = SupplierAdjustment::create([
                    'shop_owner_id' => $shopId,
                    'purchase_order_receipt_item_id' => $lockedItem->id,
                    'idempotency_key' => $idempotencyKey,
                    'issue_stage' => SupplierAdjustment::ISSUE_STAGE_POST_PAYMENT,
                    'reported_quantity' => $quantity,
                    'unit_cost_snapshot' => (string) $lockedItem->purchaseOrderItem()->value('unit_cost'),
                    'reason_category' => $category,
                    'inventory_notes' => $notes,
                    'status' => SupplierAdjustment::STATUS_REPORTED,
                    'reported_by' => $actor->id,
                    'reported_at' => now(),
                ]);
                $media = $this->attachEvidence($adjustment, $evidence, 'defect_evidence');
                $this->recordActivity($adjustment, $actor, 'reported', [
                    'issue_stage' => SupplierAdjustment::ISSUE_STAGE_POST_PAYMENT,
                    'receipt_item_id' => $lockedItem->id,
                    'reported_quantity' => $quantity,
                    'reason_category' => $category,
                    'notes' => $notes,
                ]);

                return ['adjustment' => $adjustment->fresh(), 'replayed' => false];
            }, 3);

            if (! $result['replayed']) {
                $adjustment = $result['adjustment'];
                $purchaseOrder = $adjustment->load('receiptItem.receipt.purchaseOrder')->receiptItem?->receipt?->purchaseOrder;
                try {
                    app(NotificationService::class)->notifySupplierIssueReported((int) $adjustment->shop_owner_id, [
                        'adjustment_id' => $adjustment->id,
                        'po_number' => $purchaseOrder?->po_number ?? 'unknown',
                    ]);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            return $result;
        } catch (Throwable $exception) {
            $this->deleteMedia($media);

            throw $exception;
        }
    }

    public function recordSupplierRefundProof(
        SupplierAdjustment $adjustment,
        User $actor,
        array $data,
        ?UploadedFile $proof,
    ): SupplierAdjustment {
        $media = [];

        try {
            $updated = DB::transaction(function () use ($adjustment, $actor, $data, $proof, &$media): SupplierAdjustment {
                $lockedAdjustment = SupplierAdjustment::query()
                    ->whereKey($adjustment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $receiptItem = PurchaseOrderReceiptItem::query()
                    ->whereKey($lockedAdjustment->purchase_order_receipt_item_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $receipt = PurchaseOrderReceipt::query()
                    ->whereKey($receiptItem->purchase_order_receipt_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $purchaseOrder = $receipt->purchaseOrder()->lockForUpdate()->firstOrFail();
                $expense = $receipt->expense()->lockForUpdate()->first();

                $this->assertActorAndReceipt($actor, $receipt, $purchaseOrder);
                $this->assertRefundIssue($lockedAdjustment, $receipt, $purchaseOrder, $expense);
                $this->assertConfirmedPayment($expense, (int) $receipt->shop_owner_id);

                if (ExpenseSettlement::validRefundedAmountForAdjustment((int) $lockedAdjustment->id) !== '0.00') {
                    throw new FinanceDomainException('The expected refund cannot change after a refund has been confirmed.', 'INVALID_STATE', 422);
                }

                $expectedAmount = $this->normalizeRefundAmount($data['expected_refund_amount'] ?? null);
                $this->assertExpectedRefundAmount($lockedAdjustment, $expense, $expectedAmount);

                $files = $this->validatedRefundProof($proof);
                if ($files === [] && $lockedAdjustment->getMedia('supplier_refund_proof')->isEmpty()) {
                    throw new FinanceDomainException('Supplier refund proof is required.', 'INVALID_STATE', 422);
                }
                if ($files !== []) {
                    $media = $this->attachEvidence($lockedAdjustment, $files, 'supplier_refund_proof');
                }

                $lockedAdjustment->update([
                    'resolution' => SupplierAdjustment::RESOLUTION_REFUND,
                    'status' => SupplierAdjustment::STATUS_AWAITING_VERIFICATION,
                    'expected_refund_amount' => $expectedAmount,
                    'supplier_reported_refund_amount' => $this->nullableRefundAmount($data['supplier_reported_refund_amount'] ?? null),
                    'supplier_reported_refund_reference' => $this->nullableText($data['supplier_reported_refund_reference'] ?? null),
                    'supplier_reported_refund_date' => $data['supplier_reported_refund_date'] ?? null,
                    'procurement_notes' => $this->nullableText($data['procurement_notes'] ?? null),
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                    'resolved_by' => null,
                    'resolved_at' => null,
                ]);
                $this->recordActivity($lockedAdjustment, $actor, 'supplier_refund_proof_submitted', [
                    'expected_refund_amount' => $expectedAmount,
                    'supplier_reported_refund_reference' => $lockedAdjustment->supplier_reported_refund_reference,
                    'status' => SupplierAdjustment::STATUS_AWAITING_VERIFICATION,
                ]);

                return $lockedAdjustment->fresh();
            }, 3);

            $purchaseOrder = $updated->load('receiptItem.receipt.purchaseOrder')->receiptItem?->receipt?->purchaseOrder;
            try {
                app(NotificationService::class)->notifySupplierRefundProofSubmitted((int) $updated->shop_owner_id, [
                    'adjustment_id' => $updated->id,
                    'expense_id' => $updated->receiptItem?->receipt?->expense?->id,
                    'po_number' => $purchaseOrder?->po_number ?? 'unknown',
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }

            return $updated;
        } catch (Throwable $exception) {
            $this->deleteMedia($media);

            throw $exception;
        }
    }

    /** @return array{adjustment: SupplierAdjustment, settlement: ExpenseSettlement, expense: array, replayed: bool} */
    public function confirmSupplierRefund(
        SupplierAdjustment $adjustment,
        User $actor,
        array $data,
        UploadedFile $financeProof,
    ): array {
        $shopId = (int) ($actor->shop_owner_id ?? 0);
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        if ($shopId < 1 || $idempotencyKey === '') {
            throw new FinanceDomainException('A Finance shop context and refund idempotency key are required.', 'INVALID_STATE', 422);
        }

        $existing = ExpenseSettlement::query()
            ->where('shop_owner_id', $shopId)
            ->where('idempotency_key', $idempotencyKey)
            ->where('entry_type', ExpenseSettlement::ENTRY_SUPPLIER_REFUND)
            ->first();
        if (! $existing) {
            $sourceReference = 'supplier-refund:' . $shopId . ':' . trim((string) ($data['external_transaction_reference'] ?? ''));
            if (ExpenseSettlement::query()
                ->where('shop_owner_id', $shopId)
                ->where('source', ExpenseSettlement::SOURCE_SUPPLIER_REFUND)
                ->where('source_reference', $sourceReference)
                ->exists()) {
                throw new FinanceDomainException('That supplier refund reference is already in use.', 'DUPLICATE_SUBMISSION', 409);
            }

            $this->attachFinanceRefundProof($adjustment, $actor, $financeProof);
        }

        $result = $this->settlementService->recordSupplierRefund($adjustment, $actor, $data);
        $updatedAdjustment = $result['replayed']
            ? $adjustment->fresh()
            : $this->finalizeSupplierRefund($adjustment, $actor, $result['settlement']);

        if (! $result['replayed']) {
            $purchaseOrder = $updatedAdjustment->load('receiptItem.receipt.purchaseOrder')->receiptItem?->receipt?->purchaseOrder;
            try {
                app(NotificationService::class)->notifySupplierRefundConfirmed((int) $updatedAdjustment->shop_owner_id, [
                    'adjustment_id' => $updatedAdjustment->id,
                    'po_number' => $purchaseOrder?->po_number ?? 'unknown',
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return [
            'adjustment' => $updatedAdjustment,
            'settlement' => $result['settlement'],
            'expense' => $result['expense'],
            'replayed' => $result['replayed'],
        ];
    }

    public function validateReplacement(
        int $adjustmentId,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderItem $orderItem,
        int $acceptedQuantity,
    ): SupplierAdjustment {
        $adjustment = SupplierAdjustment::query()
            ->whereKey($adjustmentId)
            ->lockForUpdate()
            ->firstOrFail();
        $originalItem = PurchaseOrderReceiptItem::query()
            ->whereKey($adjustment->purchase_order_receipt_item_id)
            ->lockForUpdate()
            ->firstOrFail();
        $originalReceipt = PurchaseOrderReceipt::query()
            ->whereKey($originalItem->purchase_order_receipt_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $adjustment->shop_owner_id !== (int) $purchaseOrder->shop_owner_id
            || (int) $originalReceipt->shop_owner_id !== (int) $purchaseOrder->shop_owner_id
            || (int) $originalReceipt->purchase_order_id !== (int) $purchaseOrder->id
            || (int) $originalItem->purchase_order_item_id !== (int) $orderItem->id) {
            throw ValidationException::withMessages(['items' => 'The replacement adjustment does not belong to this purchase-order item.']);
        }
        if ((string) $originalReceipt->status !== 'posted' || $originalReceipt->voided_at !== null) {
            throw ValidationException::withMessages(['items' => 'A replacement must reference a posted, non-void original receipt.']);
        }
        if ($adjustment->status === SupplierAdjustment::STATUS_RESOLVED) {
            throw ValidationException::withMessages(['items' => 'This supplier adjustment is already resolved.']);
        }
        if ($adjustment->resolution === SupplierAdjustment::RESOLUTION_REFUND) {
            throw ValidationException::withMessages(['items' => 'A refund adjustment cannot receive a replacement.']);
        }

        $acceptedReplacement = (int) PurchaseOrderReceiptItem::query()
            ->where('replacement_for_adjustment_id', $adjustment->id)
            ->whereHas('receipt', fn ($query) => $query->where('status', 'posted'))
            ->lockForUpdate()
            ->sum('accepted_quantity');
        if ($acceptedQuantity > max(0, (int) $adjustment->reported_quantity - $acceptedReplacement)) {
            throw ValidationException::withMessages(['items' => 'The replacement quantity exceeds the remaining supplier adjustment quantity.']);
        }

        DB::afterCommit(function () use ($adjustment, $purchaseOrder): void {
            try {
                app(NotificationService::class)->notifySupplierReplacementRequested((int) $adjustment->shop_owner_id, [
                    'adjustment_id' => $adjustment->id,
                    'po_number' => $purchaseOrder->po_number,
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        return $adjustment;
    }

    public function recordReplacementReceipt(
        SupplierAdjustment $adjustment,
        PurchaseOrderReceiptItem $receiptItem,
        User $actor,
        array $data,
    ): void {
        $media = [];

        try {
            $adjustment = SupplierAdjustment::query()
                ->whereKey($adjustment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $receipt = $receiptItem->receipt()->lockForUpdate()->firstOrFail();
            $purchaseOrder = $receipt->purchaseOrder()->lockForUpdate()->firstOrFail();
            $this->assertActorAndReceipt($actor, $receipt, $purchaseOrder);

            if ((int) $receiptItem->replacement_for_adjustment_id !== (int) $adjustment->id) {
                throw ValidationException::withMessages(['items' => 'The replacement receipt is not linked to this adjustment.']);
            }
            if ($adjustment->status === SupplierAdjustment::STATUS_RESOLVED
                || $adjustment->resolution === SupplierAdjustment::RESOLUTION_REFUND) {
                throw ValidationException::withMessages(['items' => 'This supplier adjustment cannot receive another replacement.']);
            }

            $defective = (int) $receiptItem->defective_quantity;
            if ($defective > 0) {
                $category = trim((string) ($data['reason_category'] ?? ''));
                $notes = trim((string) ($data['inventory_notes'] ?? ''));
                $evidence = $this->validatedEvidence($data['defect_evidence'] ?? []);
                $this->assertIssueDetails($category, $notes, $evidence);
                $media = $this->attachEvidence($adjustment, $evidence, 'defect_evidence', [
                    'replacement_receipt_item_id' => (int) $receiptItem->id,
                    'replacement_for_adjustment_id' => (int) $adjustment->id,
                ]);
                $this->recordActivity($adjustment, $actor, 'replacement_defect_reported', [
                    'replacement_receipt_item_id' => (int) $receiptItem->id,
                    'reason_category' => $category,
                    'notes' => $notes,
                ]);
            }

            $acceptedReplacement = (int) PurchaseOrderReceiptItem::query()
                ->where('replacement_for_adjustment_id', $adjustment->id)
                ->whereHas('receipt', fn ($query) => $query->where('status', 'posted'))
                ->sum('accepted_quantity');
            $hasReplacementDefect = $adjustment->getMedia('defect_evidence')->contains(
                fn ($mediaItem): bool => filled($mediaItem->getCustomProperty('replacement_receipt_item_id'))
            );
            $attributes = [
                'resolution' => SupplierAdjustment::RESOLUTION_REPLACEMENT,
                'status' => $acceptedReplacement >= (int) $adjustment->reported_quantity && ! $hasReplacementDefect
                    ? SupplierAdjustment::STATUS_RESOLVED
                    : SupplierAdjustment::STATUS_RESOLUTION_IN_PROGRESS,
            ];
            if ($attributes['status'] === SupplierAdjustment::STATUS_RESOLVED) {
                $attributes += [
                    'resolved_by' => $actor->id,
                    'resolved_at' => now(),
                ];
            } else {
                $attributes += [
                    'resolved_by' => null,
                    'resolved_at' => null,
                ];
            }
            $adjustment->update($attributes);
            $this->recordActivity($adjustment, $actor, 'replacement_received', [
                'replacement_receipt_item_id' => (int) $receiptItem->id,
                'accepted_replacement_quantity' => $acceptedReplacement,
                'reported_quantity' => (int) $adjustment->reported_quantity,
                'status' => $adjustment->status,
            ]);

        } catch (Throwable $exception) {
            $this->deleteMedia($media);

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function present(SupplierAdjustment $adjustment): array
    {
        $adjustment->loadMissing([
            'receiptItem.receipt.purchaseOrder',
            'receiptItem.purchaseOrderItem',
            'reportedBy:id,name',
            'reviewedBy:id,name',
            'resolvedBy:id,name',
        ]);
        $receiptItem = $adjustment->receiptItem;
        $receipt = $receiptItem?->receipt;
        $purchaseOrder = $receipt?->purchaseOrder;

        return [
            'id' => (int) $adjustment->id,
            'issue_stage' => (string) $adjustment->issue_stage,
            'reported_quantity' => (int) $adjustment->reported_quantity,
            'unit_cost_snapshot' => (string) $adjustment->unit_cost_snapshot,
            'reason_category' => (string) $adjustment->reason_category,
            'inventory_notes' => (string) $adjustment->inventory_notes,
            'status' => (string) $adjustment->status,
            'resolution' => $adjustment->resolution,
            'procurement_notes' => $adjustment->procurement_notes,
            'expected_refund_amount' => $adjustment->expected_refund_amount,
            'supplier_reported_refund_amount' => $adjustment->supplier_reported_refund_amount,
            'supplier_reported_refund_reference' => $adjustment->supplier_reported_refund_reference,
            'supplier_reported_refund_date' => $adjustment->supplier_reported_refund_date?->toDateString(),
            'refunded_amount' => ExpenseSettlement::validRefundedAmountForAdjustment((int) $adjustment->id),
            'reported_at' => $adjustment->reported_at?->toISOString(),
            'resolved_at' => $adjustment->resolved_at?->toISOString(),
            'reported_by' => $adjustment->reportedBy ? [
                'id' => (int) $adjustment->reportedBy->id,
                'name' => (string) $adjustment->reportedBy->name,
            ] : null,
            'purchase_order' => [
                'id' => $purchaseOrder?->id,
                'number' => $purchaseOrder?->po_number,
                'status' => $purchaseOrder?->status,
            ],
            'receipt' => [
                'id' => $receipt?->id,
                'status' => $receipt?->status,
            ],
            'receipt_item_id' => $receiptItem?->id,
            'purchase_order_item_id' => $receiptItem?->purchase_order_item_id,
            'evidence' => $adjustment->getMedia('defect_evidence')->map(fn ($media): array => [
                'id' => (int) $media->id,
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => (int) $media->size,
            ])->values()->all(),
            'supplier_refund_proof' => $this->presentMedia($adjustment->getMedia('supplier_refund_proof')),
            'finance_confirmation_proof' => $this->presentMedia($adjustment->getMedia('finance_confirmation_proof')),
        ];
    }

    private function attachFinanceRefundProof(
        SupplierAdjustment $adjustment,
        User $actor,
        UploadedFile $proof,
    ): void {
        $media = [];

        try {
            DB::transaction(function () use ($adjustment, $actor, $proof, &$media): void {
                $lockedAdjustment = SupplierAdjustment::query()
                    ->whereKey($adjustment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $receiptItem = $lockedAdjustment->receiptItem()->lockForUpdate()->firstOrFail();
                $receipt = $receiptItem->receipt()->lockForUpdate()->firstOrFail();
                $purchaseOrder = $receipt->purchaseOrder()->lockForUpdate()->firstOrFail();
                $expense = $receipt->expense()->lockForUpdate()->first();

                $this->assertActorAndReceipt($actor, $receipt, $purchaseOrder);
                $this->assertRefundIssue($lockedAdjustment, $receipt, $purchaseOrder, $expense);
                if ($lockedAdjustment->getMedia('supplier_refund_proof')->isEmpty()) {
                    throw new FinanceDomainException('Supplier refund proof must be submitted before Finance confirmation.', 'INVALID_STATE', 422);
                }

                $files = $this->validatedRefundProof($proof);
                $media = $this->attachEvidence($lockedAdjustment, $files, 'finance_confirmation_proof');
                $this->recordActivity($lockedAdjustment, $actor, 'finance_refund_proof_submitted', [
                    'status' => $lockedAdjustment->status,
                ]);
            }, 3);
        } catch (Throwable $exception) {
            $this->deleteMedia($media);

            throw $exception;
        }
    }

    private function finalizeSupplierRefund(
        SupplierAdjustment $adjustment,
        User $actor,
        ExpenseSettlement $settlement,
    ): SupplierAdjustment {
        return DB::transaction(function () use ($adjustment, $actor, $settlement): SupplierAdjustment {
            $lockedAdjustment = SupplierAdjustment::query()->whereKey($adjustment->getKey())->lockForUpdate()->firstOrFail();
            $expectedCents = $this->toCents($lockedAdjustment->expected_refund_amount);
            $refundedCents = $this->toCents(ExpenseSettlement::validRefundedAmountForAdjustment((int) $lockedAdjustment->id));
            $resolved = $expectedCents > 0 && $refundedCents >= $expectedCents;

            $lockedAdjustment->update([
                'resolution' => SupplierAdjustment::RESOLUTION_REFUND,
                'status' => $resolved
                    ? SupplierAdjustment::STATUS_RESOLVED
                    : SupplierAdjustment::STATUS_PARTIALLY_REFUNDED,
                'resolved_by' => $resolved ? $actor->id : null,
                'resolved_at' => $resolved ? now() : null,
            ]);
            $this->recordActivity($lockedAdjustment, $actor, 'supplier_refund_confirmed', [
                'settlement_id' => (int) $settlement->id,
                'amount' => (string) $settlement->amount,
                'refunded_amount' => ExpenseSettlement::validRefundedAmountForAdjustment((int) $lockedAdjustment->id),
                'status' => $lockedAdjustment->status,
            ]);

            return $lockedAdjustment->fresh();
        }, 3);
    }

    private function assertRefundIssue(
        SupplierAdjustment $adjustment,
        PurchaseOrderReceipt $receipt,
        PurchaseOrder $purchaseOrder,
        ?Expense $expense,
    ): void {
        if ((int) $adjustment->shop_owner_id !== (int) $receipt->shop_owner_id
            || (int) $purchaseOrder->shop_owner_id !== (int) $receipt->shop_owner_id) {
            throw new FinanceDomainException('The supplier adjustment is not available in this shop.', 'FORBIDDEN', 403);
        }
        if ((string) $adjustment->issue_stage !== SupplierAdjustment::ISSUE_STAGE_POST_PAYMENT) {
            throw new FinanceDomainException('Only a post-payment issue can request a supplier refund.', 'INVALID_STATE', 422);
        }
        if ($adjustment->status === SupplierAdjustment::STATUS_RESOLVED
            || ($adjustment->resolution !== null && $adjustment->resolution !== SupplierAdjustment::RESOLUTION_REFUND)) {
            throw new FinanceDomainException('This supplier adjustment cannot use a refund resolution.', 'INVALID_STATE', 422);
        }
        if ((string) $receipt->status !== 'posted' || $receipt->voided_at !== null || ! $expense || (string) $expense->status !== 'posted') {
            throw new FinanceDomainException('A posted, nonvoid procurement expense is required for a supplier refund.', 'INVALID_STATE', 422);
        }
    }

    private function assertConfirmedPayment(?Expense $expense, int $shopId): SupplierPaymentAttempt
    {
        if (! $expense) {
            throw new FinanceDomainException('A procurement expense is required for a supplier refund.', 'INVALID_STATE', 422);
        }

        $paidCents = $this->toCents(ExpenseSettlement::validSettledAmountForExpense((int) $expense->id));
        if ($paidCents < $this->toCents($expense->amount)) {
            throw new FinanceDomainException('A confirmed supplier payment is required before a refund.', 'INVALID_STATE', 422);
        }

        $attempt = SupplierPaymentAttempt::query()
            ->where('shop_owner_id', $shopId)
            ->where('expense_id', $expense->id)
            ->where('status', SupplierPaymentAttempt::STATUS_SUCCEEDED)
            ->whereNotNull('settlement_id')
            ->first();
        if (! $attempt) {
            throw new FinanceDomainException('A confirmed supplier payment is required before a refund.', 'INVALID_STATE', 422);
        }

        return $attempt;
    }

    private function assertExpectedRefundAmount(
        SupplierAdjustment $adjustment,
        Expense $expense,
        string $amount,
    ): void {
        $amountCents = $this->toCents($amount);
        $maximumCents = $this->toCents($adjustment->unit_cost_snapshot) * (int) $adjustment->reported_quantity;
        $paidCents = $this->toCents(ExpenseSettlement::validSettledAmountForExpense((int) $expense->id));
        if ($amountCents <= 0 || $amountCents > $maximumCents || $amountCents > $paidCents) {
            throw new FinanceDomainException('The expected refund amount is outside the paid adjustment value.', 'INVALID_STATE', 422);
        }
    }

    private function normalizeRefundAmount(mixed $amount): string
    {
        $text = trim((string) $amount);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) {
            throw new FinanceDomainException('Refund amount must be a valid decimal.', 'INVALID_STATE', 422);
        }

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '0');

        return ((int) $whole) . '.' . str_pad($fraction, 2, '0');
    }

    private function nullableRefundAmount(mixed $amount): ?string
    {
        if ($amount === null || trim((string) $amount) === '') {
            return null;
        }

        return $this->normalizeRefundAmount($amount);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array<int, UploadedFile> */
    private function validatedRefundProof(?UploadedFile $proof): array
    {
        if (! $proof) {
            return [];
        }
        if (! $proof->isValid()
            || ! in_array((string) $proof->getMimeType(), self::REFUND_EVIDENCE_MIMES, true)
            || (int) $proof->getSize() > self::MAX_EVIDENCE_BYTES) {
            throw new FinanceDomainException('Refund proof must be a valid image or PDF up to 10 MB.', 'INVALID_STATE', 422);
        }

        return [$proof];
    }

    /** @param iterable<int, mixed> $media */
    private function presentMedia(iterable $media): array
    {
        return collect($media)->map(fn ($mediaItem): array => [
            'id' => (int) $mediaItem->id,
            'file_name' => $mediaItem->file_name,
            'mime_type' => $mediaItem->mime_type,
            'size' => (int) $mediaItem->size,
        ])->values()->all();
    }

    private function assertActorAndReceipt(User $actor, PurchaseOrderReceipt $receipt, $purchaseOrder): void
    {
        if ((int) ($actor->shop_owner_id ?? 0) !== (int) $receipt->shop_owner_id
            || (int) $purchaseOrder->shop_owner_id !== (int) $receipt->shop_owner_id) {
            throw ValidationException::withMessages(['receipt' => 'The receipt is not available in this shop.']);
        }
    }

    /** @return array<int, UploadedFile> */
    private function validatedEvidence(mixed $evidence): array
    {
        if ($evidence instanceof UploadedFile) {
            $evidence = [$evidence];
        }
        if (! is_array($evidence)) {
            return [];
        }

        $files = array_values(array_filter($evidence, fn ($file): bool => $file instanceof UploadedFile));
        foreach ($files as $file) {
            if (! $file->isValid()
                || ! in_array((string) $file->getMimeType(), self::EVIDENCE_MIMES, true)
                || (int) $file->getSize() > self::MAX_EVIDENCE_BYTES) {
                throw ValidationException::withMessages(['defect_evidence' => 'Each defect evidence file must be a valid supported image up to 10 MB.']);
            }
        }

        return $files;
    }

    /** @param array<int, UploadedFile> $evidence */
    private function assertIssueDetails(string $category, string $notes, array $evidence): void
    {
        if (! in_array($category, SupplierAdjustment::REASON_CATEGORIES, true)) {
            throw ValidationException::withMessages(['reason_category' => 'The defect category is not supported.']);
        }
        if ($notes === '' || ($category === 'other' && $notes === '')) {
            throw ValidationException::withMessages(['inventory_notes' => 'Defect notes are required.']);
        }
        if ($evidence === []) {
            throw ValidationException::withMessages(['defect_evidence' => 'At least one defect image is required.']);
        }
    }

    private function assertSameIssue(
        SupplierAdjustment $existing,
        PurchaseOrderReceiptItem $receiptItem,
        int $quantity,
        string $category,
        string $notes,
    ): void {
        if ((int) $existing->purchase_order_receipt_item_id !== (int) $receiptItem->id
            || (int) $existing->reported_quantity !== $quantity
            || (string) $existing->reason_category !== $category
            || (string) $existing->inventory_notes !== $notes) {
            throw new HttpException(409, 'This idempotency key was already used with different supplier issue details.');
        }
    }

    /** @param array<int, UploadedFile> $evidence @return array<int, mixed> */
    private function attachEvidence(SupplierAdjustment $adjustment, array $evidence, string $collection, array $customProperties = []): array
    {
        $media = [];
        foreach ($evidence as $file) {
            $adder = $adjustment->addMedia($file);
            if ($customProperties !== []) {
                $adder->withCustomProperties($customProperties);
            }
            $media[] = $adder->toMediaCollection($collection);
        }

        return $media;
    }

    /** @param array<int, mixed> $media */
    private function deleteMedia(array $media): void
    {
        foreach ($media as $item) {
            try {
                $item->delete();
            } catch (Throwable) {
                // Preserve the original transaction failure.
            }
        }
    }

    /** @param array<string, mixed> $properties */
    private function recordActivity(SupplierAdjustment $adjustment, User $actor, string $event, array $properties): void
    {
        activity('supplier_adjustments')
            ->performedOn($adjustment)
            ->causedBy($actor)
            ->withProperties($properties + [
                'adjustment_id' => (int) $adjustment->id,
                'actor_id' => (int) $actor->id,
                'event' => $event,
            ])
            ->log('Supplier adjustment ' . $event);
    }

    private function toCents(mixed $amount): int
    {
        $text = trim((string) $amount);
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $text)) {
            return 0;
        }
        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '+-');
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }
}
