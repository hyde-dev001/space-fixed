<?php

namespace App\Listeners;

use App\Events\PurchaseOrderDelivered;
use App\Models\StockMovement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateStockMovementOnDelivery implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(PurchaseOrderDelivered $event): void
    {
        try {
            $purchaseOrder = $event->purchaseOrder;

            if ($purchaseOrder->inventory_item_id) {
                DB::beginTransaction();

                // Create stock movement record
                StockMovement::create([
                    'shop_owner_id' => $purchaseOrder->shop_owner_id,
                    'inventory_item_id' => $purchaseOrder->inventory_item_id,
                    'movement_type' => 'in',
                    'quantity' => $purchaseOrder->quantity,
                    'reference_type' => 'purchase_order',
                    'reference_id' => $purchaseOrder->id,
                    'reference_number' => $purchaseOrder->po_number,
                    'movement_date' => $purchaseOrder->actual_delivery_date ?? now(),
                    'performed_by' => $purchaseOrder->delivered_by,
                    'notes' => "Stock in from PO: {$purchaseOrder->po_number}",
                    'unit_cost' => $purchaseOrder->unit_cost,
                    'total_cost' => $purchaseOrder->total_cost,
                ]);

                Log::info('Stock movement created for PO delivery', [
                    'po_id' => $purchaseOrder->id,
                    'po_number' => $purchaseOrder->po_number,
                    'quantity' => $purchaseOrder->quantity,
                ]);

                DB::commit();
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create stock movement on PO delivery', [
                'po_id' => $event->purchaseOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
