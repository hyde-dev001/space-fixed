<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\SupplierOrder;
use App\Services\Erp\ShopOwnerInventoryReadService;
use App\Support\Erp\ErpActorContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryDashboardController extends Controller
{
    public function __construct(
        private readonly ShopOwnerInventoryReadService $ownerInventoryRead,
    ) {}

    /**
     * Display inventory dashboard with metrics and overview
     */
    public function index(Request $request)
    {
        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }
        
        $ownerMode = $this->ownerMode();
        $ownerRows = $ownerMode ? $this->ownerInventoryRead->rows($shopOwnerId) : null;
        $metrics = $this->getMetrics($shopOwnerId, $ownerMode, $ownerRows);
        $chartData = $this->getChartData($shopOwnerId, $ownerMode, $ownerRows);

        $products = $ownerMode
            ? $this->ownerInventoryRead->paginateRows($ownerRows ?? collect(), $request->only([
                'search',
                'category',
                'status',
                'sort_by',
                'sort_order',
                'page',
                'per_page',
            ]))
            : InventoryItem::with(['sizes', 'colorVariants', 'images'])
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
                ->paginate($request->per_page ?? 20)
                ->withQueryString();
        
        return response()->json([
            'metrics' => $metrics,
            'chartData' => $chartData,
            'products' => $products,
        ]);
    }
    
    /**
     * Get dashboard metrics
     */
    public function getMetrics($shopOwnerId = null, ?bool $includeCatalogProducts = null, ?Collection $catalogRows = null)
    {
        if (!$shopOwnerId) {
            $shopOwnerId = $this->resolveShopOwnerId();
            if (!$shopOwnerId) {
                return [
                    'total_items' => 0,
                    'total_value' => 0,
                    'low_stock_count' => 0,
                    'out_of_stock_count' => 0,
                    'stock_in_today' => 0,
                    'stock_out_today' => 0,
                    'active_supplier_orders' => 0,
                    'overdue_orders' => 0,
                ];
            }
        }
        
        $includeCatalogProducts ??= $this->ownerMode();
        if ($includeCatalogProducts) {
            $catalogMetrics = $this->ownerInventoryRead->metricsForRows(
                $catalogRows ?? $this->ownerInventoryRead->rows((int) $shopOwnerId),
            );
            $totalItems = $catalogMetrics['total_items'];
            $lowStockItems = $catalogMetrics['low_stock_count'];
            $outOfStockItems = $catalogMetrics['out_of_stock_count'];
            $totalValue = $catalogMetrics['total_value'];
        } else {
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
        }
        
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
    public function getChartData($shopOwnerId = null, ?bool $includeCatalogProducts = null, ?Collection $catalogRows = null)
    {
        if (!$shopOwnerId) {
            $shopOwnerId = $this->resolveShopOwnerId();
            if (!$shopOwnerId) {
                return [
                    'categories' => collect(),
                    'series' => [
                        ['name' => 'Available', 'data' => collect()],
                        ['name' => 'Reserved', 'data' => collect()],
                        ['name' => 'Reorder Level', 'data' => collect()],
                    ],
                ];
            }
        }
        
        $includeCatalogProducts ??= $this->ownerMode();
        if ($includeCatalogProducts) {
            return $this->ownerInventoryRead->chartDataForRows(
                $catalogRows ?? $this->ownerInventoryRead->rows((int) $shopOwnerId),
            );
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
    public function show(Request $request, $id)
    {
        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }
        
        $item = InventoryItem::with(['sizes', 'colorVariants.images', 'images', 'stockMovements' => function ($query) {
                $query->with('performer')->latest('performed_at')->limit(10);
            }])
            ->where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        return response()->json($item);
    }

    private function resolveShopOwnerId(?Request $request = null): ?int
    {
        $context = request()->attributes->get('erp.actor_context');
        if ($context instanceof ErpActorContext && $context->isOwnerMode()) {
            return (int) $context->tenantOwner()->getKey();
        }

        $user = $request?->user() ?? Auth::guard('user')->user() ?? Auth::user();
        if (!$user) {
            return null;
        }

        $shopOwnerId = $user->shop_owner_id
            ?? $user->shopOwner?->id
            ?? null;

        return $shopOwnerId ? (int) $shopOwnerId : null;
    }

    private function ownerMode(): bool
    {
        $context = request()->attributes->get('erp.actor_context');

        return $context instanceof ErpActorContext && $context->isOwnerMode();
    }
}
