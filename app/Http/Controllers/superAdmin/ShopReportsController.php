<?php

declare(strict_types=1);

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\ModerateShopReportsRequest;
use App\Models\ShopOwner;
use App\Models\ShopReport;
use App\Models\ShopReportModerationAction;
use App\Models\SuperAdmin;
use App\Services\ShopReportModerationService;
use App\Support\PrivilegedFailureResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ShopReportsController extends Controller
{
    private const WARNINGS_BEFORE_SUSPENSION = 3;

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(['all', 'high', 'medium', 'normal'])],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['all', 'open', 'resolved'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $openStatuses = ['submitted', 'under_review'];
        $resolvedStatuses = ['dismissed', 'warned', 'suspended'];
        $groupQuery = ShopReport::query()
            ->leftJoin('shop_owners', 'shop_owners.id', '=', 'shop_reports.shop_owner_id')
            ->select('shop_reports.shop_owner_id')
            ->selectRaw('COUNT(shop_reports.id) AS total_reports')
            ->selectRaw(
                'SUM(CASE WHEN shop_reports.status IN (?, ?) THEN 1 ELSE 0 END) AS open_reports',
                $openStatuses,
            )
            ->selectRaw('MAX(shop_reports.created_at) AS latest_date')
            ->selectSub(
                DB::table('shop_reports AS latest_report')
                    ->select('latest_report.reason')
                    ->whereColumn('latest_report.shop_owner_id', 'shop_reports.shop_owner_id')
                    ->orderByDesc('latest_report.created_at')
                    ->orderByDesc('latest_report.id')
                    ->limit(1),
                'latest_reason',
            )
            ->addSelect([
                'shop_owners.business_name AS business_name',
                'shop_owners.email AS shop_email',
                'shop_owners.status AS shop_status',
            ])
            ->groupBy([
                'shop_reports.shop_owner_id',
                'shop_owners.business_name',
                'shop_owners.email',
                'shop_owners.status',
            ]);

        if (($validated['search'] ?? null) !== null && $validated['search'] !== '') {
            $search = (string) $validated['search'];
            $groupQuery->where(function (Builder $searchQuery) use ($search): void {
                $this->whereContains($searchQuery, 'shop_owners.business_name', $search);
                $this->whereContains($searchQuery, 'shop_owners.email', $search, 'or');
            });
        }

        if (($validated['status'] ?? null) === 'open') {
            $groupQuery->whereIn('shop_reports.status', $openStatuses);
        } elseif (($validated['status'] ?? null) === 'resolved') {
            $groupQuery->whereIn('shop_reports.status', $resolvedStatuses);
        }

        $priority = $validated['priority'] ?? 'all';
        if ($priority === 'high') {
            $groupQuery->having('open_reports', '>=', 5);
        } elseif ($priority === 'medium') {
            $groupQuery->havingRaw('open_reports BETWEEN ? AND ?', [3, 4]);
        } elseif ($priority === 'normal') {
            $groupQuery->having('open_reports', '<', 3);
        }

        $shopGroups = $groupQuery
            ->orderByDesc('open_reports')
            ->orderByDesc('latest_date')
            ->orderByDesc('shop_reports.shop_owner_id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString();

        $shopOwnerIds = $shopGroups->getCollection()
            ->pluck('shop_owner_id')
            ->map(static fn ($id): int => (int) $id)
            ->values();
        $warningCountsByShop = $this->warningCountsFor($shopOwnerIds);
        $patternFlagsByShop = $this->patternFlagsFor($shopOwnerIds);

        $shopGroups->setCollection($shopGroups->getCollection()->map(function ($group) use ($warningCountsByShop, $patternFlagsByShop): array {
            $shopOwnerId = (int) $group->shop_owner_id;
            $openReports = (int) $group->open_reports;
            $warningStrike = (int) ($warningCountsByShop->get($shopOwnerId) ?? 0);
            $warningsUntilSuspension = max(0, self::WARNINGS_BEFORE_SUSPENSION - $warningStrike);

            return [
                'shop_owner_id' => $shopOwnerId,
                'business_name' => $group->business_name ?? '—',
                'shop_email' => $group->shop_email ?? '—',
                'shop_status' => $group->shop_status ?? '—',
                'total_reports' => (int) $group->total_reports,
                'open_reports' => $openReports,
                'latest_reason' => ShopReport::REASON_LABELS[$group->latest_reason] ?? ($group->latest_reason ?? '—'),
                'latest_date' => $group->latest_date,
                'pattern_flags' => $patternFlagsByShop->get($shopOwnerId, []),
                'warning_strike' => $warningStrike,
                'warning_limit' => self::WARNINGS_BEFORE_SUSPENSION,
                'warnings_until_suspension' => $warningsUntilSuspension,
                'next_warn_will_suspend' => $warningsUntilSuspension <= 1,
                'priority' => $openReports >= 5 ? 'high' : ($openReports >= 3 ? 'medium' : 'normal'),
            ];
        }));

        $baseQuery = ShopReport::query();
        $highPriorityQuery = (clone $baseQuery)
            ->whereIn('status', $openStatuses)
            ->select('shop_owner_id')
            ->groupBy('shop_owner_id')
            ->havingRaw('COUNT(*) >= ?', [5]);
        $stats = [
            'total_reports' => (clone $baseQuery)->count(),
            'pending_review' => (clone $baseQuery)->whereIn('status', $openStatuses)->count(),
            'high_priority' => DB::query()->fromSub($highPriorityQuery, 'high_priority_shops')->count(),
            'resolved' => (clone $baseQuery)->whereIn('status', $resolvedStatuses)->count(),
        ];

        return Inertia::render('superAdmin/Shops/ShopReports', [
            'shopGroups' => $shopGroups,
            'stats' => $stats,
            'filters' => [
                'search' => $validated['search'] ?? null,
                'priority' => $priority,
                'status' => $validated['status'] ?? 'all',
            ],
        ]);
    }

    public function show(Request $request, int $shopOwner): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        ShopOwner::withTrashed()->whereKey($shopOwner)->firstOrFail();

        $reports = ShopReport::query()
            ->where('shop_owner_id', $shopOwner)
            ->select([
                'id',
                'user_id',
                'shop_owner_id',
                'reason',
                'description',
                'transaction_type',
                'transaction_id',
                'status',
                'admin_notes',
                'reviewed_at',
                'ip_address',
                'created_at',
            ])
            ->with([
                'reporter' => static function ($reporterQuery): void {
                    $reporterQuery->select(['id', 'first_name', 'last_name', 'email', 'created_at']);
                },
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString()
            ->through(static fn (ShopReport $report): array => self::formatReport($report));

        $openReportIds = collect($reports->items())
            ->filter(static fn (array $report): bool => in_array($report['status'], ['submitted', 'under_review'], true))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        return response()->json([
            'shop_owner_id' => $shopOwner,
            'open_report_ids' => $openReportIds,
            'reports' => $reports,
        ]);
    }

    /** @param Collection<int, int> $shopOwnerIds */
    private function warningCountsFor(Collection $shopOwnerIds): Collection
    {
        if ($shopOwnerIds->isEmpty()) {
            return collect();
        }

        return ShopReportModerationAction::query()
            ->selectRaw('shop_owner_id, MAX(warning_strike_number) AS warning_count')
            ->whereNotNull('warning_strike_number')
            ->whereIn('shop_owner_id', $shopOwnerIds->all())
            ->groupBy('shop_owner_id')
            ->pluck('warning_count', 'shop_owner_id')
            ->mapWithKeys(static fn ($count, $id): array => [(int) $id => (int) $count]);
    }

    /** @param Collection<int, int> $shopOwnerIds */
    private function patternFlagsFor(Collection $shopOwnerIds): Collection
    {
        if ($shopOwnerIds->isEmpty()) {
            return collect();
        }

        $now = now();
        $flags = [];
        foreach ($shopOwnerIds as $shopOwnerId) {
            $flags[(int) $shopOwnerId] = [];
        }

        $openStatuses = ['submitted', 'under_review'];
        $batchCounts = ShopReport::query()
            ->selectRaw('shop_owner_id, COUNT(*) AS pattern_count')
            ->whereIn('shop_owner_id', $shopOwnerIds->all())
            ->whereIn('status', $openStatuses)
            ->where('created_at', '>', $now->copy()->subHours(3))
            ->where('created_at', '<', $now->copy()->addHours(3))
            ->groupBy('shop_owner_id')
            ->havingRaw('COUNT(*) >= ?', [5])
            ->pluck('pattern_count', 'shop_owner_id');

        foreach ($batchCounts as $shopOwnerId => $count) {
            $flags[(int) $shopOwnerId][] = 'batch_reports';
        }

        $newReporterCounts = ShopReport::query()
            ->join('users', 'users.id', '=', 'shop_reports.user_id')
            ->selectRaw('shop_reports.shop_owner_id, COUNT(*) AS pattern_count')
            ->whereIn('shop_reports.shop_owner_id', $shopOwnerIds->all())
            ->whereIn('shop_reports.status', $openStatuses)
            ->where('users.created_at', '>', $now->copy()->subDays(8))
            ->where('users.created_at', '<', $now->copy()->addDays(8))
            ->groupBy('shop_reports.shop_owner_id')
            ->havingRaw('COUNT(*) >= ?', [3])
            ->pluck('pattern_count', 'shop_reports.shop_owner_id');

        foreach ($newReporterCounts as $shopOwnerId => $count) {
            $flags[(int) $shopOwnerId][] = 'new_account_reporters';
        }

        $driver = DB::connection()->getDriverName();
        $ipPrefix = in_array($driver, ['mysql', 'mariadb'], true)
            ? "SUBSTRING_INDEX(shop_reports.ip_address, '.', 3)"
            : "substr(shop_reports.ip_address, 1, instr(shop_reports.ip_address, '.') + instr(substr(shop_reports.ip_address, instr(shop_reports.ip_address, '.') + 1), '.') - 1)";
        $clusteredShops = ShopReport::query()
            ->selectRaw("shop_reports.shop_owner_id, {$ipPrefix} AS ip_prefix")
            ->selectRaw('COUNT(*) AS pattern_count')
            ->whereIn('shop_reports.shop_owner_id', $shopOwnerIds->all())
            ->whereIn('shop_reports.status', $openStatuses)
            ->whereNotNull('shop_reports.ip_address')
            ->where('shop_reports.ip_address', 'like', '%.%')
            ->groupBy('shop_reports.shop_owner_id')
            ->groupByRaw($ipPrefix)
            ->havingRaw('COUNT(*) >= ?', [3])
            ->pluck('ip_prefix', 'shop_owner_id');

        foreach ($clusteredShops as $shopOwnerId => $prefix) {
            $flags[(int) $shopOwnerId][] = 'ip_clustering';
        }

        return collect($flags);
    }

    private function whereContains(Builder $query, string $column, string $value, string $boolean = 'and'): void
    {
        $escaped = strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
        $query->whereRaw(
            "{$column} LIKE ? ESCAPE '!'",
            ["%{$escaped}%"],
            $boolean,
        );
    }

    public function action(
        ModerateShopReportsRequest $request,
        int $id,
        ShopReportModerationService $moderation,
        PrivilegedFailureResponse $failures,
    ): mixed {
        $admin = Auth::guard('super_admin')->user();
        abort_unless($admin instanceof SuperAdmin, 403);

        try {
            $result = $moderation->moderate(
                shopOwnerId: $id,
                reportIds: (array) $request->validated('report_ids'),
                requestedAction: (string) $request->validated('action'),
                notes: $request->validated('admin_notes'),
                actor: $admin,
                request: $request,
            );

            $action = $result['action'];
            $requestedAction = (string) $action->requested_action;
            $effectiveAction = (string) $action->applied_action;
            $message = $effectiveAction === 'suspend' && $requestedAction === 'warn'
                ? 'Shop reached 3 warnings and has been suspended automatically.'
                : match ($effectiveAction) {
                    'dismiss' => 'Reports dismissed successfully.',
                    'warn' => 'Shop has been warned and reports updated.',
                    'suspend' => 'Shop has been suspended and reports resolved.',
                };

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'changed' => $result['changed'],
                    'action' => [
                        'id' => (int) $action->getKey(),
                        'shop_owner_id' => (int) $action->shop_owner_id,
                        'requested_action' => $requestedAction,
                        'applied_action' => $effectiveAction,
                        'report_ids' => array_values(array_map('intval', (array) $action->report_ids)),
                        'decision_key' => (string) $action->decision_key,
                        'warning_strike_number' => $action->warning_strike_number,
                    ],
                    'suspension_id' => $result['suspension_id'],
                ]);
            }

            return back()->with('success', $message);
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();
            if ($status === 409) {
                return $failures->conflict(
                    request: $request,
                    operation: 'shop_report',
                    message: 'The shop report decision conflicts with current state.',
                    code: 'shop_report_conflict',
                );
            }

            if ($status === 422) {
                return $failures->validation($request, 'The shop report decision input is invalid.', 'shop_report_validation');
            }

            return $failures->unexpected(
                request: $request,
                operation: 'shop_report',
                exception: $exception,
                message: 'The shop report decision could not be completed.',
                code: 'shop_report_error',
            );
        } catch (Throwable $exception) {
            return $failures->unexpected(
                request: $request,
                operation: 'shop_report',
                exception: $exception,
                message: 'The shop report decision could not be completed.',
                code: 'shop_report_error',
            );
        }
    }

    private static function formatReport(ShopReport $report): array
    {
        return [
            'id' => $report->id,
            'reason' => $report->reason,
            'reason_label' => ShopReport::REASON_LABELS[$report->reason] ?? $report->reason,
            'description' => $report->description,
            'status' => $report->status,
            'status_label' => ShopReport::STATUS_LABELS[$report->status] ?? $report->status,
            'transaction_type' => $report->transaction_type,
            'transaction_id' => $report->transaction_id,
            'admin_notes' => $report->admin_notes,
            'reviewed_at' => $report->reviewed_at?->toDateTimeString(),
            'ip_address' => $report->ip_address,
            'created_at' => $report->created_at?->toDateTimeString(),
            'reporter' => $report->reporter ? [
                'id' => $report->reporter->id,
                'name' => trim(($report->reporter->first_name ?? '') . ' ' . ($report->reporter->last_name ?? '')),
                'email' => $report->reporter->email,
                'created_at' => $report->reporter->created_at?->toDateTimeString(),
                'days_old' => $report->reporter->created_at?->diffInDays(now()),
            ] : null,
        ];
    }
}
