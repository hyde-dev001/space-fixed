<?php

namespace App\Jobs;

use App\Events\LowStockAlert;
use App\Models\InventoryAlert;
use App\Models\InventoryItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckLowStockJob implements ShouldQueue
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
        Log::info("Starting low stock check for shop_owner_id: {$this->shopOwnerId}");

        // Get all active inventory items for the shop owner
        $inventoryItems = InventoryItem::where('shop_owner_id', $this->shopOwnerId)
            ->where('is_active', true)
            ->get();

        $lowStockCount = 0;
        $outOfStockCount = 0;

        foreach ($inventoryItems as $item) {
            $currentQuantity = $item->available_quantity;
            $reorderLevel = $item->reorder_level;

            // Check for out of stock
            if ($currentQuantity == 0) {
                $this->handleOutOfStock($item);
                $outOfStockCount++;
                continue;
            }

            // Check for low stock
            if ($currentQuantity > 0 && $currentQuantity <= $reorderLevel) {
                $this->handleLowStock($item, $currentQuantity, $reorderLevel);
                $lowStockCount++;
                continue;
            }

            // Resolve alerts if stock is back to normal
            $this->resolveAlertsIfNormal($item, $currentQuantity, $reorderLevel);
        }

        Log::info("Low stock check completed for shop_owner_id: {$this->shopOwnerId}", [
            'total_items' => $inventoryItems->count(),
            'low_stock' => $lowStockCount,
            'out_of_stock' => $outOfStockCount
        ]);
    }

    /**
     * Handle low stock situation
     */
    protected function handleLowStock(InventoryItem $item, int $currentQuantity, int $reorderLevel): void
    {
        // Check if alert already exists
        $existingAlert = InventoryAlert::where('inventory_item_id', $item->id)
            ->where('alert_type', 'low_stock')
            ->where('is_resolved', false)
            ->first();

        if (!$existingAlert) {
            // Create new alert
            InventoryAlert::create([
                'inventory_item_id' => $item->id,
                'alert_type' => 'low_stock',
                'threshold_value' => $reorderLevel,
                'current_value' => $currentQuantity,
                'is_resolved' => false,
            ]);

            // Fire event
            event(new LowStockAlert($item, $currentQuantity, $reorderLevel));

            Log::info("Low stock alert created for item: {$item->name} (SKU: {$item->sku})");
        }
    }

    /**
     * Handle out of stock situation
     */
    protected function handleOutOfStock(InventoryItem $item): void
    {
        // Check if alert already exists
        $existingAlert = InventoryAlert::where('inventory_item_id', $item->id)
            ->where('alert_type', 'out_of_stock')
            ->where('is_resolved', false)
            ->first();

        if (!$existingAlert) {
            // Create new alert
            InventoryAlert::create([
                'inventory_item_id' => $item->id,
                'alert_type' => 'out_of_stock',
                'threshold_value' => 0,
                'current_value' => 0,
                'is_resolved' => false,
            ]);

            // Fire event
            event(new \App\Events\OutOfStockAlert($item));

            Log::critical("Out of stock alert created for item: {$item->name} (SKU: {$item->sku})");
        }
    }

    /**
     * Resolve alerts if stock is back to normal
     */
    protected function resolveAlertsIfNormal(InventoryItem $item, int $currentQuantity, int $reorderLevel): void
    {
        // If stock is above reorder level, resolve any existing alerts
        if ($currentQuantity > $reorderLevel) {
            InventoryAlert::where('inventory_item_id', $item->id)
                ->whereIn('alert_type', ['low_stock', 'out_of_stock'])
                ->where('is_resolved', false)
                ->update([
                    'is_resolved' => true,
                    'resolved_at' => now(),
                    'resolved_by' => null, // Auto-resolved by system
                ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("CheckLowStockJob failed for shop_owner_id: {$this->shopOwnerId}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
