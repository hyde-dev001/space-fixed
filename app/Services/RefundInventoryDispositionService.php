<?php

namespace App\Services;

use App\Models\OrderRefundItem;
use App\Models\InventorySize;
use App\Models\PosRefundItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefundInventoryDispositionService
{
    public function applyPosLine(PosRefundItem $line): void
    {
        $this->applyLine(
            lineId: (int) $line->id,
            modelClass: PosRefundItem::class,
            channel: 'retail_pos',
        );
    }

    public function applyOrderLine(OrderRefundItem $line): void
    {
        $this->applyLine(
            lineId: (int) $line->id,
            modelClass: OrderRefundItem::class,
            channel: 'online_order',
        );
    }

    private function applyLine(int $lineId, string $modelClass, string $channel): void
    {
        DB::transaction(function () use ($lineId, $modelClass, $channel) {
            /** @var OrderRefundItem|PosRefundItem|null $line */
            $line = $modelClass::query()->lockForUpdate()->find($lineId);
            if (!$line) {
                return;
            }

            if ($line->inventory_applied_at !== null) {
                return;
            }

            $qty = (int) ($line->approved_qty ?? $line->requested_qty ?? 0);
            if ($qty <= 0) {
                $line->inventory_action = 'write_off';
                $line->inventory_applied_at = now();
                $line->save();
                return;
            }

            $disposition = strtolower(trim((string) ($line->inspection_disposition ?? 'pending')));
            $action = $disposition === 'resellable' ? 'restock' : 'write_off';

            if ($action === 'restock') {
                $product = Product::query()->lockForUpdate()->find((int) $line->product_id);
                if ($product) {
                    $this->restoreLine($line, $product, $qty);
                }
            }

            $line->inventory_action = $action;
            $line->inventory_applied_at = now();
            $line->save();

            $orderRefundId = (int) ($line->order_refund_id ?? 0);
            $posRefundId = (int) ($line->pos_refund_id ?? 0);

            Log::info('Refund line inventory action applied', [
                'channel' => $channel,
                'refund_id' => $orderRefundId > 0 ? $orderRefundId : ($posRefundId > 0 ? $posRefundId : null),
                'refund_table' => $orderRefundId > 0 ? 'order_refunds' : ($posRefundId > 0 ? 'pos_refunds' : null),
                'line_id' => $lineId,
                'order_item_id' => (int) ($line->order_item_id ?? 0),
                'product_id' => (int) ($line->product_id ?? 0),
                'qty' => $qty,
                'inspection_disposition' => $disposition,
                'inventory_action' => $action,
            ]);
        });
    }

    private function restoreLine(OrderRefundItem|PosRefundItem $line, Product $product, int $qty): void
    {
        $orderItem = $line->orderItem;
        $selection = app(InventoryCheckoutService::class)->resolveSelection($product, [
            'variant_id' => $line->product_variant_id,
            'size' => $orderItem?->size,
            'color' => $orderItem?->color,
        ], true);

        $inventoryItem = $selection['inventory_item'];
        $variantId = (int) ($line->product_variant_id ?? 0);
        if (! $inventoryItem) {
            $product->increment('stock_quantity', $qty);

            if ($variantId > 0) {
                ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->whereKey($variantId)
                    ->lockForUpdate()
                    ->first()
                    ?->increment('quantity', $qty);
            }

            return;
        }

        $color = $selection['color'];
        $size = $selection['size'];
        if ($size) {
            $size->increment('quantity', $qty);
            if ($color) {
                $color->update([
                    'quantity' => InventorySize::query()
                        ->where('inventory_item_id', $inventoryItem->id)
                        ->where('inventory_color_variant_id', $color->id)
                        ->sum('quantity'),
                ]);
            }
        } elseif ($color) {
            $color->increment('quantity', $qty);
        }

        $movement = app(StockMovementService::class)->recordMovement([
            'inventory_item_id' => $inventoryItem->id,
            'movement_type' => 'return',
            'quantity_change' => $qty,
            'reference_type' => $line instanceof PosRefundItem ? 'pos_refund_item' : 'order_refund_item',
            'reference_id' => $line->id,
            'notes' => $line instanceof PosRefundItem
                ? "POS refund {$line->pos_refund_id} restocked item {$line->id}."
                : "Retail return for refund {$line->order_refund_id} restocked item {$line->id}.",
        ]);

        $product->update(['stock_quantity' => (int) $movement->quantity_after]);
        if ($line instanceof OrderRefundItem && $variantId > 0) {
            ProductVariant::query()
                ->where('product_id', $product->id)
                ->whereKey($variantId)
                ->lockForUpdate()
                ->first()
                ?->increment('quantity', $qty);
        }
    }
}
