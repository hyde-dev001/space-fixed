<?php

namespace App\Services;

use App\Models\StockMovement;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StockMovementService
{
    /**
     * Record a new stock movement
     */
    public function recordMovement($data)
    {
        return DB::transaction(function () use ($data) {
            $item = InventoryItem::lockForUpdate()->findOrFail($data['inventory_item_id']);
            
            $quantityBefore = $item->available_quantity;
            $quantityChange = $data['quantity_change'];
            $quantityAfter = $quantityBefore + $quantityChange;
            
            // Validate stock availability for negative movements
            if ($quantityAfter < 0) {
                throw new \Exception('Insufficient stock. Available: ' . $quantityBefore);
            }
            
            // Update inventory
            $item->available_quantity = $quantityAfter;
            $item->updated_by = $data['performed_by'] ?? null;
            $item->save();
            
            // Create movement record
            $movement = StockMovement::create([
                'inventory_item_id' => $item->id,
                'movement_type' => $data['movement_type'],
                'quantity_change' => $quantityChange,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'reference_type' => $data['reference_type'] ?? 'manual',
                'reference_id' => $data['reference_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'performed_by' => $data['performed_by'] ?? null,
                'performed_at' => $data['performed_at'] ?? now()
            ]);
            
            // Check for alerts
            app(InventoryService::class)->checkAndCreateAlerts($item->id);
            
            return $movement->load('inventoryItem', 'performer');
        });
    }
    
    /**
     * Get movements by date range
     */
    public function getMovementsByDateRange($startDate, $endDate, $shopOwnerId = null)
    {
        $query = StockMovement::with(['inventoryItem', 'performer'])
            ->whereBetween('performed_at', [$startDate, $endDate]);
        
        if ($shopOwnerId) {
            $query->whereHas('inventoryItem', function ($q) use ($shopOwnerId) {
                $q->where('shop_owner_id', $shopOwnerId);
            });
        }
        
        return $query->orderBy('performed_at', 'desc')->get();
    }
    
    /**
     * Get movement metrics for a period
     */
    public function getMovementMetrics($shopOwnerId, $period = 'month')
    {
        $startDate = $this->getStartDateForPeriod($period);
        $endDate = now();
        
        $query = StockMovement::whereHas('inventoryItem', function ($q) use ($shopOwnerId) {
            $q->where('shop_owner_id', $shopOwnerId);
        })->whereBetween('performed_at', [$startDate, $endDate]);
        
        $stockIn = (clone $query)
            ->whereIn('movement_type', ['stock_in', 'return'])
            ->sum('quantity_change');
        
        $stockOut = (clone $query)
            ->whereIn('movement_type', ['stock_out', 'repair_usage', 'damage'])
            ->sum('quantity_change');
        
        $adjustments = (clone $query)
            ->where('movement_type', 'adjustment')
            ->sum('quantity_change');
        
        $transfers = (clone $query)
            ->where('movement_type', 'transfer')
            ->count();
        
        $totalMovements = (clone $query)->count();
        
        // Daily breakdown
        $dailyMovements = (clone $query)
            ->select(
                DB::raw('DATE(performed_at) as date'),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(CASE WHEN movement_type IN ("stock_in", "return") THEN quantity_change ELSE 0 END) as stock_in'),
                DB::raw('SUM(CASE WHEN movement_type IN ("stock_out", "repair_usage", "damage") THEN quantity_change ELSE 0 END) as stock_out')
            )
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();
        
        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
                'label' => ucfirst($period)
            ],
            'summary' => [
                'stock_in' => abs($stockIn),
                'stock_out' => abs($stockOut),
                'adjustments' => $adjustments,
                'transfers' => $transfers,
                'total_movements' => $totalMovements,
                'net_change' => $stockIn + $stockOut + $adjustments
            ],
            'daily_breakdown' => $dailyMovements
        ];
    }
    
    /**
     * Export movement report
     */
    public function exportMovementReport($filters)
    {
        $query = StockMovement::with(['inventoryItem', 'performer']);
        
        // Apply filters
        if (isset($filters['shop_owner_id'])) {
            $query->whereHas('inventoryItem', function ($q) use ($filters) {
                $q->where('shop_owner_id', $filters['shop_owner_id']);
            });
        }
        
        if (isset($filters['start_date'])) {
            $query->whereDate('performed_at', '>=', $filters['start_date']);
        }
        
        if (isset($filters['end_date'])) {
            $query->whereDate('performed_at', '<=', $filters['end_date']);
        }
        
        if (isset($filters['movement_type'])) {
            $query->where('movement_type', $filters['movement_type']);
        }
        
        if (isset($filters['inventory_item_id'])) {
            $query->where('inventory_item_id', $filters['inventory_item_id']);
        }
        
        $movements = $query->orderBy('performed_at', 'desc')->get();
        
        // Format data for export
        $exportData = $movements->map(function ($movement) {
            return [
                'Date' => $movement->performed_at->format('Y-m-d H:i:s'),
                'Product' => $movement->inventoryItem->name,
                'SKU' => $movement->inventoryItem->sku,
                'Movement Type' => ucwords(str_replace('_', ' ', $movement->movement_type)),
                'Quantity Change' => $movement->quantity_change,
                'Quantity Before' => $movement->quantity_before,
                'Quantity After' => $movement->quantity_after,
                'Reference Type' => $movement->reference_type,
                'Performed By' => $movement->performer->name ?? 'System',
                'Notes' => $movement->notes
            ];
        });
        
        return $exportData;
    }
    
    /**
     * Get movements by product
     */
    public function getMovementsByProduct($itemId, $limit = 50)
    {
        return StockMovement::with(['performer'])
            ->where('inventory_item_id', $itemId)
            ->orderBy('performed_at', 'desc')
            ->limit($limit)
            ->get();
    }
    
    /**
     * Get movement statistics by type
     */
    public function getMovementStatsByType($shopOwnerId, $startDate = null, $endDate = null)
    {
        $query = StockMovement::whereHas('inventoryItem', function ($q) use ($shopOwnerId) {
            $q->where('shop_owner_id', $shopOwnerId);
        });
        
        if ($startDate && $endDate) {
            $query->whereBetween('performed_at', [$startDate, $endDate]);
        }
        
        $stats = $query->select(
                'movement_type',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(ABS(quantity_change)) as total_quantity'),
                DB::raw('AVG(ABS(quantity_change)) as avg_quantity')
            )
            ->groupBy('movement_type')
            ->get()
            ->keyBy('movement_type');
        
        return $stats;
    }
    
    /**
     * Get top movers (most activity)
     */
    public function getTopMovers($shopOwnerId, $limit = 10, $days = 30)
    {
        $startDate = Carbon::now()->subDays($days);
        
        $topMovers = StockMovement::whereHas('inventoryItem', function ($q) use ($shopOwnerId) {
                $q->where('shop_owner_id', $shopOwnerId);
            })
            ->where('performed_at', '>=', $startDate)
            ->select(
                'inventory_item_id',
                DB::raw('COUNT(*) as movement_count'),
                DB::raw('SUM(ABS(quantity_change)) as total_quantity_moved')
            )
            ->groupBy('inventory_item_id')
            ->orderBy('movement_count', 'desc')
            ->limit($limit)
            ->get();
        
        // Load inventory items
        $itemIds = $topMovers->pluck('inventory_item_id');
        $items = InventoryItem::whereIn('id', $itemIds)->get()->keyBy('id');
        
        return $topMovers->map(function ($mover) use ($items) {
            $mover->item = $items[$mover->inventory_item_id] ?? null;
            return $mover;
        });
    }
    
    /**
     * Reverse a stock movement (create opposite movement)
     */
    public function reverseMovement($movementId, $userId, $notes)
    {
        return DB::transaction(function () use ($movementId, $userId, $notes) {
            $originalMovement = StockMovement::findOrFail($movementId);
            
            // Create reverse movement
            $reverseData = [
                'inventory_item_id' => $originalMovement->inventory_item_id,
                'movement_type' => 'adjustment',
                'quantity_change' => -$originalMovement->quantity_change,
                'reference_type' => 'reversal',
                'reference_id' => $originalMovement->id,
                'notes' => "Reversal of movement #{$movementId}. " . $notes,
                'performed_by' => $userId
            ];
            
            return $this->recordMovement($reverseData);
        });
    }
    
    /**
     * Bulk record movements (for imports or batch operations)
     */
    public function bulkRecordMovements($movements, $userId)
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        foreach ($movements as $index => $movementData) {
            try {
                DB::beginTransaction();
                
                $movementData['performed_by'] = $userId;
                $this->recordMovement($movementData);
                
                DB::commit();
                $results['success']++;
            } catch (\Exception $e) {
                DB::rollBack();
                $results['failed']++;
                $results['errors'][] = [
                    'index' => $index,
                    'data' => $movementData,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Get start date based on period
     */
    protected function getStartDateForPeriod($period)
    {
        return match($period) {
            'today' => Carbon::today(),
            'week' => Carbon::now()->startOfWeek(),
            'month' => Carbon::now()->startOfMonth(),
            'quarter' => Carbon::now()->startOfQuarter(),
            'year' => Carbon::now()->startOfYear(),
            default => Carbon::now()->startOfMonth()
        };
    }
}
