<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryAlert;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InventoryService
{
    /**
     * Get dashboard metrics for a shop owner
     */
    public function getDashboardMetrics($shopOwnerId)
    {
        $totalItems = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->count();
        
        $lowStockItems = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->lowStock()
            ->count();
        
        $outOfStockItems = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->outOfStock()
            ->count();
        
        $totalValue = $this->calculateStockValue($shopOwnerId);
        
        $stockInToday = StockMovement::whereHas('inventoryItem', function ($query) use ($shopOwnerId) {
                $query->where('shop_owner_id', $shopOwnerId);
            })
            ->whereIn('movement_type', ['stock_in', 'return'])
            ->whereDate('performed_at', today())
            ->sum('quantity_change');
        
        $stockOutToday = StockMovement::whereHas('inventoryItem', function ($query) use ($shopOwnerId) {
                $query->where('shop_owner_id', $shopOwnerId);
            })
            ->whereIn('movement_type', ['stock_out', 'repair_usage', 'damage'])
            ->whereDate('performed_at', today())
            ->sum('quantity_change');
        
        return [
            'total_items' => $totalItems,
            'total_value' => round($totalValue, 2),
            'low_stock_count' => $lowStockItems,
            'out_of_stock_count' => $outOfStockItems,
            'stock_in_today' => abs($stockInToday),
            'stock_out_today' => abs($stockOutToday),
            'in_stock_count' => $totalItems - $lowStockItems - $outOfStockItems,
        ];
    }
    
    /**
     * Get stock levels chart data for visualization
     */
    public function getStockLevelsChart($shopOwnerId, $limit = 10)
    {
        $items = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->select('name', 'available_quantity', 'reserved_quantity', 'reorder_level')
            ->orderBy('available_quantity', 'desc')
            ->limit($limit)
            ->get();
        
        return [
            'categories' => $items->pluck('name')->toArray(),
            'series' => [
                [
                    'name' => 'Available',
                    'data' => $items->pluck('available_quantity')->toArray()
                ],
                [
                    'name' => 'Reserved',
                    'data' => $items->pluck('reserved_quantity')->toArray()
                ],
                [
                    'name' => 'Reorder Level',
                    'data' => $items->pluck('reorder_level')->toArray()
                ]
            ]
        ];
    }
    
    /**
     * Get low stock items
     */
    public function getLowStockItems($shopOwnerId, $threshold = null)
    {
        $query = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true);
        
        if ($threshold) {
            $query->where('available_quantity', '<=', $threshold);
        } else {
            $query->lowStock();
        }
        
        return $query->with(['images'])
            ->orderBy('available_quantity', 'asc')
            ->get();
    }
    
    /**
     * Get out of stock items
     */
    public function getOutOfStockItems($shopOwnerId)
    {
        return InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->outOfStock()
            ->with(['images'])
            ->orderBy('name')
            ->get();
    }
    
    /**
     * Adjust stock quantity for an item
     */
    public function adjustStock($itemId, $quantity, $type, $notes, $userId)
    {
        return DB::transaction(function () use ($itemId, $quantity, $type, $notes, $userId) {
            $item = InventoryItem::lockForUpdate()->findOrFail($itemId);
            
            $quantityBefore = $item->available_quantity;
            $quantityChange = $quantity - $quantityBefore;
            
            $item->available_quantity = $quantity;
            $item->updated_by = $userId;
            $item->save();
            
            // Record movement
            StockMovement::create([
                'inventory_item_id' => $item->id,
                'movement_type' => $type,
                'quantity_change' => $quantityChange,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantity,
                'reference_type' => 'manual_adjustment',
                'notes' => $notes,
                'performed_by' => $userId,
                'performed_at' => now()
            ]);
            
            // Check and create alerts
            $this->checkAndCreateAlerts($item->id);
            
            return $item->fresh();
        });
    }
    
    /**
     * Transfer stock between items
     */
    public function transferStock($fromItemId, $toItemId, $quantity, $userId)
    {
        return DB::transaction(function () use ($fromItemId, $toItemId, $quantity, $userId) {
            // Lock both items
            $fromItem = InventoryItem::lockForUpdate()->findOrFail($fromItemId);
            $toItem = InventoryItem::lockForUpdate()->findOrFail($toItemId);
            
            // Verify same shop owner
            if ($fromItem->shop_owner_id !== $toItem->shop_owner_id) {
                throw new \Exception('Cannot transfer stock between different shop owners');
            }
            
            // Verify sufficient stock
            if ($fromItem->available_quantity < $quantity) {
                throw new \Exception('Insufficient stock to transfer');
            }
            
            // Decrease from source
            $fromQuantityBefore = $fromItem->available_quantity;
            $fromItem->available_quantity -= $quantity;
            $fromItem->updated_by = $userId;
            $fromItem->save();
            
            // Increase to destination
            $toQuantityBefore = $toItem->available_quantity;
            $toItem->available_quantity += $quantity;
            $toItem->updated_by = $userId;
            $toItem->save();
            
            // Record movements
            StockMovement::create([
                'inventory_item_id' => $fromItem->id,
                'movement_type' => 'transfer',
                'quantity_change' => -$quantity,
                'quantity_before' => $fromQuantityBefore,
                'quantity_after' => $fromItem->available_quantity,
                'reference_type' => 'transfer_out',
                'reference_id' => $toItem->id,
                'notes' => "Transferred {$quantity} to {$toItem->name}",
                'performed_by' => $userId,
                'performed_at' => now()
            ]);
            
            StockMovement::create([
                'inventory_item_id' => $toItem->id,
                'movement_type' => 'transfer',
                'quantity_change' => $quantity,
                'quantity_before' => $toQuantityBefore,
                'quantity_after' => $toItem->available_quantity,
                'reference_type' => 'transfer_in',
                'reference_id' => $fromItem->id,
                'notes' => "Received {$quantity} from {$fromItem->name}",
                'performed_by' => $userId,
                'performed_at' => now()
            ]);
            
            // Check alerts for both items
            $this->checkAndCreateAlerts($fromItem->id);
            $this->checkAndCreateAlerts($toItem->id);
            
            return [
                'from_item' => $fromItem->fresh(),
                'to_item' => $toItem->fresh()
            ];
        });
    }
    
    /**
     * Calculate total stock value for a shop owner
     */
    public function calculateStockValue($shopOwnerId)
    {
        return InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->selectRaw('SUM(available_quantity * COALESCE(cost_price, 0)) as total_value')
            ->value('total_value') ?? 0;
    }
    
    /**
     * Generate stock report for a date range
     */
    public function generateStockReport($shopOwnerId, $startDate, $endDate)
    {
        $items = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->with(['stockMovements' => function ($query) use ($startDate, $endDate) {
                $query->whereBetween('performed_at', [$startDate, $endDate])
                      ->orderBy('performed_at', 'desc');
            }])
            ->get();
        
        $report = [];
        foreach ($items as $item) {
            $stockIn = $item->stockMovements
                ->whereIn('movement_type', ['stock_in', 'return'])
                ->sum('quantity_change');
            
            $stockOut = $item->stockMovements
                ->whereIn('movement_type', ['stock_out', 'repair_usage', 'damage'])
                ->sum('quantity_change');
            
            $adjustments = $item->stockMovements
                ->where('movement_type', 'adjustment')
                ->sum('quantity_change');
            
            $report[] = [
                'item' => $item,
                'stock_in' => abs($stockIn),
                'stock_out' => abs($stockOut),
                'adjustments' => $adjustments,
                'net_change' => $stockIn + $stockOut + $adjustments,
                'current_quantity' => $item->available_quantity,
                'current_value' => $item->available_quantity * ($item->cost_price ?? 0)
            ];
        }
        
        return $report;
    }
    
    /**
     * Check inventory levels and create alerts
     */
    public function checkAndCreateAlerts($itemId)
    {
        $item = InventoryItem::find($itemId);
        
        if (!$item) {
            return;
        }
        
        // Check for existing unresolved alerts
        $existingAlert = InventoryAlert::where('inventory_item_id', $item->id)
            ->where('is_resolved', false)
            ->first();
        
        // Determine alert type
        $alertType = null;
        if ($item->available_quantity == 0) {
            $alertType = 'out_of_stock';
        } elseif ($item->available_quantity <= $item->reorder_level) {
            $alertType = 'low_stock';
        }
        
        // Create new alert if needed
        if ($alertType && (!$existingAlert || $existingAlert->alert_type !== $alertType)) {
            // Resolve old alert if exists
            if ($existingAlert) {
                $existingAlert->update([
                    'is_resolved' => true,
                    'resolved_at' => now()
                ]);
            }
            
            // Create new alert
            InventoryAlert::create([
                'inventory_item_id' => $item->id,
                'alert_type' => $alertType,
                'threshold_value' => $item->reorder_level,
                'current_value' => $item->available_quantity,
                'is_resolved' => false
            ]);
        } elseif (!$alertType && $existingAlert) {
            // Resolve alert if stock is back to normal
            $existingAlert->update([
                'is_resolved' => true,
                'resolved_at' => now()
            ]);
        }
    }
    
    /**
     * Sync inventory with products table
     */
    public function syncWithProducts()
    {
        // This would sync inventory_items with the products table
        // Implementation depends on the products table structure
        // Placeholder for future implementation
        return true;
    }
    
    /**
     * Get inventory turnover rate
     */
    public function getInventoryTurnover($shopOwnerId, $days = 30)
    {
        $startDate = Carbon::now()->subDays($days);
        
        $items = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->with(['stockMovements' => function ($query) use ($startDate) {
                $query->where('performed_at', '>=', $startDate)
                      ->whereIn('movement_type', ['stock_out', 'repair_usage']);
            }])
            ->get();
        
        $turnoverData = [];
        foreach ($items as $item) {
            $totalSold = abs($item->stockMovements->sum('quantity_change'));
            $averageStock = $item->available_quantity; // Simplified, could calculate average
            
            $turnover = $averageStock > 0 ? ($totalSold / $averageStock) : 0;
            
            $turnoverData[] = [
                'item' => $item,
                'total_sold' => $totalSold,
                'average_stock' => $averageStock,
                'turnover_rate' => round($turnover, 2),
                'days_to_sell' => $turnover > 0 ? round($days / $turnover, 1) : null
            ];
        }
        
        // Sort by turnover rate descending
        usort($turnoverData, function ($a, $b) {
            return $b['turnover_rate'] <=> $a['turnover_rate'];
        });
        
        return $turnoverData;
    }
    
    /**
     * Get items needing reorder
     */
    public function getItemsNeedingReorder($shopOwnerId)
    {
        return InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->lowStock()
            ->select('id', 'name', 'sku', 'available_quantity', 'reorder_level', 'reorder_quantity')
            ->orderBy('available_quantity', 'asc')
            ->get()
            ->map(function ($item) {
                $item->suggested_order_quantity = $item->reorder_quantity;
                return $item;
            });
    }
}
