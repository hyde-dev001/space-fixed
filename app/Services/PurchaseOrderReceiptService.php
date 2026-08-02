<?php

namespace App\Services;

use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchaseOrderReceiptService
{
    public function __construct(private ExpenseApprovalService $expenseApprovalService) {}

    public function post(PurchaseOrder $purchaseOrder, User $receiver, array $data): PurchaseOrderReceipt
    {
        return DB::transaction(function () use ($purchaseOrder, $receiver, $data): PurchaseOrderReceipt {
            $purchaseOrder = PurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            $normalizedItems = collect($data['items'])
                ->map(fn ($item) => [
                    'purchase_order_item_id' => (int) $item['purchase_order_item_id'],
                    'received_quantity' => (int) $item['received_quantity'],
                    'defective_quantity' => (int) $item['defective_quantity'],
                ])
                ->sortBy('purchase_order_item_id')->values();
            $payloadHash = hash('sha256', json_encode($normalizedItems->all(), JSON_THROW_ON_ERROR));
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

            if ($purchaseOrder->is_historical
                || !in_array($purchaseOrder->status, ['in_transit', 'partially_received'], true)) {
                throw ValidationException::withMessages(['status' => 'Only a current in-transit purchase order can receive items.']);
            }

            $itemIds = $normalizedItems->pluck('purchase_order_item_id')->all();
            $orderItems = PurchaseOrderItem::where('purchase_order_id', $purchaseOrder->id)
                ->whereIn('id', $itemIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($orderItems->count() !== count($itemIds)) {
                throw ValidationException::withMessages(['items' => 'A receipt item does not belong to this purchase order.']);
            }

            foreach ($normalizedItems as $input) {
                $accepted = $input['received_quantity'] - $input['defective_quantity'];
                if ($input['defective_quantity'] > $input['received_quantity']) {
                    throw ValidationException::withMessages(['items' => 'Defective quantity cannot exceed received quantity.']);
                }
                if ($accepted > $orderItems[$input['purchase_order_item_id']]->remainingQuantity()) {
                    throw ValidationException::withMessages(['items' => 'Accepted quantity exceeds the remaining ordered quantity.']);
                }
            }

            $receivedAt = Carbon::parse($data['received_at'] ?? now());
            $receipt = PurchaseOrderReceipt::create([
                'purchase_order_id' => $purchaseOrder->id,
                'shop_owner_id' => $purchaseOrder->shop_owner_id,
                'source' => 'manual',
                'status' => 'posted',
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $payloadHash,
                'received_by' => $receiver->id,
                'received_at' => $receivedAt,
                'notes' => $data['notes'] ?? null,
            ]);

            $expenseAmount = 0.0;
            foreach ($normalizedItems as $input) {
                $orderItem = $orderItems[$input['purchase_order_item_id']];
                $accepted = $input['received_quantity'] - $input['defective_quantity'];
                $receiptItem = $receipt->items()->create([
                    'purchase_order_item_id' => $orderItem->id,
                    'received_quantity' => $input['received_quantity'],
                    'defective_quantity' => $input['defective_quantity'],
                    'accepted_quantity' => $accepted,
                    'inventory_effects' => [],
                ]);

                if ($accepted > 0) {
                    $receiptItem->update([
                        'inventory_effects' => $this->postInventory($purchaseOrder, $orderItem, $receiptItem, $accepted, $receiver->id),
                    ]);
                    $expenseAmount += $accepted * (float) $orderItem->unit_cost * $orderItem->quantity_multiplier;
                }
            }

            if ($expenseAmount > 0) {
                $this->expenseApprovalService->submitProcurementExpense($receipt, $receiver, $expenseAmount);
            }

            $this->recalculatePurchaseOrder($purchaseOrder, $receiver->id, $receivedAt);

            return $receipt;
        });
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
            if ($purchaseOrder->status === 'completed') {
                throw ValidationException::withMessages(['receipt' => 'A receipt on a completed purchase order cannot be voided.']);
            }

            $expense = $receipt->expense()->lockForUpdate()->first();
            if ($expense && !in_array($expense->status, ['submitted', 'rejected'], true)) {
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

        $parentDelta = $accepted * $orderItem->quantity_multiplier;
        $sizeEffects = [];
        foreach ($sizes as $size) {
            $size->increment('quantity', $accepted);
            $sizeEffects[] = ['id' => $size->id, 'delta' => $accepted];
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
}
