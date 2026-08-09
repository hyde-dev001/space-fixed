<?php

namespace App\Jobs;

use App\Events\LowStockAlert;
use App\Models\InventoryAlert;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\ReplenishmentRequest;
use App\Models\User;
use App\Services\ReplenishmentRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckLowStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const OPEN_REPLENISHMENT_STATUSES = ['pending', 'accepted', 'needs_details'];

    private const OPEN_PURCHASE_REQUEST_STATUSES = [
        'draft',
        'pending_finance',
        'pending_shop_owner',
        'pending_finance_final',
        'approved',
    ];

    private const OPEN_PURCHASE_ORDER_STATUSES = [
        'draft',
        'sent',
        'confirmed',
        'in_transit',
        'partially_received',
    ];

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
                $this->createReplenishmentRequestIfNeeded($item, 'high');
                $outOfStockCount++;
                continue;
            }

            // Check for low stock
            if ($currentQuantity > 0 && $currentQuantity <= $reorderLevel) {
                $this->handleLowStock($item, $currentQuantity, $reorderLevel);
                $this->createReplenishmentRequestIfNeeded($item, 'medium');
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
     * Create only the portion of the reorder quantity that is not already covered.
     */
    protected function createReplenishmentRequestIfNeeded(InventoryItem $item, string $priority): void
    {
        $reorderQuantity = max(0, (int) $item->reorder_quantity);
        if ($reorderQuantity === 0) {
            return;
        }

        $coveredQuantity = $this->openReplenishmentQuantity($item)
            + $this->openPurchaseRequestQuantity($item)
            + $this->openPurchaseOrderQuantity($item);
        $quantityNeeded = max(0, $reorderQuantity - $coveredQuantity);

        if ($quantityNeeded === 0) {
            return;
        }

        $requestedBy = User::query()
            ->where('shop_owner_id', $item->shop_owner_id)
            ->orderBy('id')
            ->value('id');

        if (!$requestedBy) {
            Log::warning('Low-stock replenishment request skipped because the shop has no user actor.', [
                'shop_owner_id' => $item->shop_owner_id,
                'inventory_item_id' => $item->id,
            ]);

            return;
        }

        $request = app(ReplenishmentRequestService::class)->createReplenishmentRequest([
            'shop_owner_id' => $item->shop_owner_id,
            'inventory_item_id' => $item->id,
            'product_name' => $item->name,
            'sku_code' => $item->sku,
            'quantity_needed' => $quantityNeeded,
            'priority' => $priority,
            'status' => 'pending',
            'requested_by' => $requestedBy,
            'requested_date' => now(),
            'notes' => 'Automatically created by the low-stock check.',
        ]);

        Log::info('Low-stock replenishment request created.', [
            'request_id' => $request->id,
            'inventory_item_id' => $item->id,
            'quantity_needed' => $quantityNeeded,
            'covered_quantity' => $coveredQuantity,
        ]);
    }

    protected function openReplenishmentQuantity(InventoryItem $item): int
    {
        return (int) ReplenishmentRequest::query()
            ->where('shop_owner_id', $item->shop_owner_id)
            ->where('inventory_item_id', $item->id)
            ->whereIn('status', self::OPEN_REPLENISHMENT_STATUSES)
            ->sum('quantity_needed');
    }

    protected function openPurchaseRequestQuantity(InventoryItem $item): int
    {
        return (int) PurchaseRequest::query()
            ->where('shop_owner_id', $item->shop_owner_id)
            ->where('inventory_item_id', $item->id)
            ->whereIn('status', self::OPEN_PURCHASE_REQUEST_STATUSES)
            ->whereDoesntHave('purchaseOrders', function ($query) {
                $query->whereIn('status', self::OPEN_PURCHASE_ORDER_STATUSES);
            })
            ->whereDoesntHave('purchaseOrderItems', function ($query) {
                $query->whereHas('purchaseOrder', function ($purchaseOrderQuery) {
                    $purchaseOrderQuery->whereIn('status', self::OPEN_PURCHASE_ORDER_STATUSES);
                });
            })
            ->sum('quantity');
    }

    protected function openPurchaseOrderQuantity(InventoryItem $item): int
    {
        $itemQuantity = PurchaseOrderItem::query()
            ->with('receiptItems.receipt')
            ->where('inventory_item_id', $item->id)
            ->whereHas('purchaseOrder', function ($query) use ($item) {
                $query->where('shop_owner_id', $item->shop_owner_id)
                    ->whereIn('status', self::OPEN_PURCHASE_ORDER_STATUSES);
            })
            ->get()
            ->sum(function (PurchaseOrderItem $purchaseOrderItem): int {
                $acceptedQuantity = $purchaseOrderItem->receiptItems
                    ->filter(fn ($receiptItem) => $receiptItem->receipt?->status === 'posted')
                    ->sum('accepted_quantity');

                return max(0, (int) $purchaseOrderItem->ordered_quantity - (int) $acceptedQuantity);
            });

        $legacyQuantity = PurchaseOrder::query()
            ->where('shop_owner_id', $item->shop_owner_id)
            ->where('inventory_item_id', $item->id)
            ->whereIn('status', self::OPEN_PURCHASE_ORDER_STATUSES)
            ->whereDoesntHave('items')
            ->get(['quantity', 'received_quantity', 'defective_quantity'])
            ->sum(function (PurchaseOrder $purchaseOrder): int {
                $receivedQuantity = (int) ($purchaseOrder->received_quantity ?? 0);
                $defectiveQuantity = (int) ($purchaseOrder->defective_quantity ?? 0);
                $acceptedQuantity = max(0, $receivedQuantity - $defectiveQuantity);

                return max(0, (int) $purchaseOrder->quantity - $acceptedQuantity);
            });

        return (int) $itemQuantity + (int) $legacyQuantity;
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
