<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Decision Support System (DSS) Controller
 *
 * Provides actionable analytics for Shop Owners and ERP Managers covering:
 *   1. Workload & Capacity analysis
 *   2. Service Revenue & Pricing analysis
 *
 * Access:  auth:shop_owner  OR  auth:user (role Manager)
 */
class DssController extends Controller
{
    // ─── status constants ───────────────────────────────────────────────────────

    private const COMPLETED_STATUSES = [
        'completed',
        'ready_for_pickup',
        'ready-for-pickup',
        'picked_up',
    ];

    private const ACTIVE_STATUSES = [
        'pending',
        'received',
        'assigned_to_repairer',
        'repairer_accepted',
        'in-progress',
        'in_progress',
        'awaiting_parts',
        'waiting_customer_confirmation',
        'completed',
        'ready-for-pickup',
        'ready_for_pickup',
    ];

    private const RETAIL_COMPLETED_STATUSES = ['completed', 'delivered'];
    private const RETAIL_ACTIVE_STATUSES    = ['pending', 'processing', 'shipped'];

    // ─── helpers ────────────────────────────────────────────────────────────────

    /**
     * Resolve the authenticated shop owner's ID.
     * Supports both the shop_owner guard and ERP user guard (manager).
     */
    private function resolveShopOwnerId(): ?int
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        if ($shopOwner) {
            return $shopOwner->id;
        }

        $erpUser = Auth::guard('user')->user();
        if ($erpUser && $erpUser->shop_owner_id) {
            return (int) $erpUser->shop_owner_id;
        }

