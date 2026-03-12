<?php

namespace App\Listeners;

use App\Events\InventoryItemUpdated;
use App\Models\StockMovement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CreateStockMovement implements ShouldQueue
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
    public function handle(InventoryItemUpdated $event): void
    {
        $inventoryItem = $event->inventoryItem;
        $changes = $event->changes;

        // Only create movement if quantity changed
        if (!isset($changes['available_quantity'])) {
            return;
        }

        $oldQuantity = $changes['available_quantity']['old'] ?? 0;
        $newQuantity = $changes['available_quantity']['new'] ?? 0;
        $quantityChange = $newQuantity - $oldQuantity;

        if ($quantityChange == 0) {
            return;
        }

        try {
            // Determine movement type
            $movementType = $quantityChange > 0 ? 'stock_in' : 'stock_out';

            // Create stock movement record
            StockMovement::create([
                'inventory_item_id' => $inventoryItem->id,
                'movement_type' => $movementType,
                'quantity_change' => $quantityChange,
                'quantity_before' => $oldQuantity,
                'quantity_after' => $newQuantity,
                'reference_type' => 'manual',
                'notes' => 'Auto-generated from inventory update',
                'performed_by' => auth()->id(),
                'performed_at' => now(),
            ]);

            Log::info("Stock movement created for inventory item: {$inventoryItem->name} (SKU: {$inventoryItem->sku}), change: {$quantityChange}");

        } catch (\Exception $e) {
            Log::error("Failed to create stock movement for inventory item ID: {$inventoryItem->id}", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(InventoryItemUpdated $event, \Throwable $exception): void
    {
        Log::error("Failed to create stock movement for inventory item ID: {$event->inventoryItem->id}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
