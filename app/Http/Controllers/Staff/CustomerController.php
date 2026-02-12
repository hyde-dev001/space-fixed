<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Inertia\Inertia;

class CustomerController extends Controller
{
    /**
     * Display the customer management page (Inertia)
     */
    public function index(Request $request)
    {
        $user = Auth::guard('user')->user();
        
        // Check password change requirement
        if ($user->force_password_change) {
            return redirect()->route('erp.profile');
        }

        // Check if user has a shop assigned
        if (!$user || !$user->shop_owner_id) {
            return Inertia::render('ERP/STAFF/Dashboard', [
                'initialCustomers' => [],
                'initialStats' => [
                    'totalCustomers' => 0,
                    'totalCustomersChange' => 0,
                    'activeCustomers' => 0,
                    'activeCustomersChange' => 0,
                    'totalOrders' => 0,
                    'totalOrdersChange' => 0,
                    'totalRevenue' => 0,
                    'totalRevenueChange' => 0,
                ]
            ]);
        }

        $shopOwnerId = $user->shop_owner_id;

        // Get customers
        $customers = User::select([
            'users.id',
            'users.name',
            'users.email',
            'users.phone',
            'users.address',
            'users.created_at',
            DB::raw('COUNT(DISTINCT orders.id) as total_orders'),
            DB::raw('COALESCE(SUM(orders.total_amount), 0) as total_spent'),
            DB::raw('MAX(orders.created_at) as last_order_date')
        ])
        ->join('orders', 'users.id', '=', 'orders.customer_id')
        ->where('orders.shop_owner_id', $shopOwnerId)
        ->groupBy('users.id', 'users.name', 'users.email', 'users.phone', 'users.address', 'users.created_at')
        ->get()
        ->map(function($customer) {
            $lastOrderDate = $customer->last_order_date ? Carbon::parse($customer->last_order_date) : null;
            $status = $lastOrderDate && $lastOrderDate->greaterThan(Carbon::now()->subDays(90)) 
                ? 'active' 
                : 'inactive';

            return [
                'id' => $customer->id,
                'name' => $customer->name ?? 'Guest Customer',
                'email' => $customer->email ?? 'N/A',
                'phone' => $customer->phone ?? 'N/A',
                'address' => $customer->address ?? 'N/A',
                'status' => $status,
                'totalOrders' => (int) $customer->total_orders,
                'totalSpent' => (float) $customer->total_spent,
                'lastOrderDate' => $lastOrderDate ? $lastOrderDate->format('Y-m-d') : 'N/A',
                'createdAt' => Carbon::parse($customer->created_at)->format('Y-m-d'),
            ];
        });

        // Calculate stats
        $totalCustomers = $customers->count();
        $activeCustomers = $customers->where('status', 'active')->count();
        $totalOrders = Order::where('shop_owner_id', $shopOwnerId)->count();
        $totalRevenue = Order::where('shop_owner_id', $shopOwnerId)
            ->where('payment_status', 'paid')
            ->sum('total_amount');

        // Previous period for comparison
        $prevTotalCustomers = max(1, User::join('orders', 'users.id', '=', 'orders.customer_id')
            ->where('orders.shop_owner_id', $shopOwnerId)
            ->where('orders.created_at', '<', now()->subDays(30))
            ->distinct('users.id')
            ->count('users.id'));

        $prevActiveCustomers = max(1, User::join('orders', 'users.id', '=', 'orders.customer_id')
            ->where('orders.shop_owner_id', $shopOwnerId)
            ->whereBetween('orders.created_at', [now()->subDays(120), now()->subDays(90)])
            ->distinct('users.id')
            ->count('users.id'));

        $prevTotalOrders = max(1, Order::where('shop_owner_id', $shopOwnerId)
            ->where('created_at', '<', now()->subDays(30))
            ->count());

        $prevTotalRevenue = max(1, Order::where('shop_owner_id', $shopOwnerId)
            ->where('payment_status', 'paid')
            ->where('created_at', '<', now()->subDays(30))
            ->sum('total_amount'));

        $stats = [
            'totalCustomers' => $totalCustomers,
            'totalCustomersChange' => round((($totalCustomers - $prevTotalCustomers) / $prevTotalCustomers) * 100, 1),
            'activeCustomers' => $activeCustomers,
            'activeCustomersChange' => round((($activeCustomers - $prevActiveCustomers) / $prevActiveCustomers) * 100, 1),
            'totalOrders' => $totalOrders,
            'totalOrdersChange' => round((($totalOrders - $prevTotalOrders) / $prevTotalOrders) * 100, 1),
            'totalRevenue' => (float) $totalRevenue,
            'totalRevenueChange' => round((($totalRevenue - $prevTotalRevenue) / $prevTotalRevenue) * 100, 1),
        ];

        return Inertia::render('ERP/STAFF/Dashboard', [
            'initialCustomers' => $customers,
            'initialStats' => $stats
        ]);
    }

