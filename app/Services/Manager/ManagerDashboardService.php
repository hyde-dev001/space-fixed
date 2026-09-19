<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\HR\LeaveRequest;
use App\Models\Order;
use App\Models\RepairRequest;
use App\Models\SuspensionRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class ManagerDashboardService
{
    private const OPEN_ORDER_STATUSES = ['pending', 'processing'];

    private const ACTIVE_REPAIR_STATUSES = [
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
        'manager_approved',
        'reassignment_required',
        'awaiting_assignment',
    ];

    private const COMPLETED_REPAIR_STATUSES = [
        'completed',
        'ready_for_pickup',
        'picked_up',
    ];

    private const REJECTED_REPAIR_STATUSES = [
        'repairer_rejected',
        'manager_rejected',
        'rejected',
        'cancelled',
    ];

    public function __construct(
        private readonly ManagerAssignmentEligibilityService $assignmentEligibility,
    ) {
    }

    /**
     * Return one shop-scoped dashboard snapshot.
     *
     * The legacy top-level keys are retained for verified existing consumers;
     * the current_state/period_metrics/signals contract is authoritative for
     * the Manager workspace.
     *
     * @return array<string, mixed>
     */
    public function dashboard(int $shopOwnerId, string $requestedRange = 'last_30_days'): array
    {
        $snapshotAt = CarbonImmutable::now();
        $range = $this->resolveRange($requestedRange, $snapshotAt);
        $businessCapabilities = $this->businessCapabilities($shopOwnerId);
        $currentState = $this->currentState($shopOwnerId, $snapshotAt, $businessCapabilities);
        $periodMetrics = $this->periodMetrics($shopOwnerId, $range, $businessCapabilities);
        $signals = $this->signals($shopOwnerId, $snapshotAt, $currentState, $businessCapabilities);

        $capturedAt = $snapshotAt->toIso8601String();
        $rangePayload = $this->rangePayload($range);
        $revenue = $periodMetrics['revenue'];

        return [
            'snapshot' => [
                'captured_at' => $capturedAt,
                'range' => $rangePayload,
            ],
            'current_state' => $currentState,
            'period_metrics' => $periodMetrics,
            'signals' => $signals,
            'freshness' => [
                'captured_at' => $capturedAt,
                'stale_after_seconds' => 60,
            ],

            // Compatibility fields for the existing Manager API contract.
            'totalSales' => $revenue['current'],
            'salesChange' => $revenue['trend']['percent'],
            'totalRepairs' => $periodMetrics['repairs']['completed'],
            'pendingJobOrders' => $currentState['job_orders']['open'] + $currentState['repair_jobs']['active'],
            'dateRange' => $rangePayload,
            'kpiBreakdown' => [
                'retail' => [
                    'completed_orders' => $periodMetrics['orders']['completed'],
                    'pending_orders' => $currentState['job_orders']['open'],
                    'period_revenue' => $revenue['current'],
                ],
                'repair' => [
                    'completed_jobs' => $periodMetrics['repairs']['completed'],
                    'pending_jobs' => $currentState['repair_jobs']['active'],
                    'closed_rejected' => $periodMetrics['repairs']['rejected'],
                ],
                'suspension' => [
                    'pending' => $currentState['approvals']['suspension'],
                ],
                'combined' => [
                    'completed_work_items_in_period' => $periodMetrics['orders']['completed'] + $periodMetrics['repairs']['completed'],
                    'pending_work_queue' => $currentState['job_orders']['open'] + $currentState['repair_jobs']['active'],
                ],
            ],
            'kpiSemantics' => [
                'totalSales' => "Paid fulfilled orders for {$range['label']}",
                'totalRepairs' => "Completed repair jobs for {$range['label']}",
                'pendingJobOrders' => 'Current open Job Orders and active Repair Jobs',
            ],
            'businessCapabilities' => $businessCapabilities,
            'activeStaff' => $currentState['staff']['active'],
            'pendingApprovals' => $currentState['approvals']['total'],
            'monthlyRevenue' => [],
            'lastUpdated' => $capturedAt,
            'suspensionMetrics' => [
                'pending_count' => $currentState['approvals']['suspension'],
            ],
        ];
    }

    /**
     * Return shop-wide inventory aggregates plus a filtered, paginated list.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function inventory(int $shopOwnerId, array $filters = []): array
    {
        $snapshotAt = CarbonImmutable::now();
        $businessCapabilities = $this->businessCapabilities($shopOwnerId);
        $baseQuery = $this->inventorySourceQuery($shopOwnerId, $businessCapabilities);

        $metrics = [
            'total_items' => (int) (clone $baseQuery)->count(),
            'total_quantity' => (int) (clone $baseQuery)->sum('quantity'),
            'low_stock_count' => (int) (clone $baseQuery)
                ->whereRaw('quantity > 0 AND quantity <= reorder_level')
                ->count(),
            'out_of_stock_count' => (int) (clone $baseQuery)
                ->where('quantity', '<=', 0)
                ->count(),
        ];

        $itemsQuery = clone $baseQuery;
        $search = trim((string) ($filters['search'] ?? ''));
        $category = trim((string) ($filters['category'] ?? ''));
        $status = strtolower(trim((string) ($filters['status'] ?? '')));

        if ($search !== '') {
            $like = "%{$search}%";
            $itemsQuery->where(function ($query) use ($like): void {
                $query->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like);
            });
        }

        if ($category !== '' && strtoupper($category) !== 'ALL') {
            $itemsQuery->where('category', $category);
        }

        match ($status) {
            'in stock', 'in_stock' => $itemsQuery->whereRaw('quantity > reorder_level'),
            'low stock', 'low_stock' => $itemsQuery->whereRaw('quantity > 0 AND quantity <= reorder_level'),
            'out of stock', 'out_of_stock' => $itemsQuery->where('quantity', '<=', 0),
            default => null,
        };

        $perPage = (int) ($filters['per_page'] ?? 10);
        $page = (int) ($filters['page'] ?? 1);
        $items = $itemsQuery
            ->orderBy('name')
            ->orderBy('source_type')
            ->orderBy('source_id')
            ->select('*')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (object $item): array => $this->mapInventoryItem($item));

        $categories = (clone $baseQuery)
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->values()
            ->all();

        return [
            'items' => $items,
            'metrics' => $metrics,
            'categories' => $categories,
            'snapshot' => [
                'captured_at' => $snapshotAt->toIso8601String(),
                'scope' => 'shop',
            ],
            'last_updated_at' => $snapshotAt->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    /** @param array{businessType: string, canRetail: bool, canRepair: bool} $businessCapabilities */
    private function currentState(int $shopOwnerId, CarbonImmutable $snapshotAt, array $businessCapabilities): array
    {
        $canRetail = (bool) ($businessCapabilities['canRetail'] ?? false);
        $canRepair = (bool) ($businessCapabilities['canRepair'] ?? false);
        $openOrders = $canRetail
            ? Order::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->whereIn('status', self::OPEN_ORDER_STATUSES)
                ->with(['assignedStaff' => fn ($query) => $query->withTrashed()])
                ->get(['id', 'order_number', 'status', 'assigned_staff_id', 'created_at', 'updated_at'])
            : collect();

        $orderReassignmentIds = [];
        $unavailableAssigneeIds = collect();
        foreach ($openOrders as $order) {
            if ($order->assignedStaff === null) {
                continue;
            }

            $decision = $this->assignmentEligibility->evaluate(
                assignee: $order->assignedStaff,
                shopOwnerId: $shopOwnerId,
                workType: 'order',
                activeWorkDate: $snapshotAt,
            );

            if (! $decision['eligible']) {
                $orderReassignmentIds[] = (int) $order->id;
                $unavailableAssigneeIds->push((int) $order->assigned_staff_id);
            }
        }

        $activeRepairs = $canRepair
            ? RepairRequest::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->whereIn('status', self::ACTIVE_REPAIR_STATUSES)
                ->with(['repairer' => fn ($query) => $query->withTrashed()])
                ->get(['id', 'request_id', 'status', 'assigned_repairer_id', 'created_at', 'updated_at'])
            : collect();

        $repairReassignmentIds = [];
        foreach ($activeRepairs as $repair) {
            if ($repair->repairer === null) {
                continue;
            }

            $decision = $this->assignmentEligibility->evaluate(
                assignee: $repair->repairer,
                shopOwnerId: $shopOwnerId,
                workType: 'repair',
                activeWorkDate: $snapshotAt,
            );

            if (! $decision['eligible']) {
                $repairReassignmentIds[] = (int) $repair->id;
                $unavailableAssigneeIds->push((int) $repair->assigned_repairer_id);
            }
        }

        $leaveApprovals = LeaveRequest::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'pending')
            ->count();
        $suspensionApprovals = SuspensionRequest::query()
            ->where('status', 'pending_manager')
            ->whereHas('employee', fn ($query) => $query->where('shop_owner_id', $shopOwnerId))
            ->count();
        $repairReviewApprovals = $canRepair
            ? RepairRequest::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('status', 'repairer_rejected')
                ->count()
            : 0;

        $unavailableAssigneeIds = $unavailableAssigneeIds->unique()->values();

        $inventoryMetrics = $this->inventoryMetrics($shopOwnerId, $businessCapabilities);

        return [
            'job_orders' => [
                'open' => $openOrders->count(),
                'pending_unassigned' => $openOrders->where('status', 'pending')->whereNull('assigned_staff_id')->count(),
                'reassignment_required' => count($orderReassignmentIds),
            ],
            'repair_jobs' => [
                'active' => $activeRepairs->count(),
                'reassignment_required' => count($repairReassignmentIds),
                'pending_manager_review' => $repairReviewApprovals,
            ],
            'approvals' => [
                'leave' => $leaveApprovals,
                'suspension' => $suspensionApprovals,
                'repair_review' => $repairReviewApprovals,
                'total' => $leaveApprovals + $suspensionApprovals + $repairReviewApprovals,
            ],
            'staff' => [
                'active' => (int) DB::table('employees')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('status', 'active')
                    ->count(),
                'unavailable_with_active_work' => $unavailableAssigneeIds->count(),
            ],
            'inventory' => $inventoryMetrics,
        ];
    }

    /** @param array{start: CarbonImmutable, end: CarbonImmutable, previous_start: CarbonImmutable, previous_end: CarbonImmutable, key: string, label: string} $range @param array{businessType: string, canRetail: bool, canRepair: bool} $businessCapabilities */
    private function periodMetrics(int $shopOwnerId, array $range, array $businessCapabilities): array
    {
        $canRetail = (bool) ($businessCapabilities['canRetail'] ?? false);
        $canRepair = (bool) ($businessCapabilities['canRepair'] ?? false);
        $currentOrders = $canRetail
            ? $this->orderPeriodMetrics($shopOwnerId, $range['start'], $range['end'])
            : ['received' => 0, 'completed' => 0];
        $previousOrders = $canRetail
            ? $this->orderPeriodMetrics($shopOwnerId, $range['previous_start'], $range['previous_end'])
            : ['received' => 0, 'completed' => 0];
        $currentRepairs = $canRepair
            ? $this->repairPeriodMetrics($shopOwnerId, $range['start'], $range['end'])
            : ['received' => 0, 'completed' => 0, 'rejected' => 0];
        $previousRepairs = $canRepair
            ? $this->repairPeriodMetrics($shopOwnerId, $range['previous_start'], $range['previous_end'])
            : ['received' => 0, 'completed' => 0, 'rejected' => 0];

        $revenueCurrent = $canRetail ? $this->orderRevenue($shopOwnerId, $range['start'], $range['end']) : 0.0;
        $revenuePrevious = $canRetail ? $this->orderRevenue($shopOwnerId, $range['previous_start'], $range['previous_end']) : 0.0;

        return [
            'range' => $this->rangePayload($range),
            'orders' => [
                'received' => $currentOrders['received'],
                'completed' => $currentOrders['completed'],
            ],
            'repairs' => [
                'received' => $currentRepairs['received'],
                'completed' => $currentRepairs['completed'],
                'rejected' => $currentRepairs['rejected'],
            ],
            'revenue' => [
                'current' => $revenueCurrent,
                'previous' => $revenuePrevious,
                'trend' => $this->trend($revenueCurrent, $revenuePrevious),
            ],
            'trends' => [
                'orders' => $this->trend($currentOrders['received'], $previousOrders['received']),
                'repairs' => $this->trend($currentRepairs['received'], $previousRepairs['received']),
            ],
        ];
    }

    /** @return array{received: int, completed: int} */
    private function orderPeriodMetrics(int $shopOwnerId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $query = Order::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereBetween('created_at', [$start, $end]);

        return [
            'received' => (clone $query)->count(),
            'completed' => (clone $query)
                ->whereIn('status', ['completed', 'delivered'])
                ->count(),
        ];
    }

    /** @return array{received: int, completed: int, rejected: int} */
    private function repairPeriodMetrics(int $shopOwnerId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $query = RepairRequest::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereBetween('created_at', [$start, $end]);

        return [
            'received' => (clone $query)->count(),
            'completed' => (clone $query)
                ->whereIn('status', self::COMPLETED_REPAIR_STATUSES)
                ->count(),
            'rejected' => (clone $query)
                ->whereIn('status', self::REJECTED_REPAIR_STATUSES)
                ->count(),
        ];
    }

    private function orderRevenue(int $shopOwnerId, CarbonImmutable $start, CarbonImmutable $end): float
    {
        return round((float) Order::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', ['completed', 'delivered', 'shipped'])
            ->whereIn('payment_status', ['paid', 'completed'])
            ->whereBetween('created_at', [$start, $end])
            ->sum('total_amount'), 2);
    }

    /** @param array{businessType: string, canRetail: bool, canRepair: bool} $businessCapabilities @return array<string, mixed> */
    private function signals(int $shopOwnerId, CarbonImmutable $snapshotAt, array $currentState, array $businessCapabilities): array
    {
        $signals = [];
        $canRetail = (bool) ($businessCapabilities['canRetail'] ?? false);
        $canRepair = (bool) ($businessCapabilities['canRepair'] ?? false);

        $orders = $canRetail
            ? Order::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->whereIn('id', $this->reassignmentOrderIds($shopOwnerId, $snapshotAt))
                ->with('assignedStaff')
                ->orderBy('created_at')
                ->limit(10)
                ->get(['id', 'order_number', 'status', 'created_at'])
            : collect();

        foreach ($orders as $order) {
            $signals[] = $this->signal(
                id: 'order-reassignment-'.$order->id,
                type: 'order_reassignment',
                status: 'Reassignment Required',
                severity: 'critical',
                createdAt: $order->created_at,
                responsible: ['type' => 'role', 'label' => 'Manager'],
                waitingOn: ['type' => 'manager', 'label' => 'Manager review'],
                nextAction: 'Reassign order to an eligible staff member',
                href: '/erp/manager/job-orders?reassignment_required=1',
                reference: (string) ($order->order_number ?: $order->id),
                snapshotAt: $snapshotAt,
            );
        }

        $rejectedRepairs = $canRepair
            ? RepairRequest::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('status', 'repairer_rejected')
                ->orderBy('created_at')
                ->limit(10)
                ->get(['id', 'request_id', 'created_at'])
            : collect();

        foreach ($rejectedRepairs as $repair) {
            $signals[] = $this->signal(
                id: 'repair-review-'.$repair->id,
                type: 'repair_review',
                status: 'Pending Manager Review',
                severity: 'warning',
                createdAt: $repair->created_at,
                responsible: ['type' => 'role', 'label' => 'Manager'],
                waitingOn: ['type' => 'manager', 'label' => 'Manager decision'],
                nextAction: 'Review repairer rejection',
                href: '/erp/manager/repair-jobs?review=pending',
                reference: (string) ($repair->request_id ?: $repair->id),
                snapshotAt: $snapshotAt,
            );
        }

        $pendingLeaves = LeaveRequest::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit(5)
            ->get(['id', 'created_at']);

        foreach ($pendingLeaves as $leave) {
            $signals[] = $this->signal(
                id: 'leave-'.$leave->id,
                type: 'leave_approval',
                status: 'Pending',
                severity: 'info',
                createdAt: $leave->created_at,
                responsible: ['type' => 'role', 'label' => 'Manager'],
                waitingOn: ['type' => 'manager', 'label' => 'Manager approval'],
                nextAction: 'Review leave request',
                href: '/erp/manager/leave-approvals',
                reference: (string) $leave->id,
                snapshotAt: $snapshotAt,
            );
        }

        if ($currentState['inventory']['low_stock_count'] > 0 || $currentState['inventory']['out_of_stock_count'] > 0) {
            $signals[] = $this->signal(
                id: 'inventory-stock-health',
                type: 'inventory_health',
                status: 'Needs Attention',
                severity: $currentState['inventory']['out_of_stock_count'] > 0 ? 'critical' : 'warning',
                createdAt: $snapshotAt,
                responsible: ['type' => 'role', 'label' => 'Inventory / Shop Owner'],
                waitingOn: ['type' => 'workflow', 'label' => 'Inventory workflow'],
                nextAction: 'Review low-stock inventory',
                href: '/erp/manager/inventory-overview',
                reference: null,
                snapshotAt: $snapshotAt,
            );
        }

        return $signals;
    }

    /** @return list<int> */
    private function reassignmentOrderIds(int $shopOwnerId, CarbonImmutable $snapshotAt): array
    {
        $orders = Order::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', self::OPEN_ORDER_STATUSES)
            ->whereNotNull('assigned_staff_id')
            ->with(['assignedStaff' => fn ($query) => $query->withTrashed()])
            ->get(['id', 'assigned_staff_id']);

        return $orders
            ->filter(function (Order $order) use ($shopOwnerId, $snapshotAt): bool {
                if ($order->assignedStaff === null) {
                    return false;
                }

                return ! $this->assignmentEligibility->evaluate(
                    assignee: $order->assignedStaff,
                    shopOwnerId: $shopOwnerId,
                    workType: 'order',
                    activeWorkDate: $snapshotAt,
                )['eligible'];
            })
            ->map(fn (Order $order): int => (int) $order->id)
            ->values()
            ->all();
    }

    /** @param CarbonInterface|string|null $createdAt @return array<string, mixed> */
    private function signal(
        string $id,
        string $type,
        string $status,
        string $severity,
        CarbonInterface|string|null $createdAt,
        array $responsible,
        array $waitingOn,
        string $nextAction,
        string $href,
        ?string $reference,
        CarbonImmutable $snapshotAt,
    ): array {
        $created = $createdAt ? CarbonImmutable::parse($createdAt) : $snapshotAt;

        return [
            'id' => $id,
            'type' => $type,
            'reference' => $reference,
            'status' => $status,
            'severity' => $severity,
            'age_days' => max(0, $created->diffInDays($snapshotAt)),
            'created_at' => $created->toIso8601String(),
            'responsible' => $responsible,
            'waiting_on' => $waitingOn,
            'next_action' => $nextAction,
            'href' => $href,
        ];
    }

    /** @return array<string, mixed> */
    private function inventoryMetrics(int $shopOwnerId, array $businessCapabilities): array
    {
        $query = $this->inventorySourceQuery($shopOwnerId, $businessCapabilities);

        return [
            'total_items' => (int) (clone $query)->count(),
            'total_quantity' => (int) (clone $query)->sum('quantity'),
            'low_stock_count' => (int) (clone $query)
                ->whereRaw('quantity > 0 AND quantity <= reorder_level')
                ->count(),
            'out_of_stock_count' => (int) (clone $query)
                ->where('quantity', '<=', 0)
                ->count(),
        ];
    }

    /**
     * Build one shop-scoped inventory source from operational inventory rows
     * and active retail products that do not already have an active inventory
     * row. The outer query keeps filtering, aggregates, and pagination in SQL.
     *
     * @param array{businessType: string, canRetail: bool, canRepair: bool} $businessCapabilities
     */
    private function inventorySourceQuery(int $shopOwnerId, array $businessCapabilities): QueryBuilder
    {
        $canRetail = (bool) ($businessCapabilities['canRetail'] ?? false);
        $canRepair = (bool) ($businessCapabilities['canRepair'] ?? false);

        if (! $canRetail && ! $canRepair) {
            $canRetail = true;
            $canRepair = true;
        }

        $sources = [];

        if ($canRepair || $canRetail) {
            $inventoryQuery = DB::table('inventory_items')
                ->where('inventory_items.shop_owner_id', $shopOwnerId)
                ->where('inventory_items.is_active', true)
                ->whereNull('inventory_items.deleted_at');

            if (! $canRetail) {
                $inventoryQuery->where('inventory_items.category', 'repair_materials');
            } elseif (! $canRepair) {
                $inventoryQuery->where('inventory_items.category', '!=', 'repair_materials');
            }

            $sources[] = $inventoryQuery->select([
                'inventory_items.id',
                DB::raw("'inventory' as source_type"),
                DB::raw('inventory_items.id as source_id'),
                'inventory_items.name',
                'inventory_items.sku',
                'inventory_items.category',
                DB::raw('inventory_items.available_quantity as quantity'),
                'inventory_items.reorder_level',
                'inventory_items.price',
                'inventory_items.main_image as image',
                'inventory_items.updated_at',
            ]);
        }

        if ($canRetail) {
            $productQuery = DB::table('products')
                ->where('products.shop_owner_id', $shopOwnerId)
                ->where('products.is_active', true)
                ->whereNull('products.deleted_at')
                ->whereNotExists(function (QueryBuilder $query) use ($shopOwnerId): void {
                    $query->selectRaw('1')
                        ->from('inventory_items')
                        ->whereColumn('inventory_items.product_id', 'products.id')
                        ->where('inventory_items.shop_owner_id', $shopOwnerId)
                        ->where('inventory_items.is_active', true)
                        ->whereNull('inventory_items.deleted_at');
                });

            $sources[] = $productQuery->select([
                'products.id',
                DB::raw("'product' as source_type"),
                DB::raw('products.id as source_id'),
                'products.name',
                'products.sku',
                DB::raw("COALESCE(products.category, 'shoes') as category"),
                DB::raw('COALESCE(products.stock_quantity, 0) as quantity'),
                DB::raw('10 as reorder_level'),
                'products.price',
                'products.main_image as image',
                'products.updated_at',
            ]);
        }

        $union = $sources[0];
        foreach (array_slice($sources, 1) as $source) {
            $union->unionAll($source);
        }

        return DB::query()->fromSub($union, 'manager_inventory');
    }

    /** @return array<string, mixed> */
    public function businessCapabilities(int $shopOwnerId): array
    {
        $businessType = strtolower(trim((string) DB::table('shop_owners')->where('id', $shopOwnerId)->value('business_type')));
        $canRepair = str_contains($businessType, 'repair') || str_contains($businessType, 'service') || str_contains($businessType, 'both');
        $canRetail = str_contains($businessType, 'retail') || str_contains($businessType, 'shoe') || str_contains($businessType, 'product') || str_contains($businessType, 'both');

        if (! $canRetail && ! $canRepair) {
            $canRetail = true;
            $canRepair = true;
            $businessType = 'both';
        }

        return [
            'businessType' => $businessType,
            'canRetail' => $canRetail,
            'canRepair' => $canRepair,
        ];
    }

    /** @return array{percent: float, previous: float|int, baseline_available: bool, direction: string} */
    private function trend(float|int $current, float|int $previous): array
    {
        $current = (float) $current;
        $previous = (float) $previous;
        $baselineAvailable = $previous > 0;
        $percent = $baselineAvailable
            ? (($current - $previous) / $previous) * 100
            : ($current > 0 ? 100.0 : 0.0);

        return [
            'percent' => round($percent, 1),
            'previous' => $previous,
            'baseline_available' => $baselineAvailable,
            'direction' => $percent > 0 ? 'increase' : ($percent < 0 ? 'decrease' : 'flat'),
        ];
    }

    /** @return array{key: string, label: string, start: string, end: string, previous_start: string, previous_end: string, comparison: array{start: string, end: string}, timezone: string} */
    private function rangePayload(array $range): array
    {
        return [
            'key' => $range['key'],
            'label' => $range['label'],
            'start' => $range['start']->toIso8601String(),
            'end' => $range['end']->toIso8601String(),
            'previous_start' => $range['previous_start']->toIso8601String(),
            'previous_end' => $range['previous_end']->toIso8601String(),
            'comparison' => [
                'start' => $range['previous_start']->toIso8601String(),
                'end' => $range['previous_end']->toIso8601String(),
            ],
            'timezone' => (string) config('app.timezone'),
        ];
    }

    /** @return array{key: string, label: string, start: CarbonImmutable, end: CarbonImmutable, previous_start: CarbonImmutable, previous_end: CarbonImmutable} */
    private function resolveRange(string $requestedRange, CarbonImmutable $now): array
    {
        $normalized = strtolower(trim($requestedRange));
        $end = $now->endOfDay();

        [$key, $label, $start] = match ($normalized) {
            '7d', 'last_7_days' => ['last_7_days', 'Last 7 days', $now->subDays(6)->startOfDay()],
            '90d', 'last_90_days', 'quarter' => ['last_90_days', 'Last 90 days', $now->subDays(89)->startOfDay()],
            '365d', 'last_365_days', 'year' => ['last_365_days', 'Last 365 days', $now->subDays(364)->startOfDay()],
            'mtd', 'month_to_date' => ['month_to_date', 'Month to date', $now->startOfMonth()],
            default => ['last_30_days', 'Last 30 days', $now->subDays(29)->startOfDay()],
        };

        $days = max(1, $start->diffInDays($end) + 1);
        $previousEnd = $start->subSecond();
        $previousStart = $previousEnd->subDays($days - 1)->startOfDay();

        return compact('key', 'label', 'start', 'end', 'previousStart', 'previousEnd') + [
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
        ];
    }

    /** @return array<string, mixed> */
    private function mapInventoryItem(object $item): array
    {
        $sourceType = (string) ($item->source_type ?? 'inventory');
        $sourceId = (int) ($item->source_id ?? $item->id);
        $image = $item->image ?? null;
        $quantity = (int) $item->quantity;
        $reorderLevel = (int) $item->reorder_level;

        if ($image && ! str_starts_with($image, 'http://') && ! str_starts_with($image, 'https://') && ! str_starts_with($image, '/')) {
            $image = asset('storage/'.ltrim($image, '/'));
        }

        return [
            'id' => (int) $item->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'name' => (string) $item->name,
            'sku' => (string) ($item->sku ?: 'PRODUCT-'.$sourceId),
            'category' => (string) $item->category,
            'quantity' => $quantity,
            'price' => (float) ($item->price ?? 0),
            'status' => $quantity <= 0
                ? 'Out of Stock'
                : ($quantity <= $reorderLevel ? 'Low Stock' : 'In Stock'),
            'image' => $image,
            'last_updated' => $item->updated_at,
        ];
    }
}
