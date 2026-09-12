<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('purchase_requests')
                ->where('unit_cost', '>', 0)
                ->where('total_cost', '>', 0)
                ->orderBy('id')
                ->chunkById(200, function ($requests): void {
                    foreach ($requests as $request) {
                        $unitCost = (float) $request->unit_cost;
                        $totalCost = (float) $request->total_cost;
                        $totalUnits = (int) round($totalCost / $unitCost);

                        if ($totalUnits <= (int) $request->quantity
                            || abs(($totalUnits * $unitCost) - $totalCost) > 0.01) {
                            continue;
                        }

                        DB::table('purchase_requests')->where('id', $request->id)->update(['quantity' => $totalUnits]);
                        if ($request->stock_request_id) {
                            DB::table('stock_request_approvals')
                                ->where('id', $request->stock_request_id)
                                ->where('quantity_needed', '<', $totalUnits)
                                ->update(['quantity_needed' => $totalUnits]);
                        }
                    }
                });

            $purchaseOrderIds = [];
            DB::table('purchase_order_items')
                ->where('quantity_multiplier', '>', 1)
                ->orderBy('id')
                ->chunkById(200, function ($items) use (&$purchaseOrderIds): void {
                    foreach ($items as $item) {
                        $multiplier = (int) $item->quantity_multiplier;
                        $purchaseOrderIds[(int) $item->purchase_order_id] = true;

                        DB::table('purchase_order_items')->where('id', $item->id)->update([
                            'ordered_quantity' => (int) $item->ordered_quantity * $multiplier,
                            'quantity_multiplier' => 1,
                        ]);
                        DB::table('purchase_order_receipt_items')
                            ->where('purchase_order_item_id', $item->id)
                            ->orderBy('id')
                            ->get()
                            ->each(function ($receiptItem) use ($multiplier): void {
                                DB::table('purchase_order_receipt_items')->where('id', $receiptItem->id)->update([
                                    'received_quantity' => (int) $receiptItem->received_quantity * $multiplier,
                                    'defective_quantity' => (int) $receiptItem->defective_quantity * $multiplier,
                                    'accepted_quantity' => (int) $receiptItem->accepted_quantity * $multiplier,
                                ]);
                            });
                    }
                });

            foreach (array_keys($purchaseOrderIds) as $purchaseOrderId) {
                $posted = DB::table('purchase_order_receipt_items as items')
                    ->join('purchase_order_receipts as receipts', 'receipts.id', '=', 'items.purchase_order_receipt_id')
                    ->where('receipts.purchase_order_id', $purchaseOrderId)
                    ->where('receipts.status', 'posted');

                DB::table('purchase_orders')->where('id', $purchaseOrderId)->update([
                    'quantity' => DB::table('purchase_order_items')->where('purchase_order_id', $purchaseOrderId)->sum('ordered_quantity'),
                    'received_quantity' => (clone $posted)->sum('items.received_quantity'),
                    'defective_quantity' => (clone $posted)->sum('items.defective_quantity'),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Corrective data rewrite: reversing it would corrupt records created after deployment.
    }
};
