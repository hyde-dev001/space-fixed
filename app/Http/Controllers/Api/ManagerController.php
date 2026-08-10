<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\ManagerReport;
use App\Support\Erp\ErpActorContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ManagerController extends Controller
{
    private function managerReportDefinitions(): array
    {
        return [
            'sales' => [
                'title' => 'Sales Report',
                'description' => 'Comprehensive sales data and revenue analysis',
            ],
            'stock' => [
                'title' => 'Stock Update Report',
                'description' => 'Current inventory levels and stock movements',
            ],
            'complaints' => [
                'title' => 'Customer Complaints',
                'description' => 'Customer feedback and complaint tracking',
            ],
            'damaged' => [
                'title' => 'Damaged Items Report',
                'description' => 'Items marked as damaged or defective',
            ],
            'missing' => [
                'title' => 'Missing Items Report',
                'description' => 'Unaccounted or lost inventory items',
            ],
            'performance' => [
                'title' => 'Performance Summary',
                'description' => 'Overall business performance metrics',
            ],
        ];
    }

    private function resolveReportRange(string $dateRange): array
    {
        $end = now()->endOfDay();

        $start = match ($dateRange) {
            'week' => now()->subDays(6)->startOfDay(),
            'month' => now()->subDays(29)->startOfDay(),
            'quarter' => now()->subMonths(3)->startOfDay(),
            'year' => now()->subYear()->startOfDay(),
            default => now()->subDays(6)->startOfDay(),
        };

        return [$start, $end];
    }

    private function mapManagerReport(ManagerReport $report): array
    {
        $definitions = $this->managerReportDefinitions();
        $reportTypeConfig = $definitions[$report->report_type] ?? [
            'title' => ucfirst($report->report_type),
            'description' => '',
        ];

        return [
            'id' => $report->id,
            'report_type' => $report->report_type,
            'report_title' => $reportTypeConfig['title'],
            'description' => $reportTypeConfig['description'],
            'date_range' => $report->date_range,
            'status' => $report->status,
            'notes' => $report->notes,
            'generated_at' => optional($report->generated_at)->toDateTimeString(),
            'sent_at' => optional($report->sent_at)->toDateTimeString(),
            'downloaded_at' => optional($report->downloaded_at)->toDateTimeString(),
            'period_start' => optional($report->period_start)->toDateTimeString(),
            'period_end' => optional($report->period_end)->toDateTimeString(),
            'summary' => $report->report_data['summary'] ?? [],
        ];
    }

    private function buildReportCsvContent(array $summary, array $rows): string
    {
        $stream = fopen('php://temp', 'w+');

        fputcsv($stream, ['Summary']);
        foreach ($summary as $key => $value) {
            fputcsv($stream, [$key, is_scalar($value) || $value === null ? (string) $value : json_encode($value)]);
        }

        fputcsv($stream, []);
        fputcsv($stream, ['Details']);

        if (empty($rows)) {
            fputcsv($stream, ['No records found for selected date range']);
        } else {
            $firstRow = (array) $rows[0];
            $headers = array_keys($firstRow);
            fputcsv($stream, $headers);

            foreach ($rows as $row) {
                $normalized = [];
                foreach ((array) $row as $value) {
                    $normalized[] = is_scalar($value) || $value === null
                        ? (string) $value
                        : json_encode($value);
                }
                fputcsv($stream, $normalized);
            }
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content ?: '';
    }

    private function storeManagerReportFile(ManagerReport $report): void
    {
        $reportData = $report->report_data ?? [];
        $summary = $reportData['summary'] ?? [];
        $rows = $reportData['rows'] ?? [];
        $content = $this->buildReportCsvContent($summary, $rows);

        $path = "manager-reports/{$report->shop_owner_id}/report-{$report->id}.csv";
        Storage::disk('local')->put($path, $content);

        if ($report->file_path !== $path) {
            $report->update(['file_path' => $path]);
        }
    }

    private function buildManagerReportData(int $shopOwnerId, string $reportType, $start, $end): array
    {
        return match ($reportType) {
            'sales' => $this->buildSalesReportData($shopOwnerId, $start, $end),
            'stock' => $this->buildStockReportData($shopOwnerId),
            'complaints' => $this->buildComplaintsReportData($shopOwnerId, $start, $end),
            'damaged' => $this->buildDamagedReportData($shopOwnerId, $start, $end),
            'missing' => $this->buildMissingReportData($shopOwnerId, $start, $end),
            'performance' => $this->buildPerformanceReportData($shopOwnerId, $start, $end),
            default => [
                'summary' => [],
                'rows' => [],
            ],
        };
    }

    private function buildSalesReportData(int $shopOwnerId, $start, $end): array
    {
        $orders = DB::table('orders')
            ->where('shop_owner_id', $shopOwnerId)
            ->whereBetween('created_at', [$start, $end])
            ->select(['id', 'customer', 'product', 'status', 'total', 'created_at'])
            ->orderByDesc('created_at')
            ->get();

        $totalRevenue = (float) $orders->sum(fn ($order) => (float) ($order->total ?? 0));
        $totalOrders = (int) $orders->count();
        $completedOrders = (int) $orders->whereIn('status', ['completed', 'delivered'])->count();

        $rows = $orders->map(function ($order) {
            return [
                'order_id' => $order->id,
                'customer' => (string) ($order->customer ?? ''),
                'product' => (string) ($order->product ?? ''),
                'status' => (string) ($order->status ?? ''),
                'total' => number_format((float) ($order->total ?? 0), 2, '.', ''),
                'created_at' => (string) ($order->created_at ?? ''),
            ];
        })->values()->all();

        return [
            'summary' => [
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'total_revenue' => number_format($totalRevenue, 2, '.', ''),
                'average_order_value' => $totalOrders > 0
                    ? number_format($totalRevenue / $totalOrders, 2, '.', '')
                    : number_format(0, 2, '.', ''),
            ],
            'rows' => $rows,
        ];
    }

    private function buildStockReportData(int $shopOwnerId): array
    {
        $items = InventoryItem::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'category', 'available_quantity', 'reorder_level', 'price', 'updated_at']);

        $rows = $items->map(function (InventoryItem $item) {
            $stockStatus = $item->available_quantity <= 0
                ? 'Out of Stock'
                : ($item->available_quantity <= $item->reorder_level ? 'Low Stock' : 'In Stock');

            return [
                'item_id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'category' => $item->category,
                'available_quantity' => $item->available_quantity,
                'reorder_level' => $item->reorder_level,
                'price' => number_format((float) ($item->price ?? 0), 2, '.', ''),
                'stock_status' => $stockStatus,
                'updated_at' => optional($item->updated_at)->toDateTimeString(),
            ];
        })->values()->all();

        return [
            'summary' => [
                'total_items' => (int) $items->count(),
                'total_quantity' => (int) $items->sum('available_quantity'),
                'low_stock_count' => (int) $items->filter(fn ($item) => $item->available_quantity > 0 && $item->available_quantity <= $item->reorder_level)->count(),
                'out_of_stock_count' => (int) $items->filter(fn ($item) => $item->available_quantity <= 0)->count(),
            ],
            'rows' => $rows,
        ];
    }

    private function buildComplaintsReportData(int $shopOwnerId, $start, $end): array
    {
        $complaints = DB::table('shop_reports as sr')
            ->leftJoin('users as u', 'sr.user_id', '=', 'u.id')
            ->where('sr.shop_owner_id', $shopOwnerId)
            ->whereBetween('sr.created_at', [$start, $end])
            ->select(['sr.id', 'u.name as customer_name', 'sr.reason', 'sr.description', 'sr.status', 'sr.created_at'])
            ->orderByDesc('sr.created_at')
            ->get();

        $rows = $complaints->map(function ($complaint) {
            return [
                'complaint_id' => $complaint->id,
                'customer_name' => (string) ($complaint->customer_name ?? 'Unknown'),
                'reason' => (string) ($complaint->reason ?? ''),
                'description' => (string) ($complaint->description ?? ''),
                'status' => (string) ($complaint->status ?? ''),
                'created_at' => (string) ($complaint->created_at ?? ''),
            ];
        })->values()->all();

        return [
            'summary' => [
                'total_complaints' => (int) $complaints->count(),
                'unresolved_complaints' => (int) $complaints->whereIn('status', ['submitted', 'under_review'])->count(),
            ],
            'rows' => $rows,
        ];
    }

    private function buildDamagedReportData(int $shopOwnerId, $start, $end): array
    {
        $damages = DB::table('stock_movements as sm')
            ->join('inventory_items as ii', 'sm.inventory_item_id', '=', 'ii.id')
            ->where('ii.shop_owner_id', $shopOwnerId)
            ->where('sm.movement_type', 'damage')
            ->whereBetween('sm.performed_at', [$start, $end])
            ->select(['sm.id', 'ii.name', 'ii.sku', 'sm.quantity_change', 'sm.notes', 'sm.performed_at'])
            ->orderByDesc('sm.performed_at')
            ->get();

        $rows = $damages->map(function ($damage) {
            return [
                'movement_id' => $damage->id,
                'item_name' => (string) ($damage->name ?? ''),
                'sku' => (string) ($damage->sku ?? ''),
                'quantity_damaged' => abs((int) ($damage->quantity_change ?? 0)),
                'notes' => (string) ($damage->notes ?? ''),
                'recorded_at' => (string) ($damage->performed_at ?? ''),
            ];
        })->values()->all();

        return [
            'summary' => [
                'damage_incidents' => (int) $damages->count(),
                'total_units_damaged' => (int) $damages->sum(fn ($damage) => abs((int) ($damage->quantity_change ?? 0))),
            ],
            'rows' => $rows,
        ];
    }

    private function buildMissingReportData(int $shopOwnerId, $start, $end): array
    {
        $missingRows = DB::table('stock_movements as sm')
            ->join('inventory_items as ii', 'sm.inventory_item_id', '=', 'ii.id')
            ->where('ii.shop_owner_id', $shopOwnerId)
            ->whereBetween('sm.performed_at', [$start, $end])
            ->where(function ($query) {
                $query
                    ->where(function ($nested) {
                        $nested->where('sm.movement_type', 'adjustment')
                            ->where('sm.quantity_change', '<', 0)
                            ->where(function ($notes) {
                                $notes->where('sm.notes', 'like', '%missing%')
                                    ->orWhere('sm.notes', 'like', '%lost%')
                                    ->orWhere('sm.notes', 'like', '%shrink%')
                                    ->orWhere('sm.notes', 'like', '%unaccounted%');
                            });
                    })
                    ->orWhere('sm.reference_type', 'missing');
            })
            ->select(['sm.id', 'ii.name', 'ii.sku', 'sm.quantity_change', 'sm.notes', 'sm.performed_at'])
            ->orderByDesc('sm.performed_at')
            ->get();

        $rows = $missingRows->map(function ($missing) {
            return [
                'movement_id' => $missing->id,
                'item_name' => (string) ($missing->name ?? ''),
                'sku' => (string) ($missing->sku ?? ''),
                'quantity_missing' => abs((int) ($missing->quantity_change ?? 0)),
                'notes' => (string) ($missing->notes ?? ''),
                'recorded_at' => (string) ($missing->performed_at ?? ''),
            ];
        })->values()->all();

        return [
            'summary' => [
                'missing_incidents' => (int) $missingRows->count(),
                'total_units_missing' => (int) $missingRows->sum(fn ($missing) => abs((int) ($missing->quantity_change ?? 0))),
            ],
            'rows' => $rows,
        ];
    }

    private function buildPerformanceReportData(int $shopOwnerId, $start, $end): array
    {
        $totalSales = (float) DB::table('finance_invoices')
            ->where('shop_id', $shopOwnerId)
            ->where('status', 'posted')
            ->whereBetween('created_at', [$start, $end])
            ->sum('total');

        $totalOrders = (int) DB::table('orders')
            ->where('shop_owner_id', $shopOwnerId)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $completedRepairs = (int) DB::table('repair_requests')
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', ['completed', 'picked_up'])
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $activeStaff = (int) DB::table('employees')
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->count();

        $pendingApprovals = (int) DB::table('finance_expenses')
            ->where('shop_id', $shopOwnerId)
            ->where('status', 'submitted')
            ->count()
            + (int) DB::table('leave_requests')
                ->where('shop_owner_id', $shopOwnerId)
                ->where('status', 'pending')
                ->count();

        return [
            'summary' => [
                'total_sales' => number_format($totalSales, 2, '.', ''),
                'total_orders' => $totalOrders,
                'completed_repairs' => $completedRepairs,
                'active_staff' => $activeStaff,
                'pending_approvals' => $pendingApprovals,
            ],
            'rows' => [
                ['metric' => 'Total Sales', 'value' => number_format($totalSales, 2, '.', ''), 'unit' => 'PHP'],
                ['metric' => 'Total Orders', 'value' => (string) $totalOrders, 'unit' => 'count'],
                ['metric' => 'Completed Repairs', 'value' => (string) $completedRepairs, 'unit' => 'count'],
                ['metric' => 'Active Staff', 'value' => (string) $activeStaff, 'unit' => 'count'],
                ['metric' => 'Pending Approvals', 'value' => (string) $pendingApprovals, 'unit' => 'count'],
            ],
        ];
    }

    private function resolveShopOwnerId($user): ?int
    {
        $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
        return $shopOwnerId ? (int) $shopOwnerId : null;
    }

    private function resolveImageUrl(?string $imagePath): ?string
    {
        if (!$imagePath) {
            return null;
        }

        if (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://') || str_starts_with($imagePath, '/')) {
            return $imagePath;
        }

        return asset('storage/' . ltrim($imagePath, '/'));
    }

    private function userHasManagerAccess($user): bool
    {
        if (!$user) {
            return false;
        }

        $managerPermissions = [
            'access-manager-dashboard',
            'access-audit-logs',
            'access-manager-reports',
            'access-inventory-overview',
            'access-repair-reject-review',
            'access-suspend-account',
        ];

        $roleColumn = strtoupper((string) $user->role);
        if (in_array($roleColumn, ['MANAGER', 'FINANCE_MANAGER', 'SUPER_ADMIN'], true)) {
            return true;
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['Manager', 'Finance Manager', 'Super Admin'])) {
            return true;
        }

        if (method_exists($user, 'hasAnyPermission') && $user->hasAnyPermission($managerPermissions)) {
            return true;
        }

        return false;
    }

    private function resolveDashboardDateRange(string $requestedRange): array
    {
        $normalized = strtolower(trim($requestedRange));
        $end = now()->endOfDay();

        [$key, $label, $start] = match ($normalized) {
            '7d', 'last_7_days' => ['last_7_days', 'Last 7 days', now()->subDays(6)->startOfDay()],
            '90d', 'last_90_days', 'quarter' => ['last_90_days', 'Last 90 days', now()->subDays(89)->startOfDay()],
            '365d', 'last_365_days', 'year' => ['last_365_days', 'Last 365 days', now()->subDays(364)->startOfDay()],
            'mtd', 'month_to_date' => ['month_to_date', 'Month to date', now()->startOfMonth()],
            default => ['last_30_days', 'Last 30 days', now()->subDays(29)->startOfDay()],
        };

        $daysInRange = max(1, $start->diffInDays($end) + 1);
        $previousEnd = $start->copy()->subSecond();
        $previousStart = $previousEnd->copy()->subDays($daysInRange - 1)->startOfDay();

        return [
            'key' => $key,
            'label' => $label,
            'start' => $start,
            'end' => $end,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
        ];
    }

    /**
     * Get dashboard statistics for Manager
     */
    public function getDashboardStats(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            // Check if user has manager role
            if (!$this->userHasManagerAccess($user)) {
                return response()->json(['error' => 'Access denied. Manager role required.'], 403);
            }
            
            $shopOwnerId = $this->resolveShopOwnerId($user);
            
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }

            $rawBusinessType = strtolower(trim((string) DB::table('shop_owners')
                ->where('id', $shopOwnerId)
                ->value('business_type')));

            $normalizedBusinessType = str_contains($rawBusinessType, 'both') ? 'both' : $rawBusinessType;
            $hasRepairSignal = str_contains($normalizedBusinessType, 'repair') || str_contains($normalizedBusinessType, 'service');
            $hasRetailSignal = str_contains($normalizedBusinessType, 'retail') || str_contains($normalizedBusinessType, 'shoe') || str_contains($normalizedBusinessType, 'product');

            if ($hasRepairSignal && !$hasRetailSignal) {
                $canRetail = false;
                $canRepair = true;
            } elseif ($hasRetailSignal && !$hasRepairSignal) {
                $canRetail = true;
                $canRepair = false;
            } else {
                $canRetail = true;
                $canRepair = true;
                $normalizedBusinessType = 'both';
            }

            $dateRange = $this->resolveDashboardDateRange((string) $request->input('range', 'last_30_days'));
            $rangeStart = $dateRange['start'];
            $rangeEnd = $dateRange['end'];
            $previousStart = $dateRange['previous_start'];
            $previousEnd = $dateRange['previous_end'];

            $retailCompletedStatuses = ['completed', 'delivered', 'shipped'];
            $retailPendingStatuses = ['pending', 'processing'];
            $repairCompletedStatuses = ['completed', 'ready_for_pickup', 'shipped', 'picked_up'];
            $repairPendingStatuses = [
                'new_request',
                'assigned_to_repairer',
                'repairer_accepted',
                'waiting_customer_confirmation',
                'owner_approval_pending',
                'owner_approved',
                'confirmed',
                'pending',
                'in_progress',
                'awaiting_parts',
                'received',
                'under-review',
                'repairer_rejected',
            ];
            $repairClosedRejectedStatuses = ['manager_approved', 'manager_rejected', 'rejected', 'cancelled'];
            
            // Sales KPI: paid + fulfilled orders in selected period
            $orderSales = $canRetail
                ? DB::table('orders')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->whereIn('status', ['completed', 'delivered', 'shipped'])
                    ->where('payment_status', 'paid')
                    ->whereBetween(DB::raw('COALESCE(paid_at, created_at)'), [$rangeStart, $rangeEnd])
                    ->sum('total_amount')
                : 0;

            // Add standalone posted invoices (not tied to an order) to avoid missing finance-only sales
            $standaloneInvoiceSales = $canRetail
                ? DB::table('finance_invoices')
                    ->where('shop_id', $shopOwnerId)
                    ->where('status', 'posted')
                    ->whereNull('job_order_id')
                    ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                    ->sum('total')
                : 0;

            $totalSales = (float) $orderSales + (float) $standaloneInvoiceSales;

            // Previous period comparison (same duration as selected period)
            $previousOrderSales = $canRetail
                ? DB::table('orders')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->whereIn('status', ['completed', 'delivered', 'shipped'])
                    ->where('payment_status', 'paid')
                    ->whereBetween(DB::raw('COALESCE(paid_at, created_at)'), [$previousStart, $previousEnd])
                    ->sum('total_amount')
                : 0;

            $previousStandaloneInvoiceSales = $canRetail
                ? DB::table('finance_invoices')
                    ->where('shop_id', $shopOwnerId)
                    ->where('status', 'posted')
                    ->whereNull('job_order_id')
                    ->whereBetween('created_at', [$previousStart, $previousEnd])
                    ->sum('total')
                : 0;

            $previousSales = (float) $previousOrderSales + (float) $previousStandaloneInvoiceSales;
                
            $salesChange = $previousSales > 0
                ? (($totalSales - $previousSales) / $previousSales) * 100 
                : 0;
            
            // Retail KPIs
            $retailCompletedInRange = $canRetail
                ? DB::table('orders')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->whereIn('status', $retailCompletedStatuses)
                    ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                    ->count()
                : 0;

            $retailPendingNow = $canRetail
                ? DB::table('orders')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->whereIn('status', $retailPendingStatuses)
                    ->count()
                : 0;

            // Repair KPIs
            $repairCompletedInRange = $canRepair
                ? DB::table('repair_requests')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->whereIn('status', $repairCompletedStatuses)
                    ->whereBetween(DB::raw('COALESCE(picked_up_at, completed_at, updated_at, created_at)'), [$rangeStart, $rangeEnd])
                    ->count()
                : 0;

            $repairPendingNow = $canRepair
                ? DB::table('repair_requests')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->whereIn('status', $repairPendingStatuses)
                    ->count()
                : 0;

            $repairClosedRejectedInRange = $canRepair
                ? DB::table('repair_requests')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->whereIn('status', $repairClosedRejectedStatuses)
                    ->whereBetween(DB::raw('COALESCE(manager_reviewed_at, updated_at, created_at)'), [$rangeStart, $rangeEnd])
                    ->count()
                : 0;

            // Legacy fields kept for dashboard compatibility
            $totalRepairs = $repairCompletedInRange;
            $pendingJobOrders = ($canRetail ? $retailPendingNow : 0) + ($canRepair ? $repairPendingNow : 0);
            
            // Get active staff count
            $activeStaff = DB::table('employees')
                ->where('shop_owner_id', $shopOwnerId)
                ->where('status', 'active')
                ->count();

            $isSqlite = DB::connection()->getDriverName() === 'sqlite';
            $monthExpression = fn(string $column) => $isSqlite
                ? "strftime('%Y-%m', {$column})"
                : "DATE_FORMAT({$column}, '%Y-%m')";
            $daysPendingExpression = $isSqlite
                ? "CAST(julianday('now') - julianday(lr.created_at) AS INTEGER)"
                : 'DATEDIFF(NOW(), lr.created_at)';
                
            // Get monthly revenue trend from paid fulfilled orders + standalone posted invoices
            $monthlyOrderRevenue = $canRetail
                ? DB::table('orders')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->whereIn('status', ['completed', 'delivered', 'shipped'])
                    ->where('payment_status', 'paid')
                    ->whereBetween(DB::raw('COALESCE(paid_at, created_at)'), [$rangeStart->copy()->startOfMonth(), $rangeEnd])
                    ->select(
                        DB::raw($monthExpression('COALESCE(paid_at, created_at)') . ' as month'),
                        DB::raw('SUM(total_amount) as revenue')
                    )
                    ->groupBy('month')
                    ->orderBy('month', 'asc')
                    ->get()
                : collect();

            $monthlyStandaloneInvoiceRevenue = $canRetail
                ? DB::table('finance_invoices')
                    ->where('shop_id', $shopOwnerId)
                    ->where('status', 'posted')
                    ->whereNull('job_order_id')
                    ->whereBetween('created_at', [$rangeStart->copy()->startOfMonth(), $rangeEnd])
                    ->select(
                        DB::raw($monthExpression('created_at') . ' as month'),
                        DB::raw('SUM(total) as revenue')
                    )
                    ->groupBy('month')
                    ->orderBy('month', 'asc')
                    ->get()
                : collect();

            $monthlyRevenueMap = [];
            foreach ($monthlyOrderRevenue as $entry) {
                $key = (string) $entry->month;
                $monthlyRevenueMap[$key] = ($monthlyRevenueMap[$key] ?? 0.0) + (float) $entry->revenue;
            }
            foreach ($monthlyStandaloneInvoiceRevenue as $entry) {
                $key = (string) $entry->month;
                $monthlyRevenueMap[$key] = ($monthlyRevenueMap[$key] ?? 0.0) + (float) $entry->revenue;
            }

            ksort($monthlyRevenueMap);
            $monthlyRevenue = collect($monthlyRevenueMap)
                ->map(fn($revenue, $month) => [
                    'month' => $month,
                    'revenue' => round((float) $revenue, 2),
                ])
                ->values();
            
            // Get pending approvals from multiple sources
            $pendingExpenses = DB::table('finance_expenses')
                ->where('shop_id', $shopOwnerId)
                ->where('status', 'submitted')
                ->count();
                
            $pendingLeaves = DB::table('leave_requests')
                ->where('shop_owner_id', $shopOwnerId)
                ->where('status', 'pending')
                ->count();
                
            $pendingApprovals = $pendingExpenses + $pendingLeaves;
            
            // Get approval summary with details
            $approvalSummary = [
                'expenses' => [
                    'count' => $pendingExpenses,
                    'total_amount' => DB::table('finance_expenses')
                        ->where('shop_id', $shopOwnerId)
                        ->where('status', 'submitted')
                        ->sum('amount')
                ],
                'leave_requests' => [
                    'count' => $pendingLeaves,
                    'details' => DB::table('leave_requests as lr')
                        ->join('employees as e', 'lr.employee_id', '=', 'e.id')
                        ->where('lr.shop_owner_id', $shopOwnerId)
                        ->where('lr.status', 'pending')
                        ->select([
                            'lr.id',
                            'lr.leave_type',
                            'lr.start_date',
                            'lr.end_date',
                            'lr.no_of_days',
                            'lr.reason',
                            'lr.created_at',
                            'e.id as employee_id',
                            'e.name as employee_name',
                            'e.email as employee_email',
                            'e.position as employee_position',
                            DB::raw($daysPendingExpression . ' as days_pending')
                        ])
                        ->orderBy('lr.created_at', 'asc')
                        ->limit(5)
                        ->get()
                ]
            ];
            
            // Get recent activities from audit logs
            $recentActivities = DB::table('audit_logs')
                ->where('shop_owner_id', $shopOwnerId)
                ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                ->whereIn('action', ['created', 'updated', 'approved', 'rejected'])
                ->select([
                    'id',
                    'user_id',
                    'action',
                    'object_type',
                    'object_id',
                    'target_type',
                    'target_id',
                    'data',
                    'metadata',
                    'created_at'
                ])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function($log) {
                    // Use object_type or target_type for entity name
                    $entityType = $log->target_type ?: $log->object_type;
                    if ($entityType) {
                        $parts = explode('\\', $entityType);
                        $entityType = end($parts);
                    } else {
                        $entityType = 'Item';
                    }
                    
                    // Get user name
                    $user = DB::table('users')->where('id', $log->user_id)->first();
                    $userName = $user ? $user->name : 'Unknown User';
                    
                    return [
                        'id' => $log->id,
                        'user_name' => $userName,
                        'action' => $log->action,
                        'entity_type' => $entityType,
                        'entity_id' => $log->target_id ?: $log->object_id,
                        'description' => ucfirst($log->action) . ' ' . strtolower($entityType),
                        'timestamp' => $log->created_at,
                        'time_ago' => \Carbon\Carbon::parse($log->created_at)->diffForHumans()
                    ];
                });
            
            return response()->json([
                'totalSales' => floatval($totalSales),
                'salesChange' => round($salesChange, 1),
                'totalRepairs' => $totalRepairs,
                'pendingJobOrders' => $pendingJobOrders,
                'dateRange' => [
                    'key' => $dateRange['key'],
                    'label' => $dateRange['label'],
                    'start' => $rangeStart->toIso8601String(),
                    'end' => $rangeEnd->toIso8601String(),
                    'previous_start' => $previousStart->toIso8601String(),
                    'previous_end' => $previousEnd->toIso8601String(),
                    'timezone' => config('app.timezone'),
                ],
                'kpiBreakdown' => [
                    'retail' => [
                        'completed_orders' => $retailCompletedInRange,
                        'pending_orders' => $retailPendingNow,
                        'period_revenue' => floatval($totalSales),
                    ],
                    'repair' => [
                        'completed_jobs' => $repairCompletedInRange,
                        'pending_jobs' => $repairPendingNow,
                        'closed_rejected' => $repairClosedRejectedInRange,
                    ],
                    'combined' => [
                        'completed_work_items_in_period' => $retailCompletedInRange + $repairCompletedInRange,
                        'pending_work_queue' => $pendingJobOrders,
                    ],
                ],
                'kpiSemantics' => [
                    'totalSales' => "Paid fulfilled orders + standalone posted invoices for {$dateRange['label']}",
                    'totalRepairs' => "Completed repair jobs for {$dateRange['label']}",
                    'pendingJobOrders' => 'Current open queue: pending retail orders + pending repair jobs',
                ],
                'businessCapabilities' => [
                    'businessType' => $normalizedBusinessType,
                    'canRetail' => $canRetail,
                    'canRepair' => $canRepair,
                ],
                'activeStaff' => $activeStaff,
                'pendingApprovals' => $pendingApprovals,
                'monthlyRevenue' => $monthlyRevenue,
                'approvalSummary' => $approvalSummary,
                'recentActivities' => $recentActivities,
                'lastUpdated' => now()->toIso8601String()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to get manager dashboard stats: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Failed to load dashboard statistics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getInventoryOverview(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            if (!$this->userHasManagerAccess($user)) {
                return response()->json(['error' => 'Access denied. Manager role required.'], 403);
            }

            $shopOwnerId = $this->resolveShopOwnerId($user);
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }

            $search = trim((string) $request->input('search', ''));
            $category = trim((string) $request->input('category', ''));
            $status = trim((string) $request->input('status', ''));
            $perPage = max(5, min((int) $request->input('per_page', 10), 100));

            $itemsQuery = InventoryItem::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true);

            if ($search !== '') {
                $itemsQuery->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            }

            if ($category !== '' && strtoupper($category) !== 'ALL') {
                $itemsQuery->where('category', $category);
            }

            if ($status !== '' && strtoupper($status) !== 'ALL') {
                if ($status === 'In Stock') {
                    $itemsQuery->where('available_quantity', '>', DB::raw('reorder_level'));
                } elseif ($status === 'Low Stock') {
                    $itemsQuery->whereRaw('available_quantity > 0 AND available_quantity <= reorder_level');
                } elseif ($status === 'Out of Stock') {
                    $itemsQuery->where('available_quantity', '<=', 0);
                }
            }

            $items = $itemsQuery
                ->orderBy('name')
                ->paginate($perPage)
                ->through(function (InventoryItem $item) {
                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'sku' => $item->sku,
                        'category' => $item->category,
                        'quantity' => $item->available_quantity,
                        'price' => (float) ($item->price ?? 0),
                        'status' => $item->status,
                        'image' => $this->resolveImageUrl($item->main_image),
                        'last_updated' => optional($item->updated_at)->toDateTimeString(),
                    ];
                });

            $baseQuery = InventoryItem::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true);

            $metrics = [
                'total_quantity' => (int) (clone $baseQuery)->sum('available_quantity'),
                'low_stock_count' => (int) (clone $baseQuery)->whereRaw('available_quantity > 0 AND available_quantity <= reorder_level')->count(),
                'out_of_stock_count' => (int) (clone $baseQuery)->where('available_quantity', '<=', 0)->count(),
            ];

            $categories = InventoryItem::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true)
                ->whereNotNull('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->values();

            return response()->json([
                'items' => $items,
                'metrics' => $metrics,
                'categories' => $categories,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get manager inventory overview: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Failed to load inventory overview',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getProducts(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            if (!$this->userHasManagerAccess($user)) {
                return response()->json(['error' => 'Access denied. Manager role required.'], 403);
            }

            $shopOwnerId = $this->resolveShopOwnerId($user);
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }

            $search = trim((string) $request->input('search', ''));
            $category = trim((string) $request->input('category', ''));
            $status = trim((string) $request->input('status', 'All'));
            $perPage = max(5, min((int) $request->input('per_page', 10), 100));

            $query = InventoryItem::query()
                ->where('shop_owner_id', $shopOwnerId);

            if ($search !== '') {
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            }

            if ($category !== '' && strtoupper($category) !== 'ALL') {
                $query->where('category', $category);
            }

            if (strtoupper($status) === 'ACTIVE') {
                $query->where('is_active', true);
            } elseif (strtoupper($status) === 'INACTIVE') {
                $query->where('is_active', false);
            }

            $products = $query
                ->orderByDesc('updated_at')
                ->paginate($perPage)
                ->through(function (InventoryItem $item) {
                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'sku' => $item->sku,
                        'category' => $item->category,
                        'quantity' => $item->available_quantity,
                        'price' => (float) ($item->price ?? 0),
                        'status' => $item->is_active ? 'Active' : 'Inactive',
                        'stock_status' => $item->status,
                        'image' => $this->resolveImageUrl($item->main_image),
                        'updated_at' => optional($item->updated_at)->toDateTimeString(),
                    ];
                });

            $baseQuery = InventoryItem::query()->where('shop_owner_id', $shopOwnerId);
            $summary = [
                'total' => (int) (clone $baseQuery)->count(),
                'active' => (int) (clone $baseQuery)->where('is_active', true)->count(),
                'inactive' => (int) (clone $baseQuery)->where('is_active', false)->count(),
            ];

            $categories = InventoryItem::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->whereNotNull('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->values();

            return response()->json([
                'products' => $products,
                'summary' => $summary,
                'categories' => $categories,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get manager products: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Failed to load products',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getReports(Request $request)
    {
        try {
            $context = request()->attributes->get('erp.actor_context');
            $ownerMode = $context instanceof ErpActorContext && $context->isOwnerMode();
            $user = Auth::guard('user')->user();

            if (!$ownerMode && !$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            if (!$ownerMode && !$this->userHasManagerAccess($user)) {
                return response()->json(['error' => 'Access denied. Manager role required.'], 403);
            }

            $shopOwnerId = $ownerMode
                ? (int) $context->tenantOwner()->getKey()
                : $this->resolveShopOwnerId($user);
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }

            $definitions = $this->managerReportDefinitions();
            $managerReportsReady = Schema::hasTable('manager_reports');
            $shopReportsReady = Schema::hasTable('shop_reports');
            $inventoryReady = Schema::hasTable('inventory_items');

            $recentReports = collect();
            $reportsGenerated = 0;
            $reportsSent = 0;

            if ($managerReportsReady) {
                $recentReports = ManagerReport::query()
                    ->where('shop_owner_id', $shopOwnerId)
                    ->orderByDesc('generated_at')
                    ->orderByDesc('id')
                    ->limit(20)
                    ->get();

                $monthStart = now()->startOfMonth();

                $reportsGenerated = (int) ManagerReport::query()
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('generated_at', '>=', $monthStart)
                    ->count();

                $reportsSent = (int) ManagerReport::query()
                    ->where('shop_owner_id', $shopOwnerId)
                    ->whereNotNull('sent_at')
                    ->where('sent_at', '>=', $monthStart)
                    ->count();
            }

            $latestByType = $recentReports
                ->groupBy('report_type')
                ->map(fn ($rows) => $rows->first());

            $reportTypes = collect($definitions)->map(function ($config, $id) use ($latestByType) {
                $latest = $latestByType->get($id);

                return [
                    'id' => $id,
                    'title' => $config['title'],
                    'description' => $config['description'],
                    'last_report' => $latest ? $this->mapManagerReport($latest) : null,
                ];
            })->values();

            $pendingComplaints = $shopReportsReady
                ? (int) DB::table('shop_reports')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->whereIn('status', ['submitted', 'under_review'])
                    ->count()
                : 0;

            $lowStockCount = $inventoryReady
                ? (int) InventoryItem::query()
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('is_active', true)
                    ->whereRaw('available_quantity > 0 AND available_quantity <= reorder_level')
                    ->count()
                : 0;

            $outOfStockCount = $inventoryReady
                ? (int) InventoryItem::query()
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('is_active', true)
                    ->where('available_quantity', '<=', 0)
                    ->count()
                : 0;

            return response()->json([
                'metrics' => [
                    'reports_generated' => $reportsGenerated,
                    'pending_issues' => (int) ($pendingComplaints + $lowStockCount + $outOfStockCount),
                    'reports_sent' => $reportsSent,
                ],
                'report_types' => $reportTypes,
                'recent_reports' => $recentReports->take(10)->map(fn (ManagerReport $report) => $this->mapManagerReport($report))->values(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get manager reports: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Failed to load reports',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function generateReport(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            if (!$this->userHasManagerAccess($user)) {
                return response()->json(['error' => 'Access denied. Manager role required.'], 403);
            }

            $shopOwnerId = $this->resolveShopOwnerId($user);
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }

            if (!Schema::hasTable('manager_reports')) {
                return response()->json([
                    'error' => 'Reports module is not initialized. Please run database migrations for manager reports.',
                ], 503);
            }

            $definitions = $this->managerReportDefinitions();

            $validated = $request->validate([
                'report_type' => ['required', 'string'],
                'date_range' => ['required', 'string', 'in:week,month,quarter,year'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);

            if (!array_key_exists($validated['report_type'], $definitions)) {
                return response()->json([
                    'error' => 'Invalid report type selected',
                ], 422);
            }

            [$start, $end] = $this->resolveReportRange($validated['date_range']);
            $reportData = $this->buildManagerReportData($shopOwnerId, $validated['report_type'], $start, $end);

            $report = ManagerReport::create([
                'shop_owner_id' => $shopOwnerId,
                'report_type' => $validated['report_type'],
                'date_range' => $validated['date_range'],
                'period_start' => $start,
                'period_end' => $end,
                'status' => 'generated',
                'notes' => $validated['notes'] ?? null,
                'report_data' => $reportData,
                'generated_by' => $user->id,
                'generated_at' => now(),
            ]);

            $this->storeManagerReportFile($report);
            $report->refresh();

            return response()->json([
                'report' => $this->mapManagerReport($report),
                'download_url' => url("/api/manager/reports/{$report->id}/download"),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to generate manager report: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Failed to generate report',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function sendReport(Request $request, int $id)
    {
        try {
            $user = Auth::guard('user')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            if (!$this->userHasManagerAccess($user)) {
                return response()->json(['error' => 'Access denied. Manager role required.'], 403);
            }

            $shopOwnerId = $this->resolveShopOwnerId($user);
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }

            if (!Schema::hasTable('manager_reports')) {
                return response()->json([
                    'error' => 'Reports module is not initialized. Please run database migrations for manager reports.',
                ], 503);
            }

            $validated = $request->validate([
                'notes' => ['required', 'string', 'max:2000'],
            ]);

            $report = ManagerReport::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->findOrFail($id);

            $report->update([
                'status' => 'sent',
                'notes' => $validated['notes'],
                'sent_by' => $user->id,
                'sent_at' => now(),
            ]);

            return response()->json([
                'message' => 'Report marked as sent to shop owner',
                'report' => $this->mapManagerReport($report->fresh()),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to send manager report: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Failed to send report',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function downloadReport(Request $request, int $id)
    {
        try {
            $user = Auth::guard('user')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            if (!$this->userHasManagerAccess($user)) {
                return response()->json(['error' => 'Access denied. Manager role required.'], 403);
            }

            $shopOwnerId = $this->resolveShopOwnerId($user);
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }

            if (!Schema::hasTable('manager_reports')) {
                return response()->json([
                    'error' => 'Reports module is not initialized. Please run database migrations for manager reports.',
                ], 503);
            }

            $report = ManagerReport::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->findOrFail($id);

            if (!$report->file_path || !Storage::disk('local')->exists($report->file_path)) {
                $this->storeManagerReportFile($report);
                $report->refresh();
            }

            $fileName = sprintf(
                '%s-%s.csv',
                $report->report_type,
                optional($report->generated_at)->format('Ymd_His') ?? now()->format('Ymd_His')
            );

            $report->update(['downloaded_at' => now()]);

            return response()->download(
                Storage::disk('local')->path($report->file_path),
                $fileName,
                ['Content-Type' => 'text/csv']
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to download manager report: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Failed to download report',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Get staff performance metrics
     */
    public function getStaffPerformance(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            if (!$this->userHasManagerAccess($user)) {
                return response()->json(['error' => 'Access denied. Manager role required.'], 403);
            }
            
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }
            
            // Get staff performance data
            $performance = DB::table('employees as e')
                ->leftJoin('orders as o', function($join) {
                    $join->on('o.shop_owner_id', '=', 'e.shop_owner_id');
                })
                ->where('e.shop_owner_id', $shopOwnerId)
                ->where('e.status', 'active')
                ->select([
                    'e.id',
                    'e.name',
                    'e.email',
                    'e.position',
                    DB::raw('COUNT(o.id) as total_jobs'),
                    DB::raw('SUM(CASE WHEN o.status IN ("completed", "delivered") THEN 1 ELSE 0 END) as completed_jobs'),
                    DB::raw('SUM(CASE WHEN o.status IN ("pending", "processing") THEN 1 ELSE 0 END) as pending_jobs'),
                    DB::raw('COALESCE(SUM(CASE WHEN o.status IN ("completed", "delivered") THEN CAST(o.total AS DECIMAL(10,2)) ELSE 0 END), 0) as total_revenue')
                ])
                ->groupBy('e.id', 'e.name', 'e.email', 'e.position')
                ->orderBy('completed_jobs', 'desc')
                ->get();
            
            return response()->json($performance);
            
        } catch (\Exception $e) {
            Log::error('Failed to get staff performance: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Failed to load staff performance',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get analytics data
     */
    public function getAnalytics(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            if (!$this->userHasManagerAccess($user)) {
                return response()->json(['error' => 'Access denied. Manager role required.'], 403);
            }
            
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }
            
            // Get order status breakdown
            $ordersByStatus = DB::table('orders')
                ->where('shop_owner_id', $shopOwnerId)
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->get();
            
            // Get top products
            $topProducts = DB::table('orders')
                ->where('shop_owner_id', $shopOwnerId)
                ->select(
                    'product',
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(CAST(total AS DECIMAL(10,2))) as total_revenue')
                )
                ->groupBy('product')
                ->orderBy('order_count', 'desc')
                ->limit(10)
                ->get();
            
            // Get recent activities (last 10)
            $recentActivities = DB::table('orders')
                ->where('shop_owner_id', $shopOwnerId)
                ->select('id', 'customer', 'product', 'status', 'total', 'created_at')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
            
            return response()->json([
                'ordersByStatus' => $ordersByStatus,
                'topProducts' => $topProducts,
                'recentActivities' => $recentActivities
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to get analytics: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Failed to load analytics',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
