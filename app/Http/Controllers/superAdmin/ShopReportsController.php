<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\ModerateShopReportsRequest;
use App\Models\ShopOwner;
use App\Models\ShopReport;
use App\Models\ShopReportModerationAction;
use App\Models\SuperAdmin;
use App\Services\ShopReportModerationService;
use App\Support\PrivilegedFailureResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ShopReportsController extends Controller
{
    private const WARNINGS_BEFORE_SUSPENSION = 3;

    public function index(): Response
    {
        $allReports = ShopReport::with(['reporter', 'shop'])
            ->orderBy('created_at', 'desc')
            ->get();

        $shopOwnerIds = $allReports
            ->pluck('shop_owner_id')
            ->filter()
            ->unique()
            ->values();

        $warningCountsByShop = ShopReportModerationAction::query()
            ->selectRaw('shop_owner_id, MAX(warning_strike_number) AS warning_count')
            ->whereNotNull('warning_strike_number')
            ->when($shopOwnerIds->isNotEmpty(), fn ($query) => $query->whereIn('shop_owner_id', $shopOwnerIds->all()))
            ->groupBy('shop_owner_id')
            ->pluck('warning_count', 'shop_owner_id');

        $shopGroups = $allReports
            ->groupBy('shop_owner_id')
            ->map(function ($reports, $shopOwnerId) use ($warningCountsByShop) {
                /** @var ShopOwner|null $shop */
                $shop = $reports->first()->shop;
                $openReports = $reports->whereIn('status', ['submitted', 'under_review']);
                $warningStrike = (int) ($warningCountsByShop->get((int) $shopOwnerId) ?? 0);
                $warningsUntilSuspension = max(0, self::WARNINGS_BEFORE_SUSPENSION - $warningStrike);

                return [
                    'shop_owner_id' => $shopOwnerId,
                    'business_name' => $shop?->business_name ?? '—',
                    'shop_email' => $shop?->email ?? '—',
                    'shop_status' => $shop?->status ?? '—',
                    'total_reports' => $reports->count(),
                    'open_reports' => $openReports->count(),
                    'open_report_ids' => $openReports->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                    'latest_reason' => $reports->first()
                        ? ShopReport::REASON_LABELS[$reports->first()->reason] ?? $reports->first()->reason
                        : '—',
                    'latest_date' => $reports->first()?->created_at?->toDateTimeString(),
                    'pattern_flags' => ShopReport::detectPatterns((int) $shopOwnerId),
                    'warning_strike' => $warningStrike,
                    'warning_limit' => self::WARNINGS_BEFORE_SUSPENSION,
                    'warnings_until_suspension' => $warningsUntilSuspension,
                    'next_warn_will_suspend' => $warningsUntilSuspension <= 1,
                    'priority' => $openReports->count() >= 5
                        ? 'high'
                        : ($openReports->count() >= 3 ? 'medium' : 'normal'),
                    'reports' => $reports->map(fn (ShopReport $report): array => self::formatReport($report))->values(),
                ];
            })
            ->sortByDesc(fn ($group) => $group['open_reports'])
            ->values();

        $stats = [
            'total_reports' => $allReports->count(),
            'pending_review' => $allReports->whereIn('status', ['submitted', 'under_review'])->count(),
            'high_priority' => $shopGroups->where('priority', 'high')->count(),
            'resolved' => $allReports->whereIn('status', ['dismissed', 'warned', 'suspended'])->count(),
        ];

        return Inertia::render('superAdmin/Shops/ShopReports', [
            'shopGroups' => $shopGroups,
            'stats' => $stats,
        ]);
    }

    public function action(
        ModerateShopReportsRequest $request,
        int $id,
        ShopReportModerationService $moderation,
        PrivilegedFailureResponse $failures,
    ): mixed
    {
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
            'created_at' => $report->created_at->toDateTimeString(),
            'reporter' => $report->reporter ? [
                'id' => $report->reporter->id,
                'name' => trim(($report->reporter->first_name ?? '').' '.($report->reporter->last_name ?? '')),
                'email' => $report->reporter->email,
                'created_at' => $report->reporter->created_at->toDateTimeString(),
                'days_old' => $report->reporter->created_at->diffInDays(now()),
            ] : null,
        ];
    }
}
