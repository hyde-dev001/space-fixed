<?php

namespace App\Http\Controllers\ERP;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\SupplierOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InventoryDashboardController extends Controller
{
    /**
     * Display inventory dashboard with metrics and overview
     */
    public function index(Request $request)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $metrics = $this->getMetrics($shopOwnerId);
        $chartData = $this->getChartData($shopOwnerId);
        
        $products = InventoryItem::with(['sizes', 'colorVariants', 'images'])
            ->where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->when($request->category, function ($query, $category) {
                $query->where('category', $category);
            })
            ->when($request->status, function ($query, $status) {
                if ($status === 'low_stock') {
                    $query->lowStock();
                } elseif ($status === 'out_of_stock') {
                    $query->outOfStock();
                }
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();
        
        return Inertia::render('ERP/Inventory/InventoryDashboard', [
            'metrics' => $metrics,
            'chartData' => $chartData,
            'products' => $products,
            'filters' => $request->only(['search', 'category', 'status'])
        ]);
    }
    
    /**
     * Get dashboard metrics
     */
    public function getMetrics($shopOwnerId = null)
    {
        if (!$shopOwnerId) {
            $shopOwnerId = request()->user()->shop_owner_id;
        }
        
        $totalItems = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->count();
        
        $lowStockItems = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->lowStock()
            ->count();
        
        $outOfStockItems = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->outOfStock()
            ->count();
        
        $totalValue = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->selectRaw('SUM(available_quantity * COALESCE(cost_price, 0)) as total')
            ->value('total') ?? 0;
        
        $stockInToday = StockMovement::whereHas('inventoryItem', function ($query) use ($shopOwnerId) {
                $query->where('shop_owner_id', $shopOwnerId);
            })
            ->whereIn('movement_type', ['stock_in', 'return'])
            ->whereDate('performed_at', today())
            ->sum('quantity_change');
        
        $stockOutToday = StockMovement::whereHas('inventoryItem', function ($query) use ($shopOwnerId) {
                $query->where('shop_owner_id', $shopOwnerId);
            })
            ->whereIn('movement_type', ['stock_out', 'repair_usage'])
            ->whereDate('performed_at', today())
            ->sum('quantity_change');
        
        $activeSupplierOrders = SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', ['sent', 'confirmed', 'in_transit'])
            ->count();
        
        $overdueOrders = SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->overdue()
            ->count();
        
        return [
            'total_items' => $totalItems,
            'total_value' => round($totalValue, 2),
            'low_stock_count' => $lowStockItems,
            'out_of_stock_count' => $outOfStockItems,
            'stock_in_today' => abs($stockInToday),
            'stock_out_today' => abs($stockOutToday),
            'active_supplier_orders' => $activeSupplierOrders,
            'overdue_orders' => $overdueOrders,
        ];
    }
    
    /**
     * Get stock levels chart data
     */
    public function getChartData($shopOwnerId = null)
    {
        if (!$shopOwnerId) {
            $shopOwnerId = request()->user()->shop_owner_id;
        }
        
        $items = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->select('name', 'available_quantity', 'reserved_quantity', 'reorder_level')
            ->orderBy('available_quantity', 'desc')
            ->limit(10)
            ->get();
        
        return [
            'categories' => $items->pluck('name'),
            'series' => [
                [
                    'name' => 'Available',
                    'data' => $items->pluck('available_quantity')
                ],
                [
                    'name' => 'Reserved',
                    'data' => $items->pluck('reserved_quantity')
                ],
                [
                    'name' => 'Reorder Level',
                    'data' => $items->pluck('reorder_level')
                ]
            ]
        ];
    }
    
    /**
     * Show single inventory item details
     */
    public function show($id)
    {
        $shopOwnerId = request()->user()->shop_owner_id;
        
        $item = InventoryItem::with(['sizes', 'colorVariants.images', 'images', 'stockMovements' => function ($query) {
                $query->with('performer')->latest('performed_at')->limit(10);
            }])
            ->where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        return response()->json($item);
    }
}
