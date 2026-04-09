<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PosRefund;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Inertia\Inertia;

class CustomerController extends Controller
{
    private function customerIdentityExpression(): string
    {
        return "CASE
            WHEN orders.customer_id IS NOT NULL THEN CONCAT('user:', orders.customer_id)
            WHEN COALESCE(NULLIF(orders.customer_email, ''), NULLIF(orders.customer_phone, ''), NULLIF(orders.customer_name, '')) IS NOT NULL
                THEN CONCAT('guest:', LOWER(COALESCE(NULLIF(orders.customer_email, ''), NULLIF(orders.customer_phone, ''), NULLIF(orders.customer_name, ''))))
            ELSE CONCAT('order:', orders.id)
        END";
    }

    private function buildCustomerAggregateQuery(int $shopOwnerId, ?Carbon $from = null, ?Carbon $to = null)
    {
        $identityExpr = $this->customerIdentityExpression();

        $baseQuery = Order::query()
            ->leftJoin('users', 'orders.customer_id', '=', 'users.id')
            ->where('orders.shop_owner_id', $shopOwnerId)
            ->select([
                DB::raw("{$identityExpr} as customer_identity"),
                'orders.id as order_id',
                'orders.customer_id',
                'orders.customer_name',
                'orders.customer_email',
                'orders.customer_phone',
                'orders.customer_address',
                'orders.total_amount',
                'orders.created_at as order_created_at',
                'users.name as user_name',
                'users.email as user_email',
                'users.phone as user_phone',
                'users.address as user_address',
                'users.created_at as user_created_at',
            ]);

        if ($from) {
            $baseQuery->where('orders.created_at', '>=', $from->copy()->startOfDay());
        }
        if ($to) {
            $baseQuery->where('orders.created_at', '<=', $to->copy()->endOfDay());
        }

        return DB::query()
            ->fromSub($baseQuery, 'customer_orders')
            ->select([
                'customer_identity',
                DB::raw('MAX(customer_id) as customer_id'),
                DB::raw("COALESCE(MAX(NULLIF(user_name, '')), MAX(NULLIF(customer_name, '')), 'Guest Customer') as name"),
                DB::raw("COALESCE(MAX(NULLIF(user_email, '')), MAX(NULLIF(customer_email, '')), 'N/A') as email"),
                DB::raw("COALESCE(MAX(NULLIF(user_phone, '')), MAX(NULLIF(customer_phone, '')), 'N/A') as phone"),
                DB::raw("COALESCE(MAX(NULLIF(user_address, '')), MAX(NULLIF(customer_address, '')), 'N/A') as address"),
                DB::raw('COUNT(DISTINCT order_id) as total_orders'),
                DB::raw('COALESCE(SUM(total_amount), 0) as total_spent'),
                DB::raw('MAX(order_created_at) as last_order_date'),
                DB::raw('MIN(COALESCE(user_created_at, order_created_at)) as first_seen_date'),
            ])
            ->groupBy('customer_identity');
    }

    private function mapCustomerAggregate($customer): array
    {
        $lastOrderDate = $customer->last_order_date ? Carbon::parse($customer->last_order_date) : null;
        $firstSeenDate = $customer->first_seen_date ? Carbon::parse($customer->first_seen_date) : $lastOrderDate;
        $status = $lastOrderDate && $lastOrderDate->greaterThan(Carbon::now()->subDays(90))
            ? 'active'
            : 'inactive';

        $fallbackId = 0 - abs((int) sprintf('%u', crc32((string) ($customer->customer_identity ?? 'guest'))));

        return [
            'id' => (int) ($customer->customer_id ?: $fallbackId),
            'name' => (string) ($customer->name ?? 'Guest Customer'),
            'email' => (string) ($customer->email ?? 'N/A'),
            'phone' => (string) ($customer->phone ?? 'N/A'),
            'address' => (string) ($customer->address ?? 'N/A'),
            'status' => $status,
            'totalOrders' => (int) ($customer->total_orders ?? 0),
            'totalSpent' => (float) ($customer->total_spent ?? 0.0),
            'lastOrderDate' => $lastOrderDate ? $lastOrderDate->format('Y-m-d') : 'N/A',
            'createdAt' => $firstSeenDate ? $firstSeenDate->format('Y-m-d') : Carbon::now()->format('Y-m-d'),
        ];
    }