        return null;
    }

    private function resolveShopOwner(int $shopOwnerId): ?ShopOwner
    {
        return ShopOwner::find($shopOwnerId);
    }

    // ─── main endpoint ──────────────────────────────────────────────────────────

    /**
     * GET /api/shop-owner/dashboard/dss-insights
     * GET /api/erp/manager/dss-insights
     */
    public function getInsights(Request $request)
    {
        $shopOwnerId = $this->resolveShopOwnerId();

        if (!$shopOwnerId) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $shopOwner      = $this->resolveShopOwner($shopOwnerId);
        $workloadLimit  = (int) ($shopOwner?->repair_workload_limit ?? 20);
        $businessType   = $shopOwner?->business_type ?? 'repair'; // 'repair', 'retail', 'both'

        $period = (int) $request->input('period', 30); // days: 7, 30, 90, 365

        $hasRepair = in_array($businessType, ['repair', 'both']);
        $hasRetail = in_array($businessType, ['retail', 'both']);

        $response = [
            'business_type'  => $businessType,
            'workload_limit' => $workloadLimit,
            'period_days'    => $period,
        ];

        if ($hasRepair) {
            $response['workload']      = $this->workloadMetrics($shopOwnerId, $workloadLimit, $period);
            $response['services']      = $this->serviceMetrics($shopOwnerId, $period);
            $response['monthly_trend'] = $this->monthlyTrend($shopOwnerId);
        }

        if ($hasRetail) {
            $response['retail_sales']       = $this->retailSalesMetrics($shopOwnerId, $period);
            $response['retail_products']    = $this->productPerformanceMetrics($shopOwnerId, $period);
            $response['retail_trend']       = $this->retailMonthlyTrend($shopOwnerId);
        }

        $response['recommendations'] = $this->generateRecommendations(
            $shopOwnerId, $workloadLimit, $period, $hasRepair, $hasRetail
        );

        return response()->json($response);
    }

    // ─── workload & capacity ────────────────────────────────────────────────────

    private function workloadMetrics(int $shopOwnerId, int $workloadLimit, int $period): array
    {
        $now = Carbon::now();

        // Current active repairs
        $activeCount = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->count();

        $utilization = $workloadLimit > 0
            ? round(($activeCount / $workloadLimit) * 100, 1)
            : 0;

        // Average completion time in days (last 90 days of completed jobs)
        $avgDaysRaw = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $now->copy()->subDays(90))
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, started_at, completed_at)) as avg_hours')
            ->value('avg_hours');

        $avgDays  = $avgDaysRaw ? round($avgDaysRaw / 24, 1) : null;
        $avgHours = $avgDaysRaw ? round($avgDaysRaw, 0) : null;

        // Intake rate — new requests per day over the period
        $intakeTotal = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->where('created_at', '>=', $now->copy()->subDays($period))
            ->count();
        $intakeRate = $period > 0 ? round($intakeTotal / $period, 2) : 0;

        // Throughput — completions per day over the period
        $completedTotal = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->where('completed_at', '>=', $now->copy()->subDays($period))
            ->count();
        $throughput = $period > 0 ? round($completedTotal / $period, 2) : 0;

        // Completion rate for the period
        $completionRate = $intakeTotal > 0
            ? round(($completedTotal / $intakeTotal) * 100, 1)
            : null;

        // Daily utilization trend — last 14 days
        $dailyTrend = $this->dailyUtilizationTrend($shopOwnerId, $workloadLimit, 14);

        // Overdue repairs (active > 2× the average completion time, or > 14 days old)
        $overdueThreshold = $avgDays ? max($avgDays * 2, 7) : 14;
        $overdueCount = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', array_diff(self::ACTIVE_STATUSES, self::COMPLETED_STATUSES))
            ->where('created_at', '<=', $now->copy()->subDays($overdueThreshold))
            ->count();

        // Total repairs this month and last month (for MoM change)
        $thisMonthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd   = $now->copy()->subMonth()->endOfMonth();

        $thisMonthCount = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->where('created_at', '>=', $thisMonthStart)->count();

        $lastMonthCount = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->where('created_at', '>=', $lastMonthStart)
            ->where('created_at', '<=', $lastMonthEnd)->count();

        $momChange = $lastMonthCount > 0
            ? round((($thisMonthCount - $lastMonthCount) / $lastMonthCount) * 100, 1)
            : null;

        return [
            'active_count'      => $activeCount,
            'workload_limit'    => $workloadLimit,
            'utilization_pct'   => $utilization,
            'avg_days'          => $avgDays,
            'avg_hours'         => $avgHours,
            'intake_rate'       => $intakeRate,         // per day
            'throughput'        => $throughput,         // per day
            'intake_total'      => $intakeTotal,
            'completed_total'   => $completedTotal,
            'completion_rate'   => $completionRate,
            'overdue_count'     => $overdueCount,
            'mom_change'        => $momChange,          // month-over-month % change
            'daily_trend'       => $dailyTrend,
        ];
    }

    /**
     * Build a 14-day history of daily active repair counts.
     * Uses snapshots approximated from created_at + status logic.
     */
    private function dailyUtilizationTrend(int $shopOwnerId, int $limit, int $days): array
    {
        $result = [];
        $now    = Carbon::now();

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $now->copy()->subDays($i)->toDateString();

            // Count repairs that were open on this date:
            //  created on or before this date AND (still active OR completed after this date)
            $dayEnd = Carbon::parse($date)->endOfDay();

            $count = RepairRequest::where('shop_owner_id', $shopOwnerId)
                ->where('created_at', '<=', $dayEnd)
                ->where(function ($q) use ($dayEnd) {
                    $q->whereIn('status', array_diff(self::ACTIVE_STATUSES, self::COMPLETED_STATUSES))
                      ->orWhere(function ($q2) use ($dayEnd) {
                          $q2->whereIn('status', self::COMPLETED_STATUSES)
                             ->where('completed_at', '>', $dayEnd);
                      });
                })
                ->count();

            $result[] = [
                'date'        => $date,
                'active'      => $count,
                'utilization' => $limit > 0 ? round(($count / $limit) * 100, 1) : 0,
            ];
        }

        return $result;
    }

    // ─── service revenue ────────────────────────────────────────────────────────

    private function serviceMetrics(int $shopOwnerId, int $period): array
    {
        $since = Carbon::now()->subDays($period);

        // Revenue + demand per service for completed paid repairs in the period
        $rows = DB::table('repair_request_service as rrs')
            ->join('repair_services as rs', 'rrs.repair_service_id', '=', 'rs.id')
            ->join('repair_requests as rr', 'rrs.repair_request_id', '=', 'rr.id')
            ->where('rr.shop_owner_id', $shopOwnerId)
            ->whereIn('rr.status', self::COMPLETED_STATUSES)
            ->where('rr.payment_status', 'completed')
            ->where('rr.completed_at', '>=', $since)
            ->select(
                'rs.id',
                'rs.name',
                DB::raw('COALESCE(rs.price, 0) as list_price'),
                DB::raw('COUNT(DISTINCT rr.id) as request_count'),
                DB::raw('SUM(rr.total) as total_revenue')
            )
            ->groupBy('rs.id', 'rs.name', 'rs.price')
            ->orderByDesc('total_revenue')
            ->get();

        // All-time totals per service for demand ranking
        $allTimeRows = DB::table('repair_request_service as rrs')
            ->join('repair_services as rs', 'rrs.repair_service_id', '=', 'rs.id')
            ->join('repair_requests as rr', 'rrs.repair_request_id', '=', 'rr.id')
            ->where('rr.shop_owner_id', $shopOwnerId)
            ->whereIn('rr.status', self::COMPLETED_STATUSES)
            ->select('rs.id', DB::raw('COUNT(DISTINCT rr.id) as all_time_count'))
            ->groupBy('rs.id')
            ->get()
            ->keyBy('id');

        $services = [];
        $totalRevenue  = $rows->sum('total_revenue');
        $maxDemand     = $rows->max('request_count') ?: 1;

        foreach ($rows as $row) {
            $avgRevPerRequest = $row->request_count > 0
                ? round($row->total_revenue / $row->request_count, 2)
                : 0;

            $revShare = $totalRevenue > 0
                ? round(($row->total_revenue / $totalRevenue) * 100, 1)
                : 0;

            $demandScore = round(($row->request_count / $maxDemand) * 100);

            // Pricing signal: high demand (>= 75th percentile) but low avg revenue
            $avgListPrice   = $row->list_price ?? 0;
            $priceGap       = $avgListPrice > 0
                ? round((($avgRevPerRequest - $avgListPrice) / $avgListPrice) * 100, 1)
                : null;

            $services[] = [
                'id'                  => $row->id,
                'name'                => $row->name,
                'list_price'          => (float) $row->list_price,
                'request_count'       => (int) $row->request_count,
                'total_revenue'       => (float) $row->total_revenue,
                'avg_revenue_per_job' => $avgRevPerRequest,
                'revenue_share_pct'   => $revShare,
                'demand_score'        => $demandScore,
                'price_gap_pct'       => $priceGap,
                'all_time_count'      => (int) ($allTimeRows[$row->id]->all_time_count ?? 0),
            ];
        }

        // Summary revenue card
        $periodRevenue = $rows->sum('total_revenue');

        // Revenue this month vs last month
        $thisMonthRevenue = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->where('payment_status', 'completed')
            ->whereBetween('completed_at', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ])
            ->sum('total');

        $lastMonthRevenue = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->where('payment_status', 'completed')
            ->whereBetween('completed_at', [
                Carbon::now()->subMonth()->startOfMonth(),
                Carbon::now()->subMonth()->endOfMonth(),
            ])
            ->sum('total');

        $revMomChange = $lastMonthRevenue > 0
            ? round((($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1)
            : null;

        return [
            'period_revenue'       => (float) $periodRevenue,
            'this_month_revenue'   => (float) $thisMonthRevenue,
            'last_month_revenue'   => (float) $lastMonthRevenue,
            'rev_mom_change'       => $revMomChange,
            'total_services'       => count($services),
            'services'             => $services,
        ];
    }

    // ─── monthly trend ──────────────────────────────────────────────────────────

    private function monthlyTrend(int $shopOwnerId): array
    {
        $months = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $start = $month->copy()->startOfMonth();
            $end   = $month->copy()->endOfMonth();

            $intake = RepairRequest::where('shop_owner_id', $shopOwnerId)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $completed = RepairRequest::where('shop_owner_id', $shopOwnerId)
                ->whereIn('status', self::COMPLETED_STATUSES)
                ->whereBetween('completed_at', [$start, $end])
                ->count();

            $revenue = RepairRequest::where('shop_owner_id', $shopOwnerId)
                ->whereIn('status', self::COMPLETED_STATUSES)
                ->where('payment_status', 'completed')
                ->whereBetween('completed_at', [$start, $end])
                ->sum('total');

            $months[] = [
                'month'     => $month->format('M Y'),
                'month_key' => $month->format('Y-m'),
                'intake'    => $intake,
                'completed' => $completed,
                'revenue'   => (float) $revenue,
            ];
        }

        return $months;
    }

    // ─── retail analytics ────────────────────────────────────────────────────────

    /**
     * Aggregate retail order & revenue KPIs for the given period.
     */
    private function retailSalesMetrics(int $shopOwnerId, int $period): array
    {
        $now        = Carbon::now();
        $periodStart = $now->copy()->subDays($period);

        // Period totals
        $totalOrders     = Order::where('shop_owner_id', $shopOwnerId)
            ->where('created_at', '>=', $periodStart)
            ->count();

        $completedOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', self::RETAIL_COMPLETED_STATUSES)
            ->where('created_at', '>=', $periodStart)
            ->count();

        $activeOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', self::RETAIL_ACTIVE_STATUSES)
            ->count();

        $periodRevenue = Order::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', self::RETAIL_COMPLETED_STATUSES)
            ->where('created_at', '>=', $periodStart)
            ->sum('total_amount');

        // Month-over-month
        $thisMonthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd   = $now->copy()->subMonth()->endOfMonth();

        $thisMonthRevenue = Order::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', self::RETAIL_COMPLETED_STATUSES)
            ->where('created_at', '>=', $thisMonthStart)
            ->sum('total_amount');

        $lastMonthRevenue = Order::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', self::RETAIL_COMPLETED_STATUSES)
            ->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])
            ->sum('total_amount');

        $revMomChange = ($lastMonthRevenue > 0)
            ? round((($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1)
            : null;

        $avgOrderValue   = $completedOrders > 0 ? round($periodRevenue / $completedOrders, 2) : 0;
        $completionRate  = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 1) : null;

        // Unique customers
        $uniqueCustomers = Order::where('shop_owner_id', $shopOwnerId)
            ->where('created_at', '>=', $periodStart)
            ->distinct('customer_name')
            ->count('customer_name');

        return [
            'total_orders'        => $totalOrders,
            'completed_orders'    => $completedOrders,
            'active_orders'       => $activeOrders,
            'period_revenue'      => (float) $periodRevenue,
            'this_month_revenue'  => (float) $thisMonthRevenue,
            'last_month_revenue'  => (float) $lastMonthRevenue,
            'rev_mom_change'      => $revMomChange,
            'avg_order_value'     => (float) $avgOrderValue,
            'completion_rate'     => $completionRate,
            'unique_customers'    => $uniqueCustomers,
        ];
    }

    /**
     * Top-selling products and low-stock alerts for the period.
     */
    private function productPerformanceMetrics(int $shopOwnerId, int $period): array
    {
        $now         = Carbon::now();
        $periodStart = $now->copy()->subDays($period);

        // Top products by revenue in period (via order_items → orders)
        $topProducts = DB::table('order_items as oi')
            ->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->where('o.shop_owner_id', $shopOwnerId)
            ->whereIn('o.status', self::RETAIL_COMPLETED_STATUSES)
            ->where('o.created_at', '>=', $periodStart)
            ->select(
                'oi.product_name',
                DB::raw('SUM(oi.quantity) as total_qty'),
                DB::raw('SUM(oi.subtotal) as total_revenue'),
                DB::raw('AVG(oi.price) as avg_price'),
                DB::raw('COUNT(DISTINCT o.id) as order_count')
            )
            ->groupBy('oi.product_name')
            ->orderByDesc('total_revenue')
            ->limit(10)
            ->get();

        $grandRevenue = $topProducts->sum('total_revenue');

        $topProductsFormatted = $topProducts->map(function ($p) use ($grandRevenue) {
            return [
                'product_name'      => $p->product_name,
                'total_qty'         => (int) $p->total_qty,
                'order_count'       => (int) $p->order_count,
                'total_revenue'     => (float) $p->total_revenue,
                'avg_price'         => round((float) $p->avg_price, 2),
                'revenue_share_pct' => $grandRevenue > 0
                    ? round(($p->total_revenue / $grandRevenue) * 100, 1)
                    : 0,
            ];
        })->values()->toArray();

        // Low-stock active products (≤ 5 units)
        $lowStock = Product::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->where('stock_quantity', '<=', 5)
            ->select('id', 'name', 'stock_quantity', 'price', 'category')
            ->orderBy('stock_quantity')
            ->limit(10)
            ->get()
            ->map(fn($p) => [
                'id'             => $p->id,
                'name'           => $p->name,
                'stock_quantity' => $p->stock_quantity,
                'price'          => (float) $p->price,
                'category'       => $p->category,
            ])
            ->values()
            ->toArray();

        // Total distinct products sold in period
        $totalProductsSold = DB::table('order_items as oi')
            ->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->where('o.shop_owner_id', $shopOwnerId)
            ->whereIn('o.status', self::RETAIL_COMPLETED_STATUSES)
            ->where('o.created_at', '>=', $periodStart)
            ->distinct('oi.product_name')
            ->count('oi.product_name');

        // Active products with zero sales in period
        $activeProdCount = Product::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->count();

        return [
            'top_products'        => $topProductsFormatted,
            'low_stock_products'  => $lowStock,
            'total_products_sold' => $totalProductsSold,
            'active_products'     => $activeProdCount,
        ];
    }

    /**
     * 12-month retail order volume + revenue trend.
     */
    private function retailMonthlyTrend(int $shopOwnerId): array
    {
        $months = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $start = $month->copy()->startOfMonth();
            $end   = $month->copy()->endOfMonth();

            $orders = Order::where('shop_owner_id', $shopOwnerId)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $completed = Order::where('shop_owner_id', $shopOwnerId)
                ->whereIn('status', self::RETAIL_COMPLETED_STATUSES)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $revenue = Order::where('shop_owner_id', $shopOwnerId)
                ->whereIn('status', self::RETAIL_COMPLETED_STATUSES)
                ->whereBetween('created_at', [$start, $end])
                ->sum('total_amount');

            $months[] = [
                'month'     => $month->format('M Y'),
                'month_key' => $month->format('Y-m'),
                'orders'    => $orders,
                'completed' => $completed,
                'revenue'   => (float) $revenue,
            ];
        }

        return $months;
    }

    // ─── rule-based recommendations ─────────────────────────────────────────────

    private function generateRecommendations(
        int $shopOwnerId,
        int $workloadLimit,
        int $period,
        bool $hasRepair = true,
        bool $hasRetail = false
    ): array
    {
        $recs = [];

        // ── re-compute key metrics ──
        $now = Carbon::now();

        $activeCount = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->count();

        $utilization = $workloadLimit > 0
            ? ($activeCount / $workloadLimit) * 100
            : 0;

        $avgHours = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $now->copy()->subDays(90))
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, started_at, completed_at)) as avg_hours')
            ->value('avg_hours');

        $avgDays = $avgHours ? ($avgHours / 24) : null;

        $intakeTotal = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->where('created_at', '>=', $now->copy()->subDays($period))
            ->count();

        $completedTotal = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->where('completed_at', '>=', $now->copy()->subDays($period))
            ->count();

        $intakeRate  = $period > 0 ? $intakeTotal / $period : 0;
        $throughput  = $period > 0 ? $completedTotal / $period : 0;

        $overdueCount = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', array_diff(self::ACTIVE_STATUSES, self::COMPLETED_STATUSES))
            ->where('created_at', '<=', $now->copy()->subDays(14))
            ->count();

        // Count high-utilization days in last 14 days (>= 90 %)
        $highUtilDays = 0;
        if ($workloadLimit > 0) {
            for ($i = 1; $i <= 14; $i++) {
                $dayEnd = $now->copy()->subDays($i)->endOfDay();
                $c = RepairRequest::where('shop_owner_id', $shopOwnerId)
                    ->where('created_at', '<=', $dayEnd)
                    ->where(function ($q) use ($dayEnd) {
                        $q->whereIn('status', array_diff(self::ACTIVE_STATUSES, self::COMPLETED_STATUSES))
                          ->orWhere(function ($q2) use ($dayEnd) {
                              $q2->whereIn('status', self::COMPLETED_STATUSES)
                                 ->where('completed_at', '>', $dayEnd);
                          });
                    })
                    ->count();
                if ($c >= $workloadLimit * 0.9) {
                    $highUtilDays++;
                }
            }
        }

        // ─── rule set ───────────────────────────────────────────────────────────

        // R1: Consistently at capacity → raise limit or hire
        if ($utilization >= 90 && $highUtilDays >= 5) {
            $suggestedLimit = (int) ceil($workloadLimit * 1.3);
            $recs[] = [
                'id'       => 'R1',
                'severity' => 'critical',
                'title'    => 'Shop Running at Full Capacity',
                'message'  => "You have been at or above 90% capacity for {$highUtilDays} of the last 14 days. "
                             . "Consider raising your workload limit to {$suggestedLimit} or assigning an additional repairer "
                             . "to avoid customer wait-time complaints.",
                'action'   => 'Increase workload limit in Shop Settings → Repair Settings.',
            ];
        }

        // R2: High utilization AND slow completion → lower limit
        if ($utilization >= 75 && $avgDays !== null && $avgDays > 5) {
            $days = round($avgDays, 1);
            $suggestedLimit = max(5, (int) floor($workloadLimit * 0.8));
            $recs[] = [
                'id'       => 'R2',
                'severity' => 'warning',
                'title'    => 'Repairs Are Taking Too Long',
                'message'  => "Average completion time is {$days} days while the shop is at {$utilization}% capacity. "
                             . "Reducing the limit to {$suggestedLimit} would keep queues manageable "
                             . "and protect service quality.",
                'action'   => 'Reduce workload limit or reassign complex jobs.',
            ];
        }

        // R3: Low utilization → limit may be set too high
        if ($utilization < 40 && $workloadLimit > 5 && $intakeTotal > 0) {
            $recs[] = [
                'id'       => 'R3',
                'severity' => 'info',
                'title'    => 'Workload Limit Higher Than Needed',
                'message'  => "Current utilization is only " . round($utilization, 1) . "% of the {$workloadLimit} repair limit. "
                             . "Your limit may be set too high, giving customers an inaccurate picture of wait times.",
                'action'   => 'Review and lower the workload limit in Shop Settings.',
            ];
        }

        // R4: Intake consistently exceeds throughput → backlog growing
        if ($intakeRate > 0 && $throughput > 0 && $intakeRate > $throughput * 1.2) {
            $surplus = round($intakeRate - $throughput, 2);
            $recs[] = [
                'id'       => 'R4',
                'severity' => 'warning',
                'title'    => 'Backlog Is Growing',
                'message'  => "Over the last {$period} days, you take in " . round($intakeRate, 2) . " repairs/day "
                             . "but only complete " . round($throughput, 2) . " — a surplus of {$surplus}/day. "
                             . "At this rate, wait times will continue to increase.",
                'action'   => 'Speed up completions or temporarily slow intake by reducing the workload limit.',
            ];
        }

        // R5: Overdue repairs piling up
        if ($overdueCount >= 3) {
            $recs[] = [
                'id'       => 'R5',
                'severity' => 'critical',
                'title'    => "{$overdueCount} Overdue Repairs",
                'message'  => "There are {$overdueCount} repairs that have been active for more than 14 days. "
                             . "These are at risk of customer escalation.",
                'action'   => 'Review Job Orders → Repair and prioritise long-standing tickets.',
            ];
        }

        // R6: No data / new shop
        if ($intakeTotal === 0) {
            $recs[] = [
                'id'       => 'R6',
                'severity' => 'info',
                'title'    => 'No Repair Data Yet',
                'message'  => "No repair requests found in the last {$period} days. "
                             . "DSS recommendations will appear once you start receiving repair jobs.",
                'action'   => null,
            ];
        }

        // ─── service pricing rules ───────────────────────────────────────────────

        $serviceRows = DB::table('repair_request_service as rrs')
            ->join('repair_services as rs', 'rrs.repair_service_id', '=', 'rs.id')
            ->join('repair_requests as rr', 'rrs.repair_request_id', '=', 'rr.id')
            ->where('rr.shop_owner_id', $shopOwnerId)
            ->whereIn('rr.status', self::COMPLETED_STATUSES)
            ->where('rr.payment_status', 'completed')
            ->where('rr.completed_at', '>=', $now->copy()->subDays(90))
            ->select(
                'rs.id',
                'rs.name',
                DB::raw('COALESCE(rs.price, 0) as list_price'),
                DB::raw('COUNT(DISTINCT rr.id) as cnt'),
                DB::raw('SUM(rr.total) as rev')
            )
            ->groupBy('rs.id', 'rs.name', 'rs.price')
            ->having('cnt', '>=', 3) // Only flag services with enough data
            ->get();

        if ($serviceRows->count() >= 4) {
            $maxCnt = $serviceRows->max('cnt');
            $p75Threshold = $serviceRows->sortByDesc('cnt')->values()->get((int) floor($serviceRows->count() * 0.25))?->cnt ?? 0;

            foreach ($serviceRows as $svc) {
                $avgRev = $svc->cnt > 0 ? $svc->rev / $svc->cnt : 0;
                $listPrice = (float) $svc->list_price;

                // Underpriced: high demand (top 25% by count) AND avg revenue < list price
                if ($svc->cnt >= $p75Threshold && $listPrice > 0 && $avgRev < $listPrice * 0.95) {
                    $gap = round((($listPrice - $avgRev) / $listPrice) * 100, 1);
                    $recs[] = [
                        'id'       => 'P-' . $svc->id,
                        'severity' => 'info',
                        'title'    => "Consider Reviewing Pricing: {$svc->name}",
                        'message'  => "\"{$svc->name}\" is your #{$svc->cnt}-request service in 90 days "
                                     . "but earns ₱" . number_format($avgRev, 0) . " avg per job "
                                     . "vs. a listed price of ₱" . number_format($listPrice, 0) . " ({$gap}% gap).",
                        'action'   => 'Review pricing in Services Uploader.',
                    ];
                }

                // High-value but low demand → needs marketing
                if ($listPrice >= 500 && $svc->cnt <= 2 && $maxCnt > 10) {
                    $recs[] = [
                        'id'       => 'M-' . $svc->id,
                        'severity' => 'info',
                        'title'    => "Low Visibility: {$svc->name}",
                        'message'  => "\"{$svc->name}\" (₱" . number_format($listPrice, 0) . ") has only {$svc->cnt} completed "
                                     . "requests in 90 days. Consider promoting this service.",
                        'action'   => 'Feature this service in your shop profile or offer introductory pricing.',
                    ];
                }
            }
        }

        // ─── retail rule set ─────────────────────────────────────────────────────

        if ($hasRetail) {

            $retailNow    = Carbon::now();
            $retailStart  = $retailNow->copy()->subDays($period);

            $retailOrders    = Order::where('shop_owner_id', $shopOwnerId)
                ->where('created_at', '>=', $retailStart)
                ->count();
            $retailCompleted = Order::where('shop_owner_id', $shopOwnerId)
                ->whereIn('status', self::RETAIL_COMPLETED_STATUSES)
                ->where('created_at', '>=', $retailStart)
                ->count();
            $retailRevenue   = Order::where('shop_owner_id', $shopOwnerId)
                ->whereIn('status', self::RETAIL_COMPLETED_STATUSES)
                ->where('created_at', '>=', $retailStart)
                ->sum('total_amount');

            $thisMonthRevenue = Order::where('shop_owner_id', $shopOwnerId)
                ->whereIn('status', self::RETAIL_COMPLETED_STATUSES)
                ->where('created_at', '>=', $retailNow->copy()->startOfMonth())
                ->sum('total_amount');
            $lastMonthRevenue = Order::where('shop_owner_id', $shopOwnerId)
                ->whereIn('status', self::RETAIL_COMPLETED_STATUSES)
                ->whereBetween('created_at', [
                    $retailNow->copy()->subMonth()->startOfMonth(),
                    $retailNow->copy()->subMonth()->endOfMonth(),
                ])
                ->sum('total_amount');

            $retailCompletionRate = $retailOrders > 0 ? ($retailCompleted / $retailOrders) * 100 : null;

            // RT1: No retail activity
            if ($retailOrders === 0) {
                $recs[] = [
                    'id'       => 'RT1',
                    'severity' => 'info',
                    'title'    => 'No Retail Orders Yet',
                    'message'  => "No retail orders were recorded in the last {$period} days. "
                                 . "Make sure your products are listed and visible to customers.",
                    'action'   => 'Check Products → ensure items are active and have stock.',
                ];
            }

            // RT2: Low completion rate (many orders not fulfilled)
            if ($retailCompletionRate !== null && $retailCompletionRate < 60 && $retailOrders >= 5) {
                $rate = round($retailCompletionRate, 1);
                $recs[] = [
                    'id'       => 'RT2',
                    'severity' => 'warning',
                    'title'    => 'Low Order Completion Rate',
                    'message'  => "Only {$rate}% of {$retailOrders} retail orders in the last {$period} days "
                                 . "were completed or delivered. Many orders may be stalled or cancelled.",
                    'action'   => 'Review pending orders in Orders → Retail and follow up on stalled items.',
                ];
            }

            // RT3: Revenue dropped month-over-month
            if ($lastMonthRevenue > 0 && $thisMonthRevenue < $lastMonthRevenue * 0.75) {
                $drop = round((1 - $thisMonthRevenue / $lastMonthRevenue) * 100, 1);
                $recs[] = [
                    'id'       => 'RT3',
                    'severity' => 'warning',
                    'title'    => "Retail Revenue Down {$drop}% This Month",
                    'message'  => "This month's retail revenue (₱" . number_format($thisMonthRevenue, 0) . ") "
                                 . "is " . $drop . "% below last month (₱" . number_format($lastMonthRevenue, 0) . "). "
                                 . "Check if stock levels or product visibility are affecting sales.",
                    'action'   => 'Review product stock and promotions in the Shop Products page.',
                ];
            }

            // RT4: Revenue growing MoM (positive signal)
            if ($lastMonthRevenue > 0 && $thisMonthRevenue >= $lastMonthRevenue * 1.2) {
                $growth = round((($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1);
                $recs[] = [
                    'id'       => 'RT4',
                    'severity' => 'info',
                    'title'    => "Retail Revenue Up {$growth}% This Month",
                    'message'  => "Great momentum — retail revenue grew {$growth}% over last month "
                                 . "(₱" . number_format($thisMonthRevenue, 0) . " vs ₱" . number_format($lastMonthRevenue, 0) . ").",
                    'action'   => null,
                ];
            }

            // RT5: Critical low-stock items
            $outOfStock = Product::where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true)
                ->where('stock_quantity', 0)
                ->count();

            $criticalLow = Product::where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true)
                ->where('stock_quantity', '>', 0)
                ->where('stock_quantity', '<=', 3)
                ->count();

            if ($outOfStock > 0) {
                $recs[] = [
                    'id'       => 'RT5',
                    'severity' => 'critical',
                    'title'    => "{$outOfStock} Product(s) Out of Stock",
                    'message'  => "{$outOfStock} active product(s) have zero stock. "
                                 . "Customers can see these items but cannot order them.",
                    'action'   => 'Restock these products or mark them inactive to avoid lost sales.',
                ];
            } elseif ($criticalLow > 0) {
                $recs[] = [
                    'id'       => 'RT5',
                    'severity' => 'warning',
                    'title'    => "{$criticalLow} Product(s) Critically Low on Stock",
                    'message'  => "{$criticalLow} active product(s) have 3 or fewer units remaining. "
                                 . "These may run out before your next restock.",
                    'action'   => 'Reorder stock for these items soon to avoid stockouts.',
                ];
            }
        }

        // ─── sort by severity ────────────────────────────────────────────────────
        $order = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($recs, fn($a, $b) => ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9));

        return $recs;
    }
}
