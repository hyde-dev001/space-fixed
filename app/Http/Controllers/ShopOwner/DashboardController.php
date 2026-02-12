<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics for shop owner
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStats()
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        
        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $shopOwnerId = $shopOwner->id;

        // Get date ranges
        $today = Carbon::today();
        $thisMonth = Carbon::now()->startOfMonth();
        $lastMonth = Carbon::now()->subMonth()->startOfMonth();
        $lastMonthEnd = Carbon::now()->subMonth()->endOfMonth();

        // Total Revenue (all time)
        $totalRevenue = Order::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', ['processing', 'shipped', 'completed'])
            ->sum('total_amount');

        // This Month Revenue
        $thisMonthRevenue = Order::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', ['processing', 'shipped', 'completed'])
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->sum('total_amount');

        // Last Month Revenue
        $lastMonthRevenue = Order::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', ['processing', 'shipped', 'completed'])
            ->whereMonth('created_at', Carbon::now()->subMonth()->month)
            ->whereYear('created_at', Carbon::now()->subMonth()->year)
            ->sum('total_amount');

        // Revenue Growth
        $revenueGrowth = $lastMonthRevenue > 0 
            ? (($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100 
            : 0;

        // Total Orders
        $totalOrders = Order::where('shop_owner_id', $shopOwnerId)->count();

        // This Month Orders
        $thisMonthOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->count();

        // Last Month Orders
        $lastMonthOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->whereMonth('created_at', Carbon::now()->subMonth()->month)
            ->whereYear('created_at', Carbon::now()->subMonth()->year)
            ->count();

        // Orders Growth
        $ordersGrowth = $lastMonthOrders > 0 
            ? (($thisMonthOrders - $lastMonthOrders) / $lastMonthOrders) * 100 
            : 0;

        // Total Products
        $totalProducts = Product::where('shop_owner_id', $shopOwnerId)->count();

        // Active Products (in stock)
        $activeProducts = Product::where('shop_owner_id', $shopOwnerId)
            ->where('stock_quantity', '>', 0)
            ->count();

        // Low Stock Products (stock < 10)
        $lowStockProducts = Product::where('shop_owner_id', $shopOwnerId)
            ->where('stock_quantity', '<', 10)
            ->where('stock_quantity', '>', 0)
            ->count();

        // Out of Stock Products
        $outOfStockProducts = Product::where('shop_owner_id', $shopOwnerId)
            ->where('stock_quantity', '<=', 0)
            ->count();

        // Pending Orders
        $pendingOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->where('status', 'pending')
            ->count();

        // Processing Orders
        $processingOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->where('status', 'processing')
            ->count();

        // Shipped Orders
        $shippedOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->where('status', 'shipped')
            ->count();

        // Completed Orders
        $completedOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->where('status', 'completed')
            ->count();

        // Top Selling Products (last 30 days)
        $topProducts = OrderItem::select('product_id', 'product_name', 'product_slug', 'product_image')
            ->selectRaw('SUM(quantity) as total_quantity')
            ->selectRaw('SUM(subtotal) as total_revenue')
            ->whereHas('order', function($query) use ($shopOwnerId) {
                $query->where('shop_owner_id', $shopOwnerId)
                    ->whereIn('status', ['processing', 'shipped', 'completed'])
                    ->where('created_at', '>=', Carbon::now()->subDays(30));
            })
            ->groupBy('product_id', 'product_name', 'product_slug', 'product_image')
            ->orderByDesc('total_quantity')
            ->limit(5)
            ->get();

        // Recent Orders (last 10)
        $recentOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->with(['customer', 'items'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->customer_name ?? $order->customer?->name ?? 'Guest',
                    'customer_email' => $order->customer_email ?? $order->customer?->email ?? '',
                    'total_amount' => $order->total_amount,
                    'status' => $order->status,
                    'items_count' => $order->items->count(),
                    'created_at' => $order->created_at->toISOString(),
                ];
            });

        // Revenue trend (last 7 days)
        $revenueTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $revenue = Order::where('shop_owner_id', $shopOwnerId)
                ->whereIn('status', ['processing', 'shipped', 'completed'])
                ->whereDate('created_at', $date)
                ->sum('total_amount');
            
            $revenueTrend[] = [
                'date' => $date->format('M d'),
                'revenue' => floatval($revenue),
            ];
        }

        // Unique Customers
        $uniqueCustomers = Order::where('shop_owner_id', $shopOwnerId)
            ->distinct('customer_id')
            ->whereNotNull('customer_id')
            ->count('customer_id');

        // Guest Orders (no customer_id)
        $guestOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->whereNull('customer_id')
            ->count();

        // Total Customers (unique + guests)
        $totalCustomers = $uniqueCustomers + $guestOrders;

        // Repeat Customers (customers with more than 1 order)
        $repeatCustomers = Order::where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('customer_id')
            ->select('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        // Average Order Value
        $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

        return response()->json([
            'revenue' => [
                'total' => floatval($totalRevenue),
                'this_month' => floatval($thisMonthRevenue),
                'last_month' => floatval($lastMonthRevenue),
                'growth' => round($revenueGrowth, 2),
                'average_order' => round($avgOrderValue, 2),
            ],
            'orders' => [
                'total' => $totalOrders,
                'this_month' => $thisMonthOrders,
                'last_month' => $lastMonthOrders,
                'growth' => round($ordersGrowth, 2),
                'pending' => $pendingOrders,
                'processing' => $processingOrders,
                'shipped' => $shippedOrders,
                'completed' => $completedOrders,
            ],
            'products' => [
                'total' => $totalProducts,
                'active' => $activeProducts,
                'low_stock' => $lowStockProducts,
                'out_of_stock' => $outOfStockProducts,
            ],
            'customers' => [
                'total' => $totalCustomers,
                'unique' => $uniqueCustomers,
                'guests' => $guestOrders,
                'repeat' => $repeatCustomers,
            ],
            'top_products' => $topProducts->map(function($item) {
                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'product_slug' => $item->product_slug,
                    'product_image' => $item->product_image,
                    'total_quantity' => $item->total_quantity,
                    'total_revenue' => floatval($item->total_revenue),
                ];
            }),
            'recent_orders' => $recentOrders,
            'revenue_trend' => $revenueTrend,
        ]);
    }

    /**
     * Get low stock alerts for shop owner
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLowStockAlerts()
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        
        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $lowStockProducts = Product::where('shop_owner_id', $shopOwner->id)
            ->where('stock_quantity', '<', 10)
            ->orderBy('stock_quantity', 'asc')
            ->get()
            ->map(function($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'stock_quantity' => $product->stock_quantity,
                    'price' => $product->price,
                    'image' => $product->image,
                    'status' => $product->stock_quantity <= 0 ? 'out_of_stock' : 'low_stock',
                ];
            });

        return response()->json($lowStockProducts);
    }
}
