<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\RepairRequest;
use App\Models\User;
use App\Services\Manager\ManagerAssignmentEligibilityService;
use App\Services\Manager\ManagerAuthorizationService;
use App\Services\Manager\ManagerDashboardService;
use App\Services\Manager\ManagerReportService;
use App\Support\Erp\ErpActorContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManagerController extends Controller
{
    /**
     * Get dashboard statistics for Manager
     */
    public function getDashboardStats(Request $request)
    {
        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $authorization = app(ManagerAuthorizationService::class);
        $shopOwnerId = $authorization->shopOwnerId($user);

        if ($shopOwnerId === null || ! $authorization->allows(
            $user,
            ManagerAuthorizationService::DASHBOARD_READ,
            $shopOwnerId,
        )) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        try {
            return response()->json(app(ManagerDashboardService::class)->dashboard(
                $shopOwnerId,
                (string) $request->input('range', 'last_30_days'),
            ));
        } catch (\Throwable $exception) {
            Log::error('Failed to load Manager dashboard snapshot.', [
                'exception' => $exception,
                'manager_id' => $user->id,
                'shop_owner_id' => $shopOwnerId,
            ]);

            return response()->json(['error' => 'Failed to load dashboard statistics.'], 500);
        }

    }

    public function getInventoryOverview(Request $request)
    {
        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $authorization = app(ManagerAuthorizationService::class);
        $shopOwnerId = $authorization->shopOwnerId($user);

        if ($shopOwnerId === null || ! $authorization->allows(
            $user,
            ManagerAuthorizationService::INVENTORY_READ,
            $shopOwnerId,
        )) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        try {
            return response()->json(app(ManagerDashboardService::class)->inventory(
                $shopOwnerId,
                $validated,
            ));
        } catch (\Throwable $exception) {
            Log::error('Failed to load Manager inventory overview.', [
                'exception' => $exception,
                'manager_id' => $user->id,
                'shop_owner_id' => $shopOwnerId,
            ]);

            return response()->json(['error' => 'Failed to load inventory overview.'], 500);
        }

    }

    private function managerReportActor(string $capability): array|\Illuminate\Http\JsonResponse
    {
        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $authorization = app(ManagerAuthorizationService::class);
        $shopOwnerId = $authorization->shopOwnerId($user);

        if ($shopOwnerId === null || ! $authorization->allows($user, $capability, $shopOwnerId)) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        return [$user, $shopOwnerId];
    }

    public function getReports(Request $request)
    {
        $context = $request->attributes->get('erp.actor_context');

        if ($context instanceof ErpActorContext && $context->isOwnerMode()) {
            return response()->json(app(ManagerReportService::class)->list(
                (int) $context->tenantOwner()->getKey(),
            ));
        }

        $actor = $this->managerReportActor(ManagerAuthorizationService::REPORTS_READ);

        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        [, $shopOwnerId] = $actor;

        try {
            return response()->json(app(ManagerReportService::class)->list($shopOwnerId));
        } catch (\Throwable $exception) {
            Log::error('Failed to load Manager reports.', [
                'exception' => $exception,
                'shop_owner_id' => $shopOwnerId,
            ]);

            return response()->json(['error' => 'Failed to load reports.'], 500);
        }
    }

    public function generateReport(Request $request)
    {
        $actor = $this->managerReportActor(ManagerAuthorizationService::REPORTS_GENERATE);

        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        [$user, $shopOwnerId] = $actor;

        $validated = $request->validate([
            'report_type' => [
                'required',
                'string',
                Rule::in(array_keys(app(ManagerReportService::class)->definitions())),
            ],
            'date_range' => ['required', 'string', 'in:week,month,quarter,year'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $headerKey = trim((string) $request->header('Idempotency-Key', ''));
            $idempotencyKey = $validated['idempotency_key'] ?? ($headerKey !== '' ? $headerKey : null);
            $report = app(ManagerReportService::class)->generate(
                $shopOwnerId,
                $user,
                $validated['report_type'],
                $validated['date_range'],
                $validated['notes'] ?? null,
                $idempotencyKey,
            );

            return response()->json([
                'report' => app(ManagerReportService::class)->map($report),
                'download_url' => url("/api/manager/reports/{$report->id}/download"),
            ], 201);
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            return response()->json([
                'error' => 'REPORT_ACTION_CONFLICT',
                'message' => 'The selected report cannot be generated with the supplied request.',
            ], 409);
        } catch (\Throwable $exception) {
            Log::error('Failed to generate Manager report.', [
                'exception' => $exception,
                'manager_id' => $user->id,
                'shop_owner_id' => $shopOwnerId,
            ]);

            return response()->json(['error' => 'Failed to generate report.'], 500);
        }
    }

    public function reviewReport(Request $request, int $id)
    {
        $actor = $this->managerReportActor(ManagerAuthorizationService::REPORTS_REVIEW);

        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        [$user, $shopOwnerId] = $actor;
        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $service = app(ManagerReportService::class);
            $report = $service->review($shopOwnerId, $id, $user, $validated['notes']);

            return response()->json([
                'message' => 'Report marked as reviewed.',
                'report' => $service->map($report),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            throw $exception;
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            return response()->json([
                'error' => 'REPORT_ACTION_CONFLICT',
                'message' => 'The report is not available for review.',
            ], 409);
        } catch (\Throwable $exception) {
            Log::error('Failed to review Manager report.', [
                'exception' => $exception,
                'manager_id' => $user->id,
                'shop_owner_id' => $shopOwnerId,
                'report_id' => $id,
            ]);

            return response()->json(['error' => 'Failed to review report.'], 500);
        }
    }

    /**
     * Compatibility alias for clients that still call the former send route.
     * It deliberately performs the same review transition and never claims
     * that a delivery occurred.
     */
    public function sendReport(Request $request, int $id)
    {
        return $this->reviewReport($request, $id);
    }

    public function downloadReport(Request $request, int $id)
    {
        $actor = $this->managerReportActor(ManagerAuthorizationService::REPORTS_READ);

        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        [, $shopOwnerId] = $actor;

        try {
            $service = app(ManagerReportService::class);
            $report = $service->reportForDownload($shopOwnerId, $id);

            return response()->download(
                Storage::disk('local')->path($report->file_path),
                $service->downloadFileName($report),
                ['Content-Type' => 'text/csv'],
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Failed to download Manager report.', [
                'exception' => $exception,
                'shop_owner_id' => $shopOwnerId,
                'report_id' => $id,
            ]);

            return response()->json(['error' => 'Failed to download report.'], 500);
        }
    }

    /**
     * Keep the legacy endpoint on the same canonical workload response.
     */
    public function getStaffPerformance(Request $request)
    {
        return $this->getStaffWorkload($request);
    }

    /**
     * Return shop-scoped current workload and period metrics by assigned user.
     */
    public function getStaffWorkload(Request $request)
    {
        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $authorization = app(ManagerAuthorizationService::class);
        $shopOwnerId = $authorization->shopOwnerId($user);

        if ($shopOwnerId === null || ! $authorization->allows(
            $user,
            ManagerAuthorizationService::STAFF_WORKLOAD_READ,
            $shopOwnerId,
        )) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:40'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        try {
            $snapshotAt = now();
            $period = $this->staffWorkloadPeriod($validated, $snapshotAt);
            $businessCapabilities = app(ManagerDashboardService::class)->businessCapabilities($shopOwnerId);
            $orderAggregates = $businessCapabilities['canRetail']
                ? $this->staffOrderAggregates($shopOwnerId, $snapshotAt)
                : collect();
            $repairAggregates = $businessCapabilities['canRepair']
                ? $this->staffRepairAggregates($shopOwnerId, $snapshotAt)
                : collect();
            $periodOrderAggregates = $businessCapabilities['canRetail']
                ? $this->staffPeriodOrderAggregates($shopOwnerId, $period)
                : collect();
            $periodRepairAggregates = $businessCapabilities['canRepair']
                ? $this->staffPeriodRepairAggregates($shopOwnerId, $period)
                : collect();

            $staffQuery = User::query()
                ->with(['employee.leaveRequests', 'roles'])
                ->where('shop_owner_id', $shopOwnerId)
                ->where(function ($query): void {
                    $query
                        ->whereRaw("UPPER(COALESCE(role, '')) IN (?, ?)", ['STAFF', 'REPAIRER'])
                        ->orWhereHas('roles', function ($roleQuery): void {
                            $roleQuery->whereIn('name', ['Staff', 'Repairer']);
                        });
                });

            $this->applyStaffWorkloadFilters($staffQuery, $validated);

            $perPage = (int) ($validated['per_page'] ?? 25);
            $paginator = $staffQuery
                ->orderBy('name')
                ->orderBy('id')
                ->paginate($perPage)
                ->withQueryString();

            $eligibility = app(ManagerAssignmentEligibilityService::class);
            $paginator->setCollection(
                $paginator->getCollection()->map(function (User $staff) use (
                    $shopOwnerId,
                    $snapshotAt,
                    $period,
                    $orderAggregates,
                    $repairAggregates,
                    $periodOrderAggregates,
                    $periodRepairAggregates,
                    $eligibility,
                    $businessCapabilities,
                ): array {
                    $staffId = (int) $staff->id;
                    $orders = $orderAggregates->get($staffId);
                    $repairs = $repairAggregates->get($staffId);
                    $periodOrders = $periodOrderAggregates->get($staffId);
                    $periodRepairs = $periodRepairAggregates->get($staffId);
                    $defaultDecision = [
                        'eligible' => true,
                        'reason_code' => null,
                        'reason_label' => null,
                    ];
                    $orderDecision = $businessCapabilities['canRetail']
                        ? $eligibility->evaluate(
                            assignee: $staff,
                            shopOwnerId: $shopOwnerId,
                            workType: 'order',
                            activeWorkDate: $snapshotAt,
                        )
                        : $defaultDecision;
                    $repairDecision = $businessCapabilities['canRepair']
                        ? $eligibility->evaluate(
                            assignee: $staff,
                            shopOwnerId: $shopOwnerId,
                            workType: 'repair',
                            activeWorkDate: $snapshotAt,
                        )
                        : $defaultDecision;

                    $activeOrders = $businessCapabilities['canRetail']
                        ? (int) ($orders->active_orders ?? 0)
                        : 0;
                    $activeRepairs = $businessCapabilities['canRepair']
                        ? (int) ($repairs->active_repairs ?? 0)
                        : 0;
                    $requiresOrderReassignment = $activeOrders > 0 && ! $orderDecision['eligible'];
                    $requiresRepairReassignment = $activeRepairs > 0 && ! $repairDecision['eligible'];
                    $status = $this->staffEffectiveStatus($staff);
                    $availabilityState = $this->staffAvailabilityState($status, $orderDecision, $repairDecision);
                    $totalActiveWork = $activeOrders + $activeRepairs;

                    return [
                        'id' => $staffId,
                        'name' => $this->staffDisplayName($staff),
                        'email' => (string) $staff->email,
                        'role' => $this->staffPrimaryRole($staff),
                        'position' => (string) ($staff->employee?->position ?? ''),
                        'status' => $status,
                        'availability_state' => $availabilityState,
                        'active_orders' => $activeOrders,
                        'active_repairs' => $activeRepairs,
                        'overdue_work' => ($businessCapabilities['canRetail'] ? (int) ($orders->overdue_orders ?? 0) : 0)
                            + ($businessCapabilities['canRepair'] ? (int) ($repairs->overdue_repairs ?? 0) : 0),
                        'period_orders' => $businessCapabilities['canRetail'] ? (int) ($periodOrders->period_orders ?? 0) : 0,
                        'period_completed_orders' => $businessCapabilities['canRetail'] ? (int) ($periodOrders->completed_orders ?? 0) : 0,
                        'period_repairs' => $businessCapabilities['canRepair'] ? (int) ($periodRepairs->period_repairs ?? 0) : 0,
                        'period_completed_repairs' => $businessCapabilities['canRepair'] ? (int) ($periodRepairs->completed_repairs ?? 0) : 0,
                        'total_active_work' => $totalActiveWork,
                        'capacity' => [
                            'active_work' => $totalActiveWork,
                            'limit' => null,
                            'utilization_percent' => null,
                            'state' => $totalActiveWork > 0 ? 'assigned' : 'available',
                        ],
                        'requires_order_reassignment' => $requiresOrderReassignment,
                        'requires_repair_reassignment' => $requiresRepairReassignment,
                        'reassignment_reason' => $requiresOrderReassignment
                            ? $orderDecision['reason_label']
                            : ($requiresRepairReassignment ? $repairDecision['reason_label'] : null),
                        'next_action' => $this->staffNextAction($requiresOrderReassignment, $requiresRepairReassignment, $totalActiveWork),
                        'last_updated_at' => $staff->updated_at?->toISOString(),
                        'links' => [
                            'orders' => '/erp/manager/job-orders?handler_id='.$staffId,
                            'repairs' => '/erp/manager/repair-jobs?repairer_id='.$staffId,
                        ],
                        'period' => [
                            'start' => $period['start']->toISOString(),
                            'end' => $period['end']->toISOString(),
                        ],
                    ];
                }),
            );

            return response()->json([
                'data' => $paginator,
                'business_capabilities' => [
                    'business_type' => $businessCapabilities['businessType'],
                    'can_retail' => $businessCapabilities['canRetail'],
                    'can_repair' => $businessCapabilities['canRepair'],
                ],
                'period' => [
                    'start' => $period['start']->toISOString(),
                    'end' => $period['end']->toISOString(),
                ],
                'last_updated_at' => $snapshotAt->toISOString(),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to load Manager staff workload.', [
                'exception' => $exception,
                'manager_id' => $user->id,
                'shop_owner_id' => $shopOwnerId,
            ]);

            return response()->json(['error' => 'Failed to load staff workload.'], 500);
        }
    }

    /** @param array<string, mixed> $filters */
    private function applyStaffWorkloadFilters($query, array $filters): void
    {
        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($nested) use ($like): void {
                $nested
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('employee', fn ($employee) => $employee->where('name', 'like', $like)->orWhere('position', 'like', $like));
            });
        }

        if (($role = trim((string) ($filters['role'] ?? ''))) !== '') {
            $role = strtoupper($role);
            $query->where(function ($nested) use ($role): void {
                $nested
                    ->whereRaw('UPPER(role) = ?', [$role])
                    ->orWhereHas('roles', fn ($roles) => $roles->whereRaw('UPPER(name) = ?', [$role]));
            });
        }

        if (($status = strtolower(trim((string) ($filters['status'] ?? '')))) !== '') {
            $query->where(function ($nested) use ($status): void {
                $nested
                    ->whereRaw('LOWER(status) = ?', [$status])
                    ->orWhereHas('employee', fn ($employee) => $employee->whereRaw('LOWER(status) = ?', [$status]));
            });
        }
    }

    /** @param array<string, mixed> $filters @return array{start: CarbonImmutable, end: CarbonImmutable} */
    private function staffWorkloadPeriod(array $filters, $snapshotAt): array
    {
        $start = isset($filters['date_from'])
            ? CarbonImmutable::parse((string) $filters['date_from'])->startOfDay()
            : CarbonImmutable::instance($snapshotAt)->subDays(29)->startOfDay();
        $end = isset($filters['date_to'])
            ? CarbonImmutable::parse((string) $filters['date_to'])->endOfDay()
            : CarbonImmutable::instance($snapshotAt)->endOfDay();

        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages([
                'date_from' => ['The workload period start must be before its end.'],
            ]);
        }

        return ['start' => $start, 'end' => $end];
    }

    private function staffOrderAggregates(int $shopOwnerId, $snapshotAt)
    {
        $query = DB::table('orders')
            ->where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('assigned_staff_id')
            ->whereNotIn('status', ['delivered', 'completed', 'cancelled', 'refund']);

        $slaMinutes = $this->managerSlaMinutes('manager.order_sla_minutes');
        $overdueExpression = $slaMinutes === null
            ? '0'
            : 'SUM(CASE WHEN created_at <= ? THEN 1 ELSE 0 END)';
        $bindings = $slaMinutes === null ? [] : [CarbonImmutable::instance($snapshotAt)->subMinutes($slaMinutes)];

        return $query
            ->select('assigned_staff_id')
            ->selectRaw('COUNT(*) as active_orders')
            ->selectRaw($overdueExpression.' as overdue_orders', $bindings)
            ->groupBy('assigned_staff_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->assigned_staff_id);
    }

    private function staffRepairAggregates(int $shopOwnerId, $snapshotAt)
    {
        $activeStatuses = [
            'assigned_to_repairer',
            'repairer_accepted',
            'pending',
            'received',
            'in_progress',
            'awaiting_parts',
            'waiting_customer_confirmation',
            'confirmed',
            'owner_approved',
            'manager_approved',
        ];
        $query = DB::table('repair_requests')
            ->where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('assigned_repairer_id')
            ->whereIn('status', $activeStatuses);

        $slaMinutes = $this->managerSlaMinutes('manager.repair_sla_minutes');
        $overdueExpression = $slaMinutes === null
            ? '0'
            : 'SUM(CASE WHEN created_at <= ? THEN 1 ELSE 0 END)';
        $bindings = $slaMinutes === null ? [] : [CarbonImmutable::instance($snapshotAt)->subMinutes($slaMinutes)];

        return $query
            ->select('assigned_repairer_id')
            ->selectRaw('COUNT(*) as active_repairs')
            ->selectRaw($overdueExpression.' as overdue_repairs', $bindings)
            ->groupBy('assigned_repairer_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->assigned_repairer_id);
    }

    /** @param array{start: CarbonImmutable, end: CarbonImmutable} $period */
    private function staffPeriodOrderAggregates(int $shopOwnerId, array $period)
    {
        return DB::table('orders')
            ->where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('assigned_staff_id')
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->select('assigned_staff_id')
            ->selectRaw('COUNT(*) as period_orders')
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as completed_orders', ['completed', 'delivered'])
            ->groupBy('assigned_staff_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->assigned_staff_id);
    }

    /** @param array{start: CarbonImmutable, end: CarbonImmutable} $period */
    private function staffPeriodRepairAggregates(int $shopOwnerId, array $period)
    {
        return DB::table('repair_requests')
            ->where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('assigned_repairer_id')
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->select('assigned_repairer_id')
            ->selectRaw('COUNT(*) as period_repairs')
            ->selectRaw('SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) as completed_repairs', ['completed', 'ready_for_pickup', 'picked_up'])
            ->groupBy('assigned_repairer_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->assigned_repairer_id);
    }

    private function managerSlaMinutes(string $key): ?int
    {
        $configured = config($key);

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : null;
    }

    private function staffEffectiveStatus(User $staff): string
    {
        if ($staff->trashed()) {
            return 'offboarded';
        }

        $userStatus = strtolower(trim((string) ($staff->getRawOriginal('status') ?? 'active')));
        if (in_array($userStatus, ['inactive', 'suspended', 'terminated', 'resigned', 'offboarded'], true)) {
            return $userStatus;
        }

        $employeeStatus = $staff->employee?->getRawOriginal('status');
        $employeeStatus = strtolower(trim((string) ($employeeStatus ?? '')));

        return $employeeStatus !== '' && $employeeStatus !== 'active' ? $employeeStatus : 'active';
    }

    /** @param array{eligible: bool, reason_code: ?string, reason_label: ?string} $orderDecision @param array{eligible: bool, reason_code: ?string, reason_label: ?string} $repairDecision */
    private function staffAvailabilityState(string $status, array $orderDecision, array $repairDecision): string
    {
        if ($status !== 'active') {
            return $status;
        }

        if ($orderDecision['reason_code'] === 'approved_leave' || $repairDecision['reason_code'] === 'approved_leave') {
            return 'approved_leave';
        }

        if ($repairDecision['reason_code'] === 'explicitly_unavailable') {
            return 'explicitly_unavailable';
        }

        return 'active';
    }

    private function staffDisplayName(User $staff): string
    {
        return trim((string) ($staff->name ?: (($staff->first_name ?? '').' '.($staff->last_name ?? '')))) ?: (string) $staff->email;
    }

    private function staffPrimaryRole(User $staff): string
    {
        $role = strtoupper(trim((string) $staff->role));

        if ($role === '') {
            $role = strtoupper(trim((string) ($staff->roles->first()?->name ?? '')));
        }

        return $role !== '' ? ucfirst(strtolower($role)) : 'Staff';
    }

    private function staffNextAction(bool $requiresOrderReassignment, bool $requiresRepairReassignment, int $totalActiveWork): string
    {
        if ($requiresOrderReassignment && $requiresRepairReassignment) {
            return 'Review order and repair assignments';
        }
        if ($requiresOrderReassignment) {
            return 'Review order reassignment';
        }
        if ($requiresRepairReassignment) {
            return 'Review repair reassignment';
        }
        if ($totalActiveWork > 0) {
            return 'Current handler to continue';
        }

        return 'No active work';
    }
}
