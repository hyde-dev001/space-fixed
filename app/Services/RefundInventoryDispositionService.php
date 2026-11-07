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
    public function applyRecoveredParcel(\App\Models\Logistics\DeliveryIncident $incident, \App\Models\User $actor): array
    {
        return DB::transaction(function () use ($incident, $actor) {
            \App\Models\ShopOwner::whereKey($incident->shop_owner_id)->lockForUpdate()->firstOrFail();
            $incident = \App\Models\Logistics\DeliveryIncident::with('leg.shipment')->lockForUpdate()->findOrFail($incident->id);
            app(\App\Services\Logistics\LogisticsActorPolicy::class)->assertParcelRecoveryAttendance($actor);
            abort_unless((int) $actor->shop_owner_id === (int) $incident->shop_owner_id
                && ($actor->can('access-staff-job-orders') || $actor->can('resolve-logistics-exceptions')), 403);
            $context = $incident->recovery_context ?? [];
            if ($prior = $context['inventory_disposition'] ?? null) return $prior;
            $shipment = $incident->leg->shipment;
            if ((! data_get($context, 'recovered_loss') && $incident->resolution !== 'return_required') || ! data_get($context, 'receipt') || ! data_get($context, 'inspection.items')
                || $shipment->source_type !== 'order' || $shipment->purpose !== 'retail_delivery') {
                throw \Illuminate\Validation\ValidationException::withMessages(['inventory' => 'Only physically received and inspected retail goods can enter inventory disposition.']);
            }
            $return = \App\Models\Logistics\ShipmentLeg::where('shipment_id', $shipment->id)
                ->where(fn ($q) => $q->where('return_for_leg_id', $incident->shipment_leg_id)->orWhere('id', $incident->shipment_leg_id))
                ->lockForUpdate()->findOrFail($context['receipt']['return_leg_id']);
            if ($return->custody_state !== 'shop' || ! $return->custody_confirmed_at) {
                throw \Illuminate\Validation\ValidationException::withMessages(['receipt' => 'Actual shop custody is required.']);
            }
            $order = \App\Models\Order::where('shop_owner_id', $actor->shop_owner_id)->lockForUpdate()->findOrFail($shipment->source_id);
            $items = $order->items()->lockForUpdate()->get()->keyBy(fn ($item) => 'order-item:'.$item->id);
            $actual = collect($context['inspection']['items']);
            if ($actual->pluck('reference')->unique()->count() !== $actual->count() || $actual->count() !== $items->count()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['inventory' => 'Inspect each original item exactly once.']);
            }
            $activeAllocation = \App\Models\Logistics\Shipment::where('shop_owner_id', $actor->shop_owner_id)
                ->where('source_type', 'order')->where('source_id', $order->id)->where('purpose', 'retail_delivery')
                ->whereHas('legs', fn ($legs) => $legs->whereNull('return_for_leg_id')->whereNotIn('status', ['cancelled', 'delivered']))->exists();
            $previousRecoveries = \App\Models\Logistics\DeliveryIncident::where('shop_owner_id', $actor->shop_owner_id)
                ->whereHas('leg.shipment', fn ($sources) => $sources->where('source_type', 'order')->where('source_id', $order->id))
                ->whereNotNull('recovery_context->inventory_disposition')->get();
            $results = [];
            foreach ($actual as $row) {
                $item = $items->get($row['reference']);
                if (! $item || (int) $row['quantity'] !== (int) $item->quantity || ! in_array($row['condition'], ['safe', 'damaged', 'unsafe'], true)) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['inventory' => 'Partial or unidentified recovery requires the existing authorized exception disposition.']);
                }
                $restocked = (int) OrderRefundItem::where('order_item_id', $item->id)->where('inventory_action', 'restock')
                    ->whereNotNull('inventory_applied_at')->sum('approved_qty');
                foreach ($previousRecoveries as $prior) {
                    $restocked += (int) data_get($prior->recovery_context, 'inventory_disposition.items.'.$item->id.'.restocked_quantity', 0);
                }
                $qty = max(0, min((int) $row['quantity'], (int) $item->quantity - $restocked));
                if ($row['condition'] === 'safe' && $activeAllocation) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['inventory' => 'Goods remain allocated to active fulfillment.']);
                }
                $action = $row['condition'] === 'safe' ? ($qty > 0 ? 'restock' : 'already_restocked') : 'write_off';
                if ($action === 'restock') {
                    $product = Product::where('shop_owner_id', $actor->shop_owner_id)->lockForUpdate()->findOrFail($item->product_id);
                    $this->restoreItem($item, $product, $qty, 'parcel_recovery', $incident->id, $actor->id);
                }
                $results[$item->id] = ['quantity' => (int) $row['quantity'], 'action' => $action,
                    'restocked_quantity' => $action === 'restock' ? $qty : 0];
            }
            $result = ['items' => $results, 'actor_id' => $actor->id, 'recorded_at' => now()->toISOString()];
            $incident->forceFill(['recovery_context' => [...$context, 'inventory_disposition' => $result]])->save();
            return $result;
        });
    }

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
            $orderItem = $modelClass === OrderRefundItem::class ? OrderRefundItem::find($lineId)?->orderItem : null;
            if ($orderItem) {
                \App\Models\Order::whereKey($orderItem->order_id)->lockForUpdate()->firstOrFail();
                $orderItem = \App\Models\OrderItem::whereKey($orderItem->id)->lockForUpdate()->firstOrFail();
            }
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

            if ($action === 'restock' && $orderItem) {
                $restocked = (int) OrderRefundItem::where('order_item_id', $orderItem->id)->where('id', '!=', $line->id)
                    ->where('inventory_action', 'restock')->whereNotNull('inventory_applied_at')->sum('approved_qty');
                $recoveries = \App\Models\Logistics\DeliveryIncident::where('shop_owner_id', $orderItem->order->shop_owner_id)
                    ->whereHas('leg.shipment', fn ($query) => $query->where('source_type', 'order')->where('source_id', $orderItem->order_id))
                    ->whereNotNull('recovery_context->inventory_disposition')->get();
                foreach ($recoveries as $recovery) {
                    $restocked += (int) data_get($recovery->recovery_context, 'inventory_disposition.items.'.$orderItem->id.'.restocked_quantity', 0);
                }
                // Physical recovery may finish before the independently pending refund.
                if ($recoveries->isNotEmpty()) $qty = max(0, min($qty, (int) $orderItem->quantity - $restocked));
            }

            if ($action === 'restock' && $qty > 0) {
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
        $this->restoreItem($orderItem, $product, $qty, $line instanceof PosRefundItem ? 'pos_refund_item' : 'order_refund_item',
            $line->id, null, $line->product_variant_id);
    }

    private function restoreItem(?\App\Models\OrderItem $orderItem, Product $product, int $qty, string $referenceType, int $referenceId,
        ?int $actorId = null, ?int $productVariantId = null): void
    {
        $productVariantId ??= $orderItem?->product_variant_id;
        $selection = app(InventoryCheckoutService::class)->resolveSelection($product, [
            'variant_id' => $productVariantId,
            'size' => $orderItem?->size,
            'color' => $orderItem?->color,
        ], true);

        $inventoryItem = $selection['inventory_item'];
        $variantId = (int) ($productVariantId ?? 0);
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
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'performed_by' => $actorId,
            'notes' => "Authorized {$referenceType} {$referenceId} returned quantity {$qty}.",
        ]);

        $product->update(['stock_quantity' => (int) $movement->quantity_after]);
        if ($referenceType !== 'pos_refund_item' && $variantId > 0) {
            ProductVariant::query()
                ->where('product_id', $product->id)
                ->whereKey($variantId)
                ->lockForUpdate()
                ->first()
                ?->increment('quantity', $qty);
        }
    }
}
