<?php

namespace App\Services;

use App\Models\InventoryColorVariant;
use App\Models\Finance\ExpenseSettlement;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptItem;
use App\Models\StockMovement;
use App\Models\SupplierAdjustment;
use App\Models\SupplierPaymentAttempt;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchaseOrderReceiptService
{
    public function __construct(
        private ExpenseApprovalService $expenseApprovalService,
        private SupplierAdjustmentService $supplierAdjustmentService,
    ) {}

    public function post(PurchaseOrder $purchaseOrder, User $receiver, array $data): PurchaseOrderReceipt
    {
        return DB::transaction(function () use ($purchaseOrder, $receiver, $data): PurchaseOrderReceipt {
            $purchaseOrder = PurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            $normalizedItems = $this->normalizeItems($data['items']);
            $payloadHash = $this->payloadHash($normalizedItems->all());
            $existing = PurchaseOrderReceipt::where('purchase_order_id', $purchaseOrder->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (!hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw new HttpException(409, 'This idempotency key was already used with different receipt quantities.');
                }
                return $existing;
            }

            $hasReplacement = $normalizedItems->contains(fn (array $item): bool => $item['replacement_for_adjustment_id'] !== null);
            $replacementOnly = $hasReplacement
                && $normalizedItems->every(fn (array $item): bool => $item['replacement_for_adjustment_id'] !== null);
            $orderItems = $purchaseOrder->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($orderItems->count() === 0 || $normalizedItems->contains(fn (array $item): bool => ! $orderItems->has($item['purchase_order_item_id']))) {
                throw ValidationException::withMessages(['items' => 'A receipt item does not belong to this purchase order.']);
            }

            if ($replacementOnly) {
                return $this->postReplacement($purchaseOrder, $receiver, $data, $normalizedItems, $orderItems, $payloadHash);
            }
            if ($hasReplacement) {
                throw ValidationException::withMessages(['items' => 'A receiving result cannot mix original and replacement units.']);
            }

            $this->assertInitialReceiving($purchaseOrder, $normalizedItems, $orderItems);
            $receivedAt = Carbon::parse($data['received_at'] ?? now());
            $receipt = PurchaseOrderReceipt::create([
                'purchase_order_id' => $purchaseOrder->id,
                'shop_owner_id' => $purchaseOrder->shop_owner_id,
                'source' => 'manual',
                'status' => PurchaseOrderReceipt::STATUS_RECEIVING,
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $payloadHash,
                'received_by' => $receiver->id,
                'received_at' => $receivedAt,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($normalizedItems as $input) {
                $orderItem = $orderItems[$input['purchase_order_item_id']];
                $accepted = $input['received_quantity'] - $input['defective_quantity'];
                $receiptItem = $receipt->items()->create([
                    'purchase_order_item_id' => $orderItem->id,
                    'idempotency_key' => $data['idempotency_key'],
                    'payload_hash' => $payloadHash,
                    'replacement_attempt' => 0,
                    'replacement_for_adjustment_id' => null,
                    'received_quantity' => $input['received_quantity'],
                    'defective_quantity' => $input['defective_quantity'],
                    'accepted_quantity' => $accepted,
                    'inventory_effects' => [],
                ]);

                if ($accepted > 0) {
                    $receiptItem->update([
                        'inventory_effects' => $this->postInventory($purchaseOrder, $orderItem, $receiptItem, $accepted, $input['size_quantities'], $receiver->id),
                    ]);
                }
                if ($input['defective_quantity'] > 0) {
                    $this->supplierAdjustmentService->reportReceivingDefect($receiptItem, $receiver, [
                        'reason_category' => $input['reason_category'],
                        'inventory_notes' => $input['inventory_notes'],
                        'defect_evidence' => $input['defect_evidence'],
                    ]);
                }
            }

            $this->recalculatePendingPurchaseOrder($purchaseOrder, $receipt);

            return $receipt;
        });
    }

    public function finalize(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderReceipt $receipt,
        User $actor,
    ): PurchaseOrderReceipt {
        return DB::transaction(function () use ($purchaseOrder, $receipt, $actor): PurchaseOrderReceipt {
            $purchaseOrder = PurchaseOrder::query()->whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            $receipt = PurchaseOrderReceipt::query()
                ->where('purchase_order_id', $purchaseOrder->id)
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($receipt->isFinal()) {
                return $receipt;
            }
            if ($receipt->status !== PurchaseOrderReceipt::STATUS_RECEIVING) {
                throw ValidationException::withMessages(['receipt' => 'Only a submitted receiving result can become the final receipt.']);
            }
            if ($purchaseOrder->receipts()
                ->where('status', PurchaseOrderReceipt::STATUS_POSTED)
                ->whereNull('voided_at')
                ->where('id', '<>', $receipt->id)
                ->exists()) {
                throw ValidationException::withMessages(['receipt' => 'This purchase order already has its one final receipt.']);
            }
            if ($purchaseOrder->is_historical || ! $purchaseOrder->isReceiving()) {
                throw ValidationException::withMessages(['status' => 'Only a current receiving purchase order can be finalized.']);
            }

            $initialItems = $receipt->items()
                ->whereNull('replacement_for_adjustment_id')
                ->orderBy('id')->lockForUpdate()->get();
            $orderItems = $purchaseOrder->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($initialItems->count() !== $orderItems->count()) {
                throw ValidationException::withMessages(['receipt' => 'Every purchase-order item must be physically accounted for before finalization.']);
            }
            foreach ($orderItems as $orderItem) {
                $received = (int) $initialItems->firstWhere('purchase_order_item_id', $orderItem->id)?->received_quantity;
                if ($received !== (int) $orderItem->ordered_quantity) {
                    throw ValidationException::withMessages(['receipt' => 'The complete original supplier delivery is required before finalization.']);
                }
            }

            $adjustments = SupplierAdjustment::query()
                ->where('issue_stage', SupplierAdjustment::ISSUE_STAGE_RECEIVING_DEFECT)
                ->whereHas('receiptItem', fn ($query) => $query->where('purchase_order_receipt_id', $receipt->id))
                ->lockForUpdate()->get();
            foreach ($adjustments as $adjustment) {
                if ($adjustment->status !== SupplierAdjustment::STATUS_RESOLVED) {
                    throw ValidationException::withMessages(['receipt' => 'Every supplier adjustment must be resolved before finalization.']);
                }
                if (in_array($adjustment->return_status, [SupplierAdjustment::RETURN_REQUIRED, SupplierAdjustment::RETURN_RELEASED], true)) {
                    throw ValidationException::withMessages(['receipt' => 'A required defective return must be received by the supplier or waived before finalization.']);
                }
            }

            $reference = $this->nextReceiptReference((int) $receipt->shop_owner_id, $receipt->received_at);
            $receipt->update([
                'status' => PurchaseOrderReceipt::STATUS_POSTED,
                'receipt_reference' => $reference,
            ]);

            $this->markPurchaseOrderFinalized($purchaseOrder, $receipt);

            $payableCents = 0;
            foreach ($receipt->items()->lockForUpdate()->get() as $receiptItem) {
                $orderItem = $orderItems->get($receiptItem->purchase_order_item_id);
                $payableCents += (int) $receiptItem->accepted_quantity * $this->toCents($orderItem?->unit_cost);
            }
            if ($payableCents > 0) {
                $this->expenseApprovalService->submitProcurementExpense($receipt, $actor, $this->formatCents($payableCents));
            }

            return $receipt;
        }, 3);
    }

    private function normalizeItems(array $items)
    {
        return collect($items)->map(function ($item): array {
            $sizes = collect($item['size_quantities'] ?? [])->map(fn ($size) => [
                'inventory_size_id' => (int) $size['inventory_size_id'],
                'received_quantity' => (int) $size['received_quantity'],
                'defective_quantity' => (int) $size['defective_quantity'],
            ])->sortBy('inventory_size_id')->values();
            $evidence = array_values($item['defect_evidence'] ?? []);

            return [
                'purchase_order_item_id' => (int) $item['purchase_order_item_id'],
                'received_quantity' => $sizes->isEmpty() ? (int) $item['received_quantity'] : $sizes->sum('received_quantity'),
                'defective_quantity' => $sizes->isEmpty() ? (int) $item['defective_quantity'] : $sizes->sum('defective_quantity'),
                'size_quantities' => $sizes->all(),
                'replacement_for_adjustment_id' => filled($item['replacement_for_adjustment_id'] ?? null)
                    ? (int) $item['replacement_for_adjustment_id'] : null,
                'reason_category' => filled($item['reason_category'] ?? null) ? trim((string) $item['reason_category']) : null,
                'inventory_notes' => filled($item['inventory_notes'] ?? null) ? trim((string) $item['inventory_notes']) : null,
                'defect_evidence' => $evidence,
                'defect_evidence_hashes' => $this->evidenceHashes($evidence),
            ];
        })->sortBy('purchase_order_item_id')->values();
    }

    private function payloadHash(array $items): string
    {
        return hash('sha256', json_encode(
            collect($items)->map(fn (array $item): array => collect($item)->except('defect_evidence')->all())->all(),
            JSON_THROW_ON_ERROR,
        ));
    }

    private function assertInitialReceiving(PurchaseOrder $purchaseOrder, $items, $orderItems): void
    {
        if ($purchaseOrder->is_historical || ! $purchaseOrder->isReceiving()) {
            throw ValidationException::withMessages(['status' => 'Only a current in-transit purchase order can receive items.']);
        }
        if ($purchaseOrder->receipts()->whereIn('status', [PurchaseOrderReceipt::STATUS_RECEIVING, PurchaseOrderReceipt::STATUS_POSTED])->exists()) {
            throw ValidationException::withMessages(['receipt' => 'This purchase order already has its one receiving result or final receipt.']);
        }
        if ($items->count() !== $orderItems->count()) {
            throw ValidationException::withMessages(['items' => 'Every purchase-order item must be physically accounted for in one receiving result.']);
        }

        foreach ($items as $input) {
            $orderItem = $orderItems->get($input['purchase_order_item_id']);
            if ((int) $input['received_quantity'] !== (int) $orderItem->ordered_quantity) {
                throw ValidationException::withMessages(['items' => 'Partial original supplier deliveries cannot be finalized. Account for the complete ordered quantity.']);
            }
            if ((int) $input['defective_quantity'] > (int) $input['received_quantity']) {
                throw ValidationException::withMessages(['items' => 'Defective quantity cannot exceed received quantity.']);
            }
            $this->validateSizeShape($orderItem, $input['size_quantities']);
            $this->validatePerSizeQuantities($orderItem, $input['size_quantities']);
        }
    }

    private function validateSizeShape(PurchaseOrderItem $orderItem, array $sizeQuantities): void
    {
        $eligibleSizeIds = array_map('intval', $orderItem->eligible_size_ids ?? []);
        $submittedSizeIds = array_column($sizeQuantities, 'inventory_size_id');
        if (count($eligibleSizeIds) > 1 && array_values(array_diff($eligibleSizeIds, $submittedSizeIds)) !== []) {
            throw ValidationException::withMessages(['items' => 'Every snapshotted size requires a receipt allocation.']);
        }
        if (array_diff($submittedSizeIds, $eligibleSizeIds) !== []) {
            throw ValidationException::withMessages(['items' => 'A size allocation does not belong to this purchase order item.']);
        }
    }

    private function postReplacement(PurchaseOrder $purchaseOrder, User $receiver, array $data, $items, $orderItems, string $payloadHash): PurchaseOrderReceipt
    {
        $adjustments = [];
        foreach ($items as $input) {
            $orderItem = $orderItems->get($input['purchase_order_item_id']);
            $adjustment = $this->supplierAdjustmentService->validateReplacement(
                (int) $input['replacement_for_adjustment_id'],
                $purchaseOrder,
                $orderItem,
                $input['received_quantity'] - $input['defective_quantity'],
                $input['received_quantity'],
            );
            $this->validateSizeShape($orderItem, $input['size_quantities']);
            $adjustments[] = $adjustment;
        }
        $issueStages = collect($adjustments)->pluck('issue_stage')->unique()->values();
        if ($issueStages->count() !== 1) {
            throw ValidationException::withMessages(['items' => 'Replacement items must belong to one receiving workflow.']);
        }

        if ($issueStages->first() === SupplierAdjustment::ISSUE_STAGE_RECEIVING_DEFECT) {
            $receipt = PurchaseOrderReceipt::query()
                ->where('purchase_order_id', $purchaseOrder->id)
                ->where('status', PurchaseOrderReceipt::STATUS_RECEIVING)
                ->lockForUpdate()->first();
            if (! $receipt) {
                throw ValidationException::withMessages(['receipt' => 'Receiving replacements must remain on the original pre-final receiving result.']);
            }
            $existingItems = $receipt->items()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->get();
            if ($existingItems->isNotEmpty()) {
                if ($existingItems->count() !== $items->count()
                    || $existingItems->pluck('payload_hash')->sort()->values()->all() !== collect($items)->map(fn (array $item): string => $this->payloadHash([$item]))->sort()->values()->all()) {
                    throw new HttpException(409, 'This idempotency key was already used with different replacement quantities.');
                }
                return $receipt;
            }

            foreach ($items as $index => $input) {
                $orderItem = $orderItems[$input['purchase_order_item_id']];
                $adjustment = $adjustments[$index];
                $replacementAttempt = ((int) $receipt->items()
                    ->where('purchase_order_item_id', $orderItem->id)
                    ->where('replacement_for_adjustment_id', $adjustment->id)
                    ->max('replacement_attempt')) + 1;
                $receiptItem = $receipt->items()->create([
                    'purchase_order_item_id' => $orderItem->id,
                    'idempotency_key' => $data['idempotency_key'],
                    'payload_hash' => $this->payloadHash([$input]),
                    'replacement_for_adjustment_id' => $adjustment->id,
                    'replacement_attempt' => $replacementAttempt,
                    'received_quantity' => $input['received_quantity'],
                    'defective_quantity' => $input['defective_quantity'],
                    'accepted_quantity' => $input['received_quantity'] - $input['defective_quantity'],
                    'inventory_effects' => [],
                ]);
                if ($receiptItem->accepted_quantity > 0) {
                    $receiptItem->update([
                        'inventory_effects' => $this->postInventory($purchaseOrder, $orderItem, $receiptItem, $receiptItem->accepted_quantity, $input['size_quantities'], $receiver->id),
                    ]);
                }
                $this->supplierAdjustmentService->recordReplacementReceipt($adjustment, $receiptItem, $receiver, [
                    'reason_category' => $input['reason_category'],
                    'inventory_notes' => $input['inventory_notes'],
                    'defect_evidence' => $input['defect_evidence'],
                ]);
            }

            $receipt->wasRecentlyCreated = true;

            return $receipt;
        }

        $receivedAt = Carbon::parse($data['received_at'] ?? now());
        $receipt = PurchaseOrderReceipt::create([
            'purchase_order_id' => $purchaseOrder->id,
            'shop_owner_id' => $purchaseOrder->shop_owner_id,
            'source' => 'manual',
            'status' => PurchaseOrderReceipt::STATUS_POSTED,
            'idempotency_key' => $data['idempotency_key'],
            'payload_hash' => $payloadHash,
            'received_by' => $receiver->id,
            'received_at' => $receivedAt,
            'notes' => $data['notes'] ?? null,
        ]);
        foreach ($items as $index => $input) {
            $orderItem = $orderItems[$input['purchase_order_item_id']];
            $adjustment = $adjustments[$index];
            $receiptItem = $receipt->items()->create([
                'purchase_order_item_id' => $orderItem->id,
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $this->payloadHash([$input]),
                'replacement_for_adjustment_id' => $adjustment->id,
                'replacement_attempt' => 1,
                'received_quantity' => $input['received_quantity'],
                'defective_quantity' => $input['defective_quantity'],
                'accepted_quantity' => $input['received_quantity'] - $input['defective_quantity'],
                'inventory_effects' => [],
            ]);
            if ($receiptItem->accepted_quantity > 0) {
                $receiptItem->update([
                    'inventory_effects' => $this->postInventory($purchaseOrder, $orderItem, $receiptItem, $receiptItem->accepted_quantity, $input['size_quantities'], $receiver->id),
                ]);
            }
            $this->supplierAdjustmentService->recordReplacementReceipt($adjustment, $receiptItem, $receiver, [
                'reason_category' => $input['reason_category'],
                'inventory_notes' => $input['inventory_notes'],
                'defect_evidence' => $input['defect_evidence'],
            ]);
        }

        $receipt->wasRecentlyCreated = true;

        return $receipt;
    }

    private function recalculatePendingPurchaseOrder(PurchaseOrder $purchaseOrder, PurchaseOrderReceipt $receipt): void
    {
        $initialItems = $receipt->items()->whereNull('replacement_for_adjustment_id');
        $purchaseOrder->update([
            'received_quantity' => (clone $initialItems)->sum('received_quantity'),
            'defective_quantity' => (clone $initialItems)->sum('defective_quantity'),
            'status' => 'partially_received',
        ]);
    }

    private function markPurchaseOrderFinalized(PurchaseOrder $purchaseOrder, PurchaseOrderReceipt $receipt): void
    {
        $initialItems = $receipt->items()->whereNull('replacement_for_adjustment_id');
        $purchaseOrder->update([
            'received_quantity' => (clone $initialItems)->sum('received_quantity'),
            'defective_quantity' => (clone $initialItems)->sum('defective_quantity'),
            'status' => 'delivered',
            'delivered_by' => $receipt->received_by,
            'delivered_date' => $receipt->received_at,
            'actual_delivery_date' => $receipt->received_at?->toDateString(),
        ]);
    }

    private function nextReceiptReference(int $shopId, Carbon $receivedAt): string
    {
        $year = (int) $receivedAt->year;
        $now = now();
        DB::table('shop_procurement_receipt_sequences')->insertOrIgnore([
            'shop_owner_id' => $shopId,
            'receipt_year' => $year,
            'next_number' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sequence = DB::table('shop_procurement_receipt_sequences')
            ->where('shop_owner_id', $shopId)
            ->where('receipt_year', $year)
            ->lockForUpdate()
            ->first();
        $number = (int) $sequence->next_number;
        DB::table('shop_procurement_receipt_sequences')->where('id', $sequence->id)->update([
            'next_number' => $number + 1,
            'updated_at' => now(),
        ]);

        return sprintf('RCV-%d-%04d', $year, $number);
    }

    private function validatePerSizeQuantities(PurchaseOrderItem $orderItem, array $sizeQuantities): void
    {
        $eligibleSizeIds = array_values(array_unique(array_map('intval', $orderItem->eligible_size_ids ?? [])));
        if (count($eligibleSizeIds) <= 1) {
            return;
        }

        $maximumPerSize = intdiv(
            (int) $orderItem->ordered_quantity + count($eligibleSizeIds) - 1,
            count($eligibleSizeIds)
        );
        $acceptedBySize = $this->acceptedQuantitiesBySize($orderItem);

        foreach ($sizeQuantities as $sizeQuantity) {
            $sizeId = (int) $sizeQuantity['inventory_size_id'];
            $remainingForSize = max(0, $maximumPerSize - ($acceptedBySize[$sizeId] ?? 0));

            if ((int) $sizeQuantity['received_quantity'] > $remainingForSize) {
                throw ValidationException::withMessages([
                    'items' => 'Received quantity for a size exceeds its remaining ordered quantity.',
                ]);
            }
        }
    }

    private function acceptedQuantitiesBySize(PurchaseOrderItem $orderItem): array
    {
        $acceptedBySize = [];
        $receiptItems = $orderItem->receiptItems()
            ->whereHas('receipt', fn ($query) => $query->where('status', 'posted'))
            ->get(['inventory_effects']);

        foreach ($receiptItems as $receiptItem) {
            foreach (($receiptItem->inventory_effects['sizes'] ?? []) as $sizeEffect) {
                $sizeId = (int) ($sizeEffect['id'] ?? 0);
                if ($sizeId < 1) {
                    continue;
                }

                $acceptedBySize[$sizeId] = ($acceptedBySize[$sizeId] ?? 0) + (int) ($sizeEffect['delta'] ?? 0);
            }
        }

        return $acceptedBySize;
    }

    public function void(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderReceipt $receipt,
        User $actor,
        string $reason
    ): PurchaseOrderReceipt {
        return DB::transaction(function () use ($purchaseOrder, $receipt, $actor, $reason): PurchaseOrderReceipt {
            $purchaseOrder = PurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            $receipt = PurchaseOrderReceipt::where('purchase_order_id', $purchaseOrder->id)
                ->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if ($receipt->status === 'voided') {
                return $receipt;
            }
            if ($receipt->source !== 'manual' || $purchaseOrder->is_historical) {
                throw ValidationException::withMessages(['receipt' => 'Migration and historical receipts cannot be voided.']);
            }
            if ($purchaseOrder->isCompleted()) {
                throw ValidationException::withMessages(['receipt' => 'A receipt on a completed purchase order cannot be voided.']);
            }

            $expense = $receipt->expense()->lockForUpdate()->first();
            $blockingPayment = $expense?->supplierPaymentAttempts()
                ->whereIn('status', [
                    SupplierPaymentAttempt::STATUS_INITIATING,
                    SupplierPaymentAttempt::STATUS_AWAITING_VERIFICATION,
                    SupplierPaymentAttempt::STATUS_SUCCEEDED,
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($blockingPayment) {
                throw ValidationException::withMessages([
                    'receipt' => 'The receipt cannot be voided while supplier payment is active or succeeded.',
                ]);
            }
            $settledAmount = $expense
                ? ExpenseSettlement::validSettledAmountForExpense((int) $expense->id)
                : '0.00';
            $postedUnpaid = $expense
                && (string) $expense->status === 'posted'
                && $settledAmount === '0.00';
            if ($expense && !in_array($expense->status, ['submitted', 'rejected'], true) && ! $postedUnpaid) {
                throw ValidationException::withMessages(['receipt' => 'The linked expense status no longer permits receipt voiding.']);
            }

            $receiptItems = $receipt->items()->with('purchaseOrderItem')->lockForUpdate()->get();
            $requiredParents = [];
            $requiredColors = [];
            $requiredSizes = [];
            foreach ($receiptItems as $receiptItem) {
                $effects = $receiptItem->inventory_effects ?? [];
                if ($parent = $effects['parent'] ?? null) {
                    $requiredParents[$parent['id']] = ($requiredParents[$parent['id']] ?? 0) + (int) $parent['delta'];
                }
                if ($color = $effects['color_variant'] ?? null) {
                    $requiredColors[$color['id']] = ($requiredColors[$color['id']] ?? 0) + (int) $color['delta'];
                }
                foreach ($effects['sizes'] ?? [] as $size) {
                    $requiredSizes[$size['id']] = ($requiredSizes[$size['id']] ?? 0) + (int) $size['delta'];
                }
            }

            $parents = InventoryItem::whereIn('id', array_keys($requiredParents))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $colors = InventoryColorVariant::whereIn('id', array_keys($requiredColors))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $sizes = InventorySize::whereIn('id', array_keys($requiredSizes))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->assertReversalBalances($parents, $requiredParents, 'available_quantity', 'parent inventory');
            $this->assertReversalBalances($colors, $requiredColors, 'quantity', 'color inventory');
            $this->assertReversalBalances($sizes, $requiredSizes, 'quantity', 'size inventory');

            foreach ($receiptItems as $receiptItem) {
                $effects = $receiptItem->inventory_effects ?? [];
                $parent = $effects['parent'] ?? null;
                if (!$parent) {
                    continue;
                }

                $inventory = $parents[$parent['id']];
                $delta = (int) $parent['delta'];
                $before = (int) $inventory->available_quantity;
                $inventory->decrement('available_quantity', $delta);

                if ($color = $effects['color_variant'] ?? null) {
                    $colors[$color['id']]->decrement('quantity', (int) $color['delta']);
                }
                foreach ($effects['sizes'] ?? [] as $size) {
                    $sizes[$size['id']]->decrement('quantity', (int) $size['delta']);
                }

                $original = StockMovement::where('purchase_order_receipt_item_id', $receiptItem->id)
                    ->lockForUpdate()->first();
                if ($original && !$original->reversal()->exists()) {
                    StockMovement::create([
                        'inventory_item_id' => $inventory->id,
                        'movement_type' => 'adjustment',
                        'quantity_change' => -$delta,
                        'quantity_before' => $before,
                        'quantity_after' => $before - $delta,
                        'reference_type' => PurchaseOrderReceipt::class,
                        'reference_id' => $receipt->id,
                        'reversal_of_stock_movement_id' => $original->id,
                        'notes' => "Voided receipt #{$receipt->id}: {$reason}",
                        'performed_by' => $actor->id,
                        'performed_at' => now(),
                    ]);
                }
            }

            $receipt->update([
                'status' => 'voided',
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            if ($expense) {
                $this->expenseApprovalService->rejectForVoidedReceipt($expense, $receipt);
            }
            $this->recalculateAfterVoid($purchaseOrder);

            return $receipt;
        });
    }

    private function postInventory(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderItem $orderItem,
        PurchaseOrderReceiptItem $receiptItem,
        int $accepted,
        array $sizeQuantities,
        int $receiverId
    ): array {
        if (!$orderItem->inventory_item_id) {
            return [];
        }

        $inventory = InventoryItem::whereKey($orderItem->inventory_item_id)->lockForUpdate()->firstOrFail();
        if ($inventory->shop_owner_id !== $purchaseOrder->shop_owner_id) {
            throw ValidationException::withMessages(['items' => 'Inventory target does not belong to this shop.']);
        }

        $sizeIds = array_map('intval', $orderItem->eligible_size_ids ?? []);
        $sizes = InventorySize::where('inventory_item_id', $inventory->id)
            ->whereIn('id', $sizeIds)->orderBy('id')->lockForUpdate()->get();
        if ($sizes->count() !== count($sizeIds)) {
            throw ValidationException::withMessages(['items' => 'A snapshotted inventory size no longer exists.']);
        }
        if (filled($orderItem->requested_size) && $sizes->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'The requested inventory size is not available for receiving.']);
        }

        $sizeDeltas = collect($sizeQuantities)->mapWithKeys(fn ($size) => [
            (int) $size['inventory_size_id'] => (int) $size['received_quantity'] - (int) $size['defective_quantity'],
        ])->all();
        if ($sizeDeltas === [] && $sizes->count() === 1) {
            $sizeDeltas[(int) $sizes->first()->id] = $accepted;
        }

        $parentDelta = $sizes->isEmpty() ? $accepted : array_sum($sizeDeltas);
        $sizeEffects = [];
        foreach ($sizes as $size) {
            $delta = (int) ($sizeDeltas[$size->id] ?? 0);
            if ($delta > 0) {
                $size->increment('quantity', $delta);
                $sizeEffects[] = ['id' => $size->id, 'delta' => $delta];
            }
        }

        $colorEffect = null;
        $colorVariantIds = $sizes->pluck('inventory_color_variant_id')->filter()->unique()->values();
        if ($colorVariantIds->count() > 1) {
            throw ValidationException::withMessages(['items' => 'Snapshotted sizes span more than one color variant.']);
        }
        $colorVariantId = $colorVariantIds->first();
        if (!$colorVariantId && filled($orderItem->requested_color)) {
            $colorVariantId = InventoryColorVariant::where('inventory_item_id', $inventory->id)
                ->whereRaw('LOWER(color_name) = ?', [strtolower(trim($orderItem->requested_color))])
                ->value('id');
        }
        if ($colorVariantId) {
            $color = InventoryColorVariant::whereKey($colorVariantId)->lockForUpdate()->firstOrFail();
            $color->increment('quantity', $parentDelta);
            $colorEffect = ['id' => $color->id, 'delta' => $parentDelta];
        }

        $before = $inventory->available_quantity;
        $inventory->increment('available_quantity', $parentDelta);
        StockMovement::create([
            'inventory_item_id' => $inventory->id,
            'movement_type' => 'stock_in',
            'quantity_change' => $parentDelta,
            'quantity_before' => $before,
            'quantity_after' => $before + $parentDelta,
            'reference_type' => PurchaseOrderReceipt::class,
            'reference_id' => $receiptItem->purchase_order_receipt_id,
            'purchase_order_receipt_item_id' => $receiptItem->id,
            'notes' => "Received from PO {$purchaseOrder->po_number}",
            'performed_by' => $receiverId,
            'performed_at' => now(),
        ]);

        return [
            'parent' => ['id' => $inventory->id, 'delta' => $parentDelta],
            'color_variant' => $colorEffect,
            'sizes' => $sizeEffects,
        ];
    }

    private function recalculatePurchaseOrder(PurchaseOrder $purchaseOrder, int $receiverId, Carbon $receivedAt): void
    {
        $postedItems = PurchaseOrderReceiptItem::whereHas('receipt', fn ($query) =>
            $query->where('purchase_order_id', $purchaseOrder->id)->where('status', 'posted'));
        $purchaseOrder->received_quantity = (clone $postedItems)->sum('received_quantity');
        $purchaseOrder->defective_quantity = (clone $postedItems)->sum('defective_quantity');
        $purchaseOrder->save();

        $fullyReceived = $purchaseOrder->items()->get()
            ->every(fn (PurchaseOrderItem $item) => $item->remainingQuantity() === 0);
        if ($fullyReceived) {
            $purchaseOrder->markAsDeliveredFromReceipts($receiverId, $receivedAt->toDateString());
            return;
        }

        $purchaseOrder->update(['status' => 'partially_received']);
    }

    private function assertReversalBalances($models, array $required, string $column, string $label): void
    {
        if ($models->count() !== count($required)) {
            throw ValidationException::withMessages(['receipt' => "A recorded {$label} target no longer exists."]);
        }
        foreach ($required as $id => $quantity) {
            if ((int) $models[$id]->{$column} < $quantity) {
                throw ValidationException::withMessages(['receipt' => "Insufficient {$label} remains to void this receipt."]);
            }
        }
    }

    private function recalculateAfterVoid(PurchaseOrder $purchaseOrder): void
    {
        $postedItems = PurchaseOrderReceiptItem::whereHas('receipt', fn ($query) =>
            $query->where('purchase_order_id', $purchaseOrder->id)->where('status', 'posted'));
        $received = (clone $postedItems)->sum('received_quantity');
        $defective = (clone $postedItems)->sum('defective_quantity');
        $accepted = (clone $postedItems)->sum('accepted_quantity');
        $fullyReceived = $purchaseOrder->items()->get()
            ->every(fn (PurchaseOrderItem $item) => $item->remainingQuantity() === 0);

        $attributes = [
            'received_quantity' => $received,
            'defective_quantity' => $defective,
        ];
        if ($fullyReceived && $accepted > 0) {
            $completionReceipt = $purchaseOrder->receipts()->where('status', 'posted')->latest('received_at')->first();
            $attributes += [
                'status' => 'delivered',
                'delivered_by' => $completionReceipt?->received_by,
                'delivered_date' => $completionReceipt?->received_at,
                'actual_delivery_date' => $completionReceipt?->received_at?->toDateString(),
            ];
        } else {
            $attributes += [
                'status' => $accepted > 0 ? 'partially_received' : 'in_transit',
                'delivered_by' => null,
                'delivered_date' => null,
                'actual_delivery_date' => null,
            ];
        }

        $purchaseOrder->update($attributes);
    }

    /** @param array<int, mixed> $evidence @return array<int, string> */
    private function evidenceHashes(array $evidence): array
    {
        return collect($evidence)
            ->filter(fn ($file): bool => $file instanceof UploadedFile && $file->isValid() && $file->getRealPath())
            ->map(fn (UploadedFile $file): string => (string) hash_file('sha256', $file->getRealPath()))
            ->values()
            ->all();
    }

    private function toCents(mixed $amount): int
    {
        $text = trim((string) $amount);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) {
            throw ValidationException::withMessages([
                'items' => 'A purchase-order item contains an invalid unit cost.',
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function formatCents(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