    /**
     * Get customers who have purchased from this shop
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCustomers(Request $request)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user || !in_array($user->role, ['STAFF', 'MANAGER'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get shop owner ID from the authenticated user
        $shopOwnerId = $user->shop_owner_id;

        if (!$shopOwnerId) {
            return response()->json(['error' => 'Shop owner not found'], 404);
        }

        // Get filters from request
        $searchTerm = $request->input('search', '');
        $filterStatus = $request->input('status', 'all');

        // Get unique customers who have ordered from this shop
        $customersQuery = User::select([
            'users.id',
            'users.name',
            'users.email',
            'users.phone',
            'users.address',
            'users.created_at',
            DB::raw('COUNT(DISTINCT orders.id) as total_orders'),
            DB::raw('COALESCE(SUM(orders.total_amount), 0) as total_spent'),
            DB::raw('MAX(orders.created_at) as last_order_date'),
            DB::raw('CASE 
                WHEN MAX(orders.created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
                THEN "active" 
                ELSE "inactive" 
            END as status')
        ])
        ->join('orders', 'users.id', '=', 'orders.customer_id')
        ->where('orders.shop_owner_id', $shopOwnerId)
        ->groupBy('users.id', 'users.name', 'users.email', 'users.phone', 'users.address', 'users.created_at');

        // Apply search filter
        if ($searchTerm) {
            $customersQuery->where(function($q) use ($searchTerm) {
                $q->where('users.name', 'like', '%' . $searchTerm . '%')
                  ->orWhere('users.email', 'like', '%' . $searchTerm . '%')
                  ->orWhere('users.phone', 'like', '%' . $searchTerm . '%');
            });
        }

        $customers = $customersQuery->get()->map(function($customer) use ($filterStatus) {
            // Determine active status
            $lastOrderDate = $customer->last_order_date ? Carbon::parse($customer->last_order_date) : null;
            $status = $lastOrderDate && $lastOrderDate->greaterThan(Carbon::now()->subDays(30)) 
                ? 'active' 
                : 'inactive';

            return [
                'id' => $customer->id,
                'name' => $customer->name ?? 'N/A',
                'email' => $customer->email ?? 'N/A',
                'phone' => $customer->phone ?? 'N/A',
                'address' => $customer->address ?? 'N/A',
                'status' => $status,
                'totalOrders' => (int) $customer->total_orders,
                'totalSpent' => (float) $customer->total_spent,
                'lastOrderDate' => $lastOrderDate ? $lastOrderDate->format('Y-m-d') : 'N/A',
                'createdAt' => Carbon::parse($customer->created_at)->format('Y-m-d'),
            ];
        });

        // Apply status filter after mapping
        if ($filterStatus !== 'all') {
            $customers = $customers->filter(function($customer) use ($filterStatus) {
                return $customer['status'] === $filterStatus;
            })->values();
        }

        return response()->json([
            'customers' => $customers
        ]);
    }

    /**
     * Get customer statistics
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStats()
    {
        $user = Auth::guard('user')->user();
        
        if (!$user || !in_array($user->role, ['STAFF', 'MANAGER'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $shopOwnerId = $user->shop_owner_id;

        if (!$shopOwnerId) {
            return response()->json(['error' => 'Shop owner not found'], 404);
        }

        // Get unique customer count
        $totalCustomers = Order::where('shop_owner_id', $shopOwnerId)
            ->distinct('customer_id')
            ->count('customer_id');

        // Get active customers (ordered in last 30 days)
        $activeCustomers = Order::where('shop_owner_id', $shopOwnerId)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->distinct('customer_id')
            ->count('customer_id');

        // Get total orders
        $totalOrders = Order::where('shop_owner_id', $shopOwnerId)->count();

        // Get total revenue from completed/processing orders
        $totalRevenue = Order::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', ['processing', 'shipped', 'completed'])
            ->sum('total_amount');

        // Previous month stats for comparison
        $lastMonthCustomers = Order::where('shop_owner_id', $shopOwnerId)
            ->whereBetween('created_at', [
                Carbon::now()->subMonths(2)->startOfMonth(),
                Carbon::now()->subMonth()->endOfMonth()
            ])
            ->distinct('customer_id')
            ->count('customer_id');

        $lastMonthOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->whereBetween('created_at', [
                Carbon::now()->subMonth()->startOfMonth(),
                Carbon::now()->subMonth()->endOfMonth()
            ])
            ->count();

        $lastMonthRevenue = Order::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', ['processing', 'shipped', 'completed'])
            ->whereBetween('created_at', [
                Carbon::now()->subMonth()->startOfMonth(),
                Carbon::now()->subMonth()->endOfMonth()
            ])
            ->sum('total_amount');

        // Calculate percentage changes
        $customerChange = $lastMonthCustomers > 0 
            ? (($totalCustomers - $lastMonthCustomers) / $lastMonthCustomers) * 100 
            : ($totalCustomers > 0 ? 100 : 0);

        $ordersChange = $lastMonthOrders > 0 
            ? (($totalOrders - $lastMonthOrders) / $lastMonthOrders) * 100 
            : ($totalOrders > 0 ? 100 : 0);

        $revenueChange = $lastMonthRevenue > 0 
            ? (($totalRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100 
            : ($totalRevenue > 0 ? 100 : 0);

        return response()->json([
            'totalCustomers' => $totalCustomers,
            'totalCustomersChange' => round($customerChange, 1),
            'activeCustomers' => $activeCustomers,
            'activeCustomersChange' => round($customerChange, 1),
            'totalOrders' => $totalOrders,
            'totalOrdersChange' => round($ordersChange, 1),
            'totalRevenue' => $totalRevenue,
            'totalRevenueChange' => round($revenueChange, 1),
        ]);
    }
}
