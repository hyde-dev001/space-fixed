<?php

namespace App\Jobs;

use App\Models\InventoryItem;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncInventoryWithProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $shopOwnerId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $shopOwnerId)
    {
        $this->shopOwnerId = $shopOwnerId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Starting inventory-product sync for shop_owner_id: {$this->shopOwnerId}");

        try {
            DB::beginTransaction();

            // Get all inventory items linked to products
            $inventoryItems = InventoryItem::where('shop_owner_id', $this->shopOwnerId)
                ->whereNotNull('product_id')
                ->get();

            $syncedCount = 0;
            $errorCount = 0;

            foreach ($inventoryItems as $item) {
                try {
                    $product = Product::lockForUpdate()->find($item->product_id);

                    if (!$product) {
                        Log::warning("Product not found for inventory item: {$item->id}, unlinking product_id");
                        $item->product_id = null;
                        $item->save();
                        continue;
                    }

                    // Sync stock quantity
                    if ($product->stock != $item->available_quantity) {
                        $oldStock = $product->stock;
                        $product->stock = $item->available_quantity;
                        $product->save();

                        Log::info("Synced product stock: Product ID {$product->id}, {$oldStock} -> {$item->available_quantity}");
                        $syncedCount++;
                    }

                } catch (\Exception $e) {
                    Log::error("Failed to sync inventory item ID: {$item->id}", [
                        'error' => $e->getMessage()
                    ]);
                    $errorCount++;
                }
            }

            DB::commit();

            Log::info("Inventory-product sync completed for shop_owner_id: {$this->shopOwnerId}", [
                'total_items' => $inventoryItems->count(),
                'synced' => $syncedCount,
                'errors' => $errorCount
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Inventory-product sync failed for shop_owner_id: {$this->shopOwnerId}", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("SyncInventoryWithProductsJob failed for shop_owner_id: {$this->shopOwnerId}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