    private function getSucceededRefundAmountByOrder(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $onlineRefundByOrder = [];
        $onlineRefunds = OrderRefund::query()
            ->select(['id', 'order_id', 'amount'])
            ->whereIn('order_id', $orderIds)
            ->where('status', 'succeeded')
            ->get();

        if ($onlineRefunds->isNotEmpty()) {
            $lineRefundByRefundId = collect();

            if (Schema::hasTable('order_refund_items')) {
                $lineRefundByRefundId = DB::table('order_refund_items')
                    ->select('order_refund_id', DB::raw('SUM(COALESCE(line_amount, 0)) as line_total'))
                    ->whereIn('order_refund_id', $onlineRefunds->pluck('id')->all())
                    ->groupBy('order_refund_id')
                    ->pluck('line_total', 'order_refund_id');
            }

            foreach ($onlineRefunds as $refund) {
                $lineAmount = (float) ($lineRefundByRefundId[$refund->id] ?? 0.0);
                $effectiveRefundAmount = $lineAmount > 0
                    ? $lineAmount
                    : (float) ($refund->amount ?? 0.0);

                if ($effectiveRefundAmount <= 0) {
                    continue;
                }

                $orderId = (int) ($refund->order_id ?? 0);
                if ($orderId <= 0) {
                    continue;
                }

                $onlineRefundByOrder[$orderId] = (float) (($onlineRefundByOrder[$orderId] ?? 0.0) + $effectiveRefundAmount);
            }
        }

        $posRefundByOrder = [];
        if (Schema::hasTable('pos_refunds')) {
            $posRefundRows = PosRefund::query()
                ->select('module_reference_id')
                ->selectRaw('SUM(COALESCE(approved_amount, requested_amount, 0)) as total_refunded')
                ->where('module_type', 'retail')
                ->where('status', 'succeeded')
                ->whereIn('module_reference_id', $orderIds)
                ->groupBy('module_reference_id')
                ->get();

            foreach ($posRefundRows as $row) {
                $moduleReferenceId = (int) ($row->module_reference_id ?? 0);
                if ($moduleReferenceId <= 0) {
                    continue;
                }

                $posRefundByOrder[$moduleReferenceId] = (float) ($row->total_refunded ?? 0.0);
            }
        }

        $combined = [];
        foreach ($orderIds as $orderId) {
            $id = (int) $orderId;
            if ($id <= 0) {
                continue;
            }

            $combined[$id] = round(
                max(0.0, (float) ($onlineRefundByOrder[$id] ?? 0.0) + (float) ($posRefundByOrder[$id] ?? 0.0)),
                2,
            );
        }

        return $combined;
    }

