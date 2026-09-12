<?php

namespace App\Jobs;

use App\Events\LowStockAlert;
use App\Events\OutOfStockAlert;
use App\Services\InventoryReplenishmentService;
use App\Models\InventoryAlert;
use App\Models\InventoryItem;
use App\Services\StockRequestApprovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
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

    public function handle(): void
    {
        Log::info("Starting low stock check for shop_owner_id: {$this->shopOwnerId}");

        $inventoryItems = InventoryItem::query()
            ->where('shop_owner_id', $this->shopOwnerId)
            ->where('is_active', true)
            ->get();

        $lowStockCount = 0;
        $outOfStockCount = 0;
        $replenishment = app(InventoryReplenishmentService::class);

        foreach ($inventoryItems as $item) {
            foreach ($replenishment->effectiveTargets($item) as $target) {
                $currentQuantity = (int) $target['quantity'];
                $reorderLevel = (int) $target['reorder_level'];

                if ($currentQuantity <= 0) {
                    $this->handleOutOfStock($item, $target);
                    $this->createAutomaticStockRequestIfEnabled($item, 'high', $target);
                    $outOfStockCount++;
                    continue;
                }

                if ($currentQuantity <= $reorderLevel) {
                    $this->handleLowStock($item, $target);
                    $this->createAutomaticStockRequestIfEnabled($item, 'medium', $target);
                    $lowStockCount++;
                    continue;
                }

                $this->resolveAlertsIfNormal($item, $target);
            }
        }

        Log::info("Low stock check completed for shop_owner_id: {$this->shopOwnerId}", [
            'total_items' => $inventoryItems->count(),
            'low_stock' => $lowStockCount,
            'out_of_stock' => $outOfStockCount,
        ]);
    }

    /** @param array<string, mixed> $target */
    protected function handleLowStock(InventoryItem $item, array $target): void
    {
        $alert = DB::transaction(function () use ($item, $target): ?array {
            $lockedTarget = $this->lockTarget($item, $target);
            if (! $lockedTarget
                || (int) $lockedTarget['quantity'] <= 0
                || (int) $lockedTarget['quantity'] > (int) $lockedTarget['reorder_level']) {
                return null;
            }

            $this->unresolvedAlertQuery($item, 'out_of_stock', $lockedTarget)
                ->update([
                    'is_resolved' => true,
                    'resolved_at' => now(),
                    'resolved_by' => null,
                ]);

            $query = $this->unresolvedAlertQuery($item, 'low_stock', $lockedTarget);
            if ($query->exists()) {
                return null;
            }

            InventoryAlert::create([
                'inventory_item_id' => $item->id,
                'inventory_color_variant_id' => $lockedTarget['inventory_color_variant_id'],
                'inventory_size_id' => $lockedTarget['inventory_size_id'],
                'alert_type' => 'low_stock',
                'threshold_value' => $lockedTarget['reorder_level'],
                'current_value' => $lockedTarget['quantity'],
                'is_resolved' => false,
            ]);

            return [
                'target' => $lockedTarget,
                'current_quantity' => (int) $lockedTarget['quantity'],
                'reorder_level' => (int) $lockedTarget['reorder_level'],
            ];
        }, 3);

        if ($alert) {
            event(new LowStockAlert(
                $item->fresh(),
                $alert['current_quantity'],
                $alert['reorder_level'],
                $alert['target'],
            ));

            Log::info("Low stock alert created for item: {$item->name} (SKU: {$item->sku})", [
                'inventory_color_variant_id' => $alert['target']['inventory_color_variant_id'],
                'inventory_size_id' => $alert['target']['inventory_size_id'],
            ]);
        }
    }

    /** @param array<string, mixed> $target */
    protected function handleOutOfStock(InventoryItem $item, array $target): void
    {
        $alert = DB::transaction(function () use ($item, $target): ?array {
            $lockedTarget = $this->lockTarget($item, $target);
            if (! $lockedTarget || (int) $lockedTarget['quantity'] > 0) {
                return null;
            }

            $this->unresolvedAlertQuery($item, 'low_stock', $lockedTarget)
                ->update([
                    'is_resolved' => true,
                    'resolved_at' => now(),
                    'resolved_by' => null,
                ]);

            $query = $this->unresolvedAlertQuery($item, 'out_of_stock', $lockedTarget);
            if ($query->exists()) {
                return null;
            }

            InventoryAlert::create([
                'inventory_item_id' => $item->id,
                'inventory_color_variant_id' => $lockedTarget['inventory_color_variant_id'],
                'inventory_size_id' => $lockedTarget['inventory_size_id'],
                'alert_type' => 'out_of_stock',
                'threshold_value' => 0,
                'current_value' => 0,
                'is_resolved' => false,
            ]);

            return $lockedTarget;
        }, 3);

        if ($alert) {
            event(new OutOfStockAlert($item->fresh(), $alert));
            Log::critical("Out of stock alert created for item: {$item->name} (SKU: {$item->sku})", [
                'inventory_color_variant_id' => $alert['inventory_color_variant_id'],
                'inventory_size_id' => $alert['inventory_size_id'],
            ]);
        }
    }

    /** @param array<string, mixed> $target */
    protected function createAutomaticStockRequestIfEnabled(InventoryItem $item, string $priority, array $target): void
    {
        if (! $target['auto_stock_request_enabled']) {
            return;
        }

        app(StockRequestApprovalService::class)->createAutomaticStockRequest($item, $priority, $target);
    }

    /** @param array<string, mixed> $target */
    protected function resolveAlertsIfNormal(InventoryItem $item, array $target): void
    {
        DB::transaction(function () use ($item, $target): void {
            $lockedTarget = $this->lockTarget($item, $target);
            if (! $lockedTarget || (int) $lockedTarget['quantity'] <= (int) $lockedTarget['reorder_level']) {
                return;
            }

            foreach (['low_stock', 'out_of_stock'] as $alertType) {
                $this->unresolvedAlertQuery($item, $alertType, $lockedTarget)
                    ->update([
                        'is_resolved' => true,
                        'resolved_at' => now(),
                        'resolved_by' => null,
                    ]);
            }
        }, 3);
    }

    /** @param array<string, mixed> $target */
    private function lockTarget(InventoryItem $item, array $target): ?array
    {
        $lockedItem = InventoryItem::query()
            ->where('shop_owner_id', $item->shop_owner_id)
            ->whereKey($item->id)
            ->lockForUpdate()
            ->first();
        if (! $lockedItem) {
            return null;
        }

        $type = (string) ($target['type'] ?? '');
        $id = (int) ($target['id'] ?? 0);
        if ($type === 'item' && $id !== (int) $lockedItem->id) {
            return null;
        }

        if ($type === 'color') {
            $color = $lockedItem->colorVariants()->whereKey($id)->lockForUpdate()->first();
            if (! $color || $color->sizes()->exists()) {
                return null;
            }
        }

        if ($type === 'size' && ! $lockedItem->sizes()->whereKey($id)->lockForUpdate()->first()) {
            return null;
        }

        if (! in_array($type, ['item', 'color', 'size'], true)) {
            return null;
        }

        $lockedItem->unsetRelation('sizes')->unsetRelation('colorVariants');

        return app(InventoryReplenishmentService::class)
            ->effectiveTargets($lockedItem)
            ->first(fn (array $candidate): bool => $candidate['type'] === $type && (int) $candidate['id'] === $id);
    }

    /** @param array<string, mixed> $target */
    private function unresolvedAlertQuery(InventoryItem $item, string $alertType, array $target)
    {
        $query = InventoryAlert::query()
            ->where('inventory_item_id', $item->id)
            ->where('alert_type', $alertType)
            ->where('is_resolved', false);

        foreach (['inventory_color_variant_id', 'inventory_size_id'] as $key) {
            $target[$key] === null
                ? $query->whereNull($key)
                : $query->where($key, $target[$key]);
        }

        return $query;
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
