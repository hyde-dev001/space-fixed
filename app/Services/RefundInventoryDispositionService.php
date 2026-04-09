<?php

namespace App\Services;

use App\Models\OrderRefundItem;
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
                    $product->increment('stock_quantity', $qty);
                }

                $variantId = (int) ($line->product_variant_id ?? 0);
                if ($variantId > 0) {
                    $variant = ProductVariant::query()->lockForUpdate()->find($variantId);
                    if ($variant) {
                        $variant->increment('quantity', $qty);
                    }
                }
            }

            $line->inventory_action = $action;
            $line->inventory_applied_at = now();
            $line->save();

            Log::info('Refund line inventory action applied', [
                'channel' => $channel,
                'line_id' => $lineId,
                'product_id' => (int) ($line->product_id ?? 0),
                'qty' => $qty,
                'inspection_disposition' => $disposition,
                'inventory_action' => $action,
            ]);
         });
     }
}