    private function computeRetailNetRevenue(int $shopOwnerId, ?Carbon $from = null, ?Carbon $to = null): float
    {
        $ordersQuery = Order::query()
            ->select('id', 'total_amount')
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', '!=', 'cancelled');

        if ($from) {
            $ordersQuery->where('created_at', '>=', $from->copy()->startOfDay());
        }
        if ($to) {
            $ordersQuery->where('created_at', '<=', $to->copy()->endOfDay());
        }

        $orders = $ordersQuery->get();
        if ($orders->isEmpty()) {
            return 0.0;
        }

        $orderIds = $orders->pluck('id')->map(fn ($id) => (int) $id)->all();
        $refundAmountByOrder = $this->getSucceededRefundAmountByOrder($orderIds);

        $netRevenue = 0.0;
        foreach ($orders as $order) {
            $orderId = (int) ($order->id ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $grossAmount = max(0.0, (float) ($order->total_amount ?? 0.0));
            if ($grossAmount <= 0) {
                continue;
            }

            $totalRefunded = max(0.0, (float) ($refundAmountByOrder[$orderId] ?? 0.0));
            $netRevenue += max(0.0, $grossAmount - min($grossAmount, $totalRefunded));
        }

        return round($netRevenue, 2);
    }

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
            return Inertia::render('ERP/STAFF/Customers', [
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

        // Build customer summaries from order data so POS walk-in customers are included.
        $customers = $this->buildCustomerAggregateQuery($shopOwnerId)
            ->get()
            ->map(fn ($customer) => $this->mapCustomerAggregate($customer))
            ->sortByDesc('lastOrderDate')
            ->values();

        // Calculate stats
        $totalCustomers = $customers->count();
        $activeCustomers = $customers->where('status', 'active')->count();
        $totalOrders = Order::where('shop_owner_id', $shopOwnerId)->count();
        $totalRevenue = $this->computeRetailNetRevenue($shopOwnerId);

        // Previous period for comparison (last 30 days)
        $prevTotalCustomers = $this->buildCustomerAggregateQuery(
            $shopOwnerId,
            now()->subDays(60),
            now()->subDays(30),
        )->get()->count();

        $prevActiveCustomers = $this->buildCustomerAggregateQuery(
            $shopOwnerId,
            now()->subDays(120),
            now()->subDays(90),
        )->get()->count();

        $prevTotalOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])
            ->count();

        $prevTotalRevenue = $this->computeRetailNetRevenue(
            $shopOwnerId,
            now()->subDays(60),
            now()->subDays(30),
        );

        // Calculate percentage changes (handle division by zero)
        $calculateChange = function($current, $previous) {
            if ($previous == 0) {
                return $current > 0 ? 100 : 0;
            }
            return round((($current - $previous) / $previous) * 100, 1);
        };

        $stats = [
            'totalCustomers' => $totalCustomers,
            'totalCustomersChange' => $calculateChange($totalCustomers, $prevTotalCustomers),
            'activeCustomers' => $activeCustomers,
            'activeCustomersChange' => $calculateChange($activeCustomers, $prevActiveCustomers),
            'totalOrders' => $totalOrders,
            'totalOrdersChange' => $calculateChange($totalOrders, $prevTotalOrders),
            'totalRevenue' => (float) $totalRevenue,
            'totalRevenueChange' => $calculateChange($totalRevenue, $prevTotalRevenue),
        ];

        return Inertia::render('ERP/STAFF/Customers', [
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

        $staffPermissions = [
            'access-staff-dashboard',
            'access-staff-customers',
        ];

        if (!$user || (!in_array($user->role, ['STAFF', 'MANAGER']) && !$user->hasAnyPermission($staffPermissions))) {
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

        $customers = $this->buildCustomerAggregateQuery($shopOwnerId)
            ->get()
            ->map(fn ($customer) => $this->mapCustomerAggregate($customer));

        if ($searchTerm) {
            $normalizedSearch = strtolower($searchTerm);

            $customers = $customers->filter(function ($customer) use ($normalizedSearch) {
                return str_contains(strtolower((string) $customer['name']), $normalizedSearch)
                    || str_contains(strtolower((string) $customer['email']), $normalizedSearch)
                    || str_contains((string) $customer['phone'], $normalizedSearch);
            })->values();
        }

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
        $totalCustomers = $this->buildCustomerAggregateQuery($shopOwnerId)
            ->get()
            ->count();

        // Get active customers (has orders in last 30 days)
        $activeCustomers = $this->buildCustomerAggregateQuery(
            $shopOwnerId,
            Carbon::now()->subDays(30),
            Carbon::now(),
        )->get()->count();

        // Get total orders
        $totalOrders = Order::where('shop_owner_id', $shopOwnerId)->count();

        // Get total revenue (net of succeeded refunds)
        $totalRevenue = $this->computeRetailNetRevenue($shopOwnerId);

        // Previous month stats for comparison
        $lastMonthCustomers = $this->buildCustomerAggregateQuery(
            $shopOwnerId,
            Carbon::now()->subMonths(2)->startOfMonth(),
            Carbon::now()->subMonth()->endOfMonth(),
        )->get()->count();

        $lastMonthOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->whereBetween('created_at', [
                Carbon::now()->subMonth()->startOfMonth(),
                Carbon::now()->subMonth()->endOfMonth()
            ])
            ->count();

        $lastMonthRevenue = $this->computeRetailNetRevenue(
            $shopOwnerId,
            Carbon::now()->subMonth()->startOfMonth(),
            Carbon::now()->subMonth()->endOfMonth(),
        );

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
