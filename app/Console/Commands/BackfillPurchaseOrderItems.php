<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillPurchaseOrderItems extends Command
{
    protected $signature = 'procurement:backfill-purchase-orders {--dry-run}';

    protected $description = 'Backfill canonical item and migration receipt rows for legacy purchase orders';

    private int $orders = 0;
    private int $items = 0;
    private int $receipts = 0;
    private int $unresolved = 0;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->comment('Running procurement backfill in dry-run mode; no rows will be changed.');
        }

        PurchaseOrder::query()
            ->with('inventoryItem')
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($dryRun): void {
                foreach ($orders as $purchaseOrder) {
                    $this->backfill($purchaseOrder, $dryRun);
                }
            });

        $this->info(sprintf(
            'Purchase orders=%d, items=%d, receipts=%d, unresolved=%d%s',
            $this->orders,
            $this->items,
            $this->receipts,
            $this->unresolved,
            $dryRun ? ' (dry-run)' : ''
        ));

        return $this->unresolved > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function backfill(PurchaseOrder $purchaseOrder, bool $dryRun): void
    {
        $existing = $purchaseOrder->items()->where('source', 'migration')->first();
        if (!$existing && $purchaseOrder->items()->exists()) {
            return;
        }

        $this->orders++;
        $snapshot = PurchaseOrderItem::snapshotSizeTargets(
            $purchaseOrder->inventoryItem,
            $purchaseOrder->requested_size,
            $purchaseOrder->requested_color
        );
        $eligibleSizeIds = $snapshot['eligible_size_ids'];
        $terminal = in_array($purchaseOrder->status, ['delivered', 'completed', 'cancelled'], true);
        $allSizes = blank($purchaseOrder->requested_size) && $eligibleSizeIds !== [];
        $unitCost = (float) $purchaseOrder->unit_cost;
        $derivedQuantity = $unitCost > 0 ? (int) round((float) $purchaseOrder->total_cost / $unitCost) : (int) $purchaseOrder->quantity;
        $totalMatchesUnits = abs(($derivedQuantity * $unitCost) - (float) $purchaseOrder->total_cost) <= 0.009;
        $orderedQuantity = !$terminal && $allSizes && $totalMatchesUnits
            ? max((int) $purchaseOrder->quantity, $derivedQuantity)
            : (int) $purchaseOrder->quantity;

        if (!$terminal && $allSizes && !$totalMatchesUnits) {
            $this->unresolved++;
            $this->warn("PO {$purchaseOrder->po_number} has an all-size total that does not match its current size snapshot.");
            return;
        }

        if (!$terminal && blank($purchaseOrder->requested_size)
            && $purchaseOrder->inventoryItem?->category === 'shoes' && $eligibleSizeIds === []) {
            $this->unresolved++;
            $this->warn("PO {$purchaseOrder->po_number} has no eligible size rows to snapshot.");
            return;
        }

        $this->items += $existing ? 0 : 1;
        $hasReceipt = ($purchaseOrder->received_quantity ?? 0) > 0 || ($purchaseOrder->defective_quantity ?? 0) > 0;
        $receiptExists = $purchaseOrder->receipts()->where('idempotency_key', "migration-po-{$purchaseOrder->id}")->exists();
        $this->receipts += $hasReceipt && !$receiptExists ? 1 : 0;

        if ($dryRun) {
            return;
        }

        DB::transaction(function () use ($purchaseOrder, $eligibleSizeIds, $orderedQuantity, $terminal, $hasReceipt): void {
            if (!$terminal && $purchaseOrder->quantity !== $orderedQuantity) {
                $purchaseOrder->update(['quantity' => $orderedQuantity]);
            }
            $item = PurchaseOrderItem::firstOrCreate(
                ['purchase_order_id' => $purchaseOrder->id, 'source' => 'migration'],
                [
                    'purchase_request_id' => $purchaseOrder->pr_id,
                    'inventory_item_id' => $purchaseOrder->inventory_item_id,
                    'product_name' => $purchaseOrder->product_name,
                    'requested_size' => $purchaseOrder->requested_size,
                    'requested_color' => $purchaseOrder->requested_color,
                    'ordered_quantity' => $orderedQuantity,
                    'unit_cost' => $purchaseOrder->unit_cost,
                    'line_total' => $purchaseOrder->total_cost,
                    'quantity_multiplier' => 1,
                    'eligible_size_ids' => $eligibleSizeIds,
                ]
            );

            if ($terminal && !$purchaseOrder->is_historical) {
                $purchaseOrder->update(['is_historical' => true]);
            }

            if (!$hasReceipt) {
                return;
            }

            $received = max(0, (int) $purchaseOrder->received_quantity);
            $defective = max(0, (int) $purchaseOrder->defective_quantity);
            $receipt = PurchaseOrderReceipt::firstOrCreate(
                [
                    'purchase_order_id' => $purchaseOrder->id,
                    'idempotency_key' => "migration-po-{$purchaseOrder->id}",
                ],
                [
                    'shop_owner_id' => $purchaseOrder->shop_owner_id,
                    'source' => 'migration',
                    'status' => 'posted',
                    'received_by' => $purchaseOrder->delivered_by ?: $purchaseOrder->ordered_by,
                    'received_at' => $purchaseOrder->delivered_date ?: $purchaseOrder->updated_at,
                    'notes' => 'Backfilled from legacy purchase-order receipt totals.',
                ]
            );

            $receipt->items()->firstOrCreate(
                ['purchase_order_item_id' => $item->id],
                [
                    'received_quantity' => $received,
                    'defective_quantity' => $defective,
                    'accepted_quantity' => max(0, $received - $defective),
                    'inventory_effects' => [],
                ]
            );

            if (!$terminal) {
                $accepted = max(0, $received - $defective);
                $purchaseOrder->update([
                    'status' => $accepted >= $item->ordered_quantity ? 'delivered' : 'partially_received',
                ]);
            }
        });
    }

}
