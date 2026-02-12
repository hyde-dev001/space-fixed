<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth:user');
    }

    /**
     * Get customers who have purchased from the staff's shop
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user || !$user->shop_owner_id) {
                return response()->json([
                    'message' => 'No shop assigned to this user',
                    'customers' => []
                ], 200);
            }

            $shopOwnerId = $user->shop_owner_id;
            
            // Get search and filter parameters
            $search = $request->input('search', '');
            $status = $request->input('status', 'all');

            // Get all customers who have ordered from this shop
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
                DB::raw('CASE WHEN MAX(orders.created_at) >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN "active" ELSE "inactive" END as status')
            ])
            ->join('orders', 'users.id', '=', 'orders.customer_id')
            ->where('orders.shop_owner_id', $shopOwnerId)
            ->groupBy('users.id', 'users.name', 'users.email', 'users.phone', 'users.address', 'users.created_at');

            // Apply search filter
            if (!empty($search)) {
                $customersQuery->where(function ($q) use ($search) {
                    $q->where('users.name', 'LIKE', "%{$search}%")
                      ->orWhere('users.email', 'LIKE', "%{$search}%")
                      ->orWhere('users.phone', 'LIKE', "%{$search}%");
                });
            }

            $customers = $customersQuery->get()->map(function ($customer) {
                return [
                    'id' => $customer->id,
                    'name' => $customer->name ?? 'Guest Customer',
                    'email' => $customer->email ?? 'N/A',
                    'phone' => $customer->phone ?? 'N/A',
                    'address' => $customer->address ?? 'N/A',
                    'status' => $customer->status,
                    'totalOrders' => (int) $customer->total_orders,
                    'totalSpent' => (float) $customer->total_spent,
                    'lastOrderDate' => $customer->last_order_date ? \Carbon\Carbon::parse($customer->last_order_date)->format('Y-m-d') : 'N/A',
                    'createdAt' => $customer->created_at ? \Carbon\Carbon::parse($customer->created_at)->format('Y-m-d') : 'N/A',
                ];
            });

            // Apply status filter
            if ($status !== 'all') {
                $customers = $customers->filter(function ($customer) use ($status) {
                    return $customer['status'] === $status;
                })->values();
            }

            return response()->json([
                'customers' => $customers
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching customers: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error fetching customers',
                'error' => $e->getMessage(),
                'customers' => []
            ], 500);
        }
    }

    /**
     * Get customer statistics for the staff's shop
     */
    public function stats(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user || !$user->shop_owner_id) {
                return response()->json([
                    'totalCustomers' => 0,
                    'totalCustomersChange' => 0,
                    'activeCustomers' => 0,
                    'activeCustomersChange' => 0,
                    'totalOrders' => 0,
                    'totalOrdersChange' => 0,
                    'totalRevenue' => 0,
                    'totalRevenueChange' => 0,
                ]);
            }

            $shopOwnerId = $user->shop_owner_id;

            // Current period stats
            $totalCustomers = User::join('orders', 'users.id', '=', 'orders.customer_id')
                ->where('orders.shop_owner_id', $shopOwnerId)
                ->distinct('users.id')
                ->count('users.id');

            $activeCustomers = User::join('orders', 'users.id', '=', 'orders.customer_id')
                ->where('orders.shop_owner_id', $shopOwnerId)
                ->where('orders.created_at', '>=', now()->subDays(90))
                ->distinct('users.id')
                ->count('users.id');

            $totalOrders = Order::where('shop_owner_id', $shopOwnerId)->count();

            $totalRevenue = Order::where('shop_owner_id', $shopOwnerId)
                ->where('payment_status', 'paid')
                ->sum('total_amount');

            // Previous period stats (for comparison)
            $prevTotalCustomers = User::join('orders', 'users.id', '=', 'orders.customer_id')
                ->where('orders.shop_owner_id', $shopOwnerId)
                ->where('orders.created_at', '<', now()->subDays(30))
                ->distinct('users.id')
                ->count('users.id');

            $prevActiveCustomers = User::join('orders', 'users.id', '=', 'orders.customer_id')
                ->where('orders.shop_owner_id', $shopOwnerId)
                ->whereBetween('orders.created_at', [now()->subDays(120), now()->subDays(90)])
                ->distinct('users.id')
                ->count('users.id');

            $prevTotalOrders = Order::where('shop_owner_id', $shopOwnerId)
                ->where('created_at', '<', now()->subDays(30))
                ->count();

            $prevTotalRevenue = Order::where('shop_owner_id', $shopOwnerId)
                ->where('payment_status', 'paid')
                ->where('created_at', '<', now()->subDays(30))
                ->sum('total_amount');

            // Calculate percentage changes
            $totalCustomersChange = $prevTotalCustomers > 0 
                ? round((($totalCustomers - $prevTotalCustomers) / $prevTotalCustomers) * 100, 1)
                : ($totalCustomers > 0 ? 100 : 0);

            $activeCustomersChange = $prevActiveCustomers > 0 
                ? round((($activeCustomers - $prevActiveCustomers) / $prevActiveCustomers) * 100, 1)
                : ($activeCustomers > 0 ? 100 : 0);

            $totalOrdersChange = $prevTotalOrders > 0 
                ? round((($totalOrders - $prevTotalOrders) / $prevTotalOrders) * 100, 1)
                : ($totalOrders > 0 ? 100 : 0);

            $totalRevenueChange = $prevTotalRevenue > 0 
                ? round((($totalRevenue - $prevTotalRevenue) / $prevTotalRevenue) * 100, 1)
                : ($totalRevenue > 0 ? 100 : 0);

            return response()->json([
                'totalCustomers' => $totalCustomers,
                'totalCustomersChange' => $totalCustomersChange,
                'activeCustomers' => $activeCustomers,
                'activeCustomersChange' => $activeCustomersChange,
                'totalOrders' => $totalOrders,
                'totalOrdersChange' => $totalOrdersChange,
                'totalRevenue' => (float) $totalRevenue,
                'totalRevenueChange' => $totalRevenueChange,
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching customer stats: ' . $e->getMessage());
            return response()->json([
                'totalCustomers' => 0,
                'totalCustomersChange' => 0,
                'activeCustomers' => 0,
                'activeCustomersChange' => 0,
                'totalOrders' => 0,
                'totalOrdersChange' => 0,
                'totalRevenue' => 0,
                'totalRevenueChange' => 0,
            ], 500);
        }
    }
}
