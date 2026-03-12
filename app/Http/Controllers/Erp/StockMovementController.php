<?php

namespace App\Http\Controllers\ERP;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    /**
     * List all stock movements with filters
     */
    public function index(Request $request)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $query = StockMovement::with(['inventoryItem', 'performer'])
            ->whereHas('inventoryItem', function ($q) use ($shopOwnerId) {
                $q->where('shop_owner_id', $shopOwnerId);
            });
        
        // Filter by movement type
        if ($request->movement_type) {
            $query->where('movement_type', $request->movement_type);
        }
        
        // Filter by inventory item
        if ($request->inventory_item_id) {
            $query->where('inventory_item_id', $request->inventory_item_id);
        }
        
        // Filter by date range
        if ($request->start_date) {
            $query->whereDate('performed_at', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->whereDate('performed_at', '<=', $request->end_date);
        }
        
        // Search by product name or SKU
        if ($request->search) {
            $query->whereHas('inventoryItem', function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('sku', 'like', "%{$request->search}%");
            });
        }
        
        $movements = $query->orderBy('performed_at', 'desc')
            ->paginate($request->per_page ?? 50)
            ->withQueryString();
        
        return response()->json($movements);
    }
    
    /**
     * Record new stock movement
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'movement_type' => 'required|in:stock_in,stock_out,adjustment,return,repair_usage,transfer,damage',
            'quantity_change' => 'required|integer|not_in:0',
            'notes' => 'nullable|string|max:1000',
            'reference_type' => 'nullable|string|max:100',
            'reference_id' => 'nullable|integer'
        ]);
        
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $item = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($validated['inventory_item_id']);
        
        DB::transaction(function () use ($item, $validated, $request) {
            $quantityBefore = $item->available_quantity;
            $quantityAfter = $quantityBefore + $validated['quantity_change'];
            
            if ($quantityAfter < 0) {
                throw new \Exception('Insufficient stock. Available: ' . $quantityBefore);
            }
            
            // Update inventory
            $item->available_quantity = $quantityAfter;
            $item->updated_by = $request->user()->id;
            $item->save();
            
            // Create movement record
            StockMovement::create([
                'inventory_item_id' => $item->id,
                'movement_type' => $validated['movement_type'],
                'quantity_change' => $validated['quantity_change'],
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'reference_type' => $validated['reference_type'] ?? 'manual',
                'reference_id' => $validated['reference_id'] ?? null,
                'notes' => $validated['notes'],
                'performed_by' => $request->user()->id,
                'performed_at' => now()
            ]);
            
            $item->checkReorderLevel();
        });
        
        return response()->json([
            'message' => 'Stock movement recorded successfully',
            'item' => $item->fresh()
        ], 201);
    }
    
    /**
     * Get stock movement metrics
     */
    public function getMetrics(Request $request)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $startDate = $request->start_date ?? now()->startOfMonth();
        $endDate = $request->end_date ?? now()->endOfMonth();
        
        $stockIn = StockMovement::whereHas('inventoryItem', function ($q) use ($shopOwnerId) {
                $q->where('shop_owner_id', $shopOwnerId);
            })
            ->whereIn('movement_type', ['stock_in', 'return'])
            ->whereBetween('performed_at', [$startDate, $endDate])
            ->sum('quantity_change');
        
        $stockOut = StockMovement::whereHas('inventoryItem', function ($q) use ($shopOwnerId) {
                $q->where('shop_owner_id', $shopOwnerId);
            })
            ->whereIn('movement_type', ['stock_out', 'repair_usage', 'damage'])
            ->whereBetween('performed_at', [$startDate, $endDate])
            ->sum('quantity_change');
        
        $adjustments = StockMovement::whereHas('inventoryItem', function ($q) use ($shopOwnerId) {
                $q->where('shop_owner_id', $shopOwnerId);
            })
            ->where('movement_type', 'adjustment')
            ->whereBetween('performed_at', [$startDate, $endDate])
            ->sum('quantity_change');
        
        $totalMovements = StockMovement::whereHas('inventoryItem', function ($q) use ($shopOwnerId) {
                $q->where('shop_owner_id', $shopOwnerId);
            })
            ->whereBetween('performed_at', [$startDate, $endDate])
            ->count();
        
        return response()->json([
            'stock_in' => abs($stockIn),
            'stock_out' => abs($stockOut),
            'adjustments' => $adjustments,
            'total_movements' => $totalMovements,
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ]
        ]);
    }
    
    /**
     * Export stock movement report
     */
    public function exportReport(Request $request)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $query = StockMovement::with(['inventoryItem', 'performer'])
            ->whereHas('inventoryItem', function ($q) use ($shopOwnerId) {
                $q->where('shop_owner_id', $shopOwnerId);
            });
        
        if ($request->start_date) {
            $query->whereDate('performed_at', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->whereDate('performed_at', '<=', $request->end_date);
        }
        
        $movements = $query->orderBy('performed_at', 'desc')->get();
        
        // Here you would implement CSV/Excel export
        // For now, return JSON
        return response()->json($movements);
    }
}
