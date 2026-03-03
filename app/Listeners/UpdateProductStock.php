<?php

namespace App\Listeners;

use App\Events\StockMovementRecorded;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateProductStock implements ShouldQueue
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
    public function handle(StockMovementRecorded $event): void
    {
        $stockMovement = $event->stockMovement;
        $inventoryItem = $stockMovement->inventoryItem;

        // Only sync if inventory item is linked to a product
        if (!$inventoryItem->product_id) {
            return;
        }

        try {
            DB::beginTransaction();

            $product = Product::lockForUpdate()->find($inventoryItem->product_id);

            if (!$product) {
                Log::warning("Product not found for inventory item: {$inventoryItem->id}");
                DB::rollBack();
                return;
            }

            // Update product stock quantity
            $product->stock = $inventoryItem->available_quantity;
            $product->save();

            DB::commit();

            Log::info("Product stock updated for product ID: {$product->id}, new stock: {$product->stock}");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to update product stock for inventory item ID: {$inventoryItem->id}", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(StockMovementRecorded $event, \Throwable $exception): void
    {
        Log::error("Failed to update product stock for movement ID: {$event->stockMovement->id}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
