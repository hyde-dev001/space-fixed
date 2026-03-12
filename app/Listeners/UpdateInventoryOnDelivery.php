<?php

namespace App\Listeners;

use App\Events\PurchaseOrderDelivered;
use App\Models\InventoryItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateInventoryOnDelivery implements ShouldQueue
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

                $inventoryItem = InventoryItem::find($purchaseOrder->inventory_item_id);
                
                if ($inventoryItem) {
                    // Update stock quantity
                    $oldQuantity = $inventoryItem->stock_quantity;
                    $inventoryItem->stock_quantity += $purchaseOrder->quantity;
                    $inventoryItem->last_restock_date = $purchaseOrder->actual_delivery_date ?? now();
                    $inventoryItem->save();

                    Log::info('Inventory updated on PO delivery', [
                        'po_id' => $purchaseOrder->id,
                        'po_number' => $purchaseOrder->po_number,
                        'inventory_item_id' => $inventoryItem->id,
                        'old_quantity' => $oldQuantity,
                        'added_quantity' => $purchaseOrder->quantity,
                        'new_quantity' => $inventoryItem->stock_quantity,
                    ]);
                }

                DB::commit();
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update inventory on PO delivery', [
                'po_id' => $event->purchaseOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
