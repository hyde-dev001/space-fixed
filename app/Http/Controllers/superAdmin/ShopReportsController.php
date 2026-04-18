<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Mail\ShopReportWarningMail;
use App\Models\ShopReport;
use App\Models\ShopOwner;
use App\Models\AuditLog;
use App\Services\SuspensionAppealService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class ShopReportsController extends Controller
{
    private const WARNINGS_BEFORE_SUSPENSION = 3;

    /**
     * Show the shop reports dashboard.
     *
     * Groups reports by shop, attaches report counts and pattern flags.
     * Sorted so high-priority shops (5+ open reports) appear first.
     */
    public function index(): Response
    {
        // ── 1. Load all reports with reporter + shop ───────────────────────
        $allReports = ShopReport::with(['reporter', 'shop'])
            ->orderBy('created_at', 'desc')
            ->get();

        $shopOwnerIds = $allReports
            ->pluck('shop_owner_id')
            ->filter()
            ->unique()
            ->values();

        $warningCountsByShop = AuditLog::query()
            ->selectRaw('target_id, COUNT(*) AS warning_count')
            ->where('target_type', 'ShopOwner')
            ->where('action', 'shop_report_warn')
            ->when($shopOwnerIds->isNotEmpty(), fn ($query) => $query->whereIn('target_id', $shopOwnerIds->all()))
            ->groupBy('target_id')
            ->pluck('warning_count', 'target_id');

        // ── 2. Group by shop and build summary rows ────────────────────────
        $shopGroups = $allReports
            ->groupBy('shop_owner_id')
            ->map(function ($reports, $shopOwnerId) use ($warningCountsByShop) {
                /** @var \App\Models\ShopOwner|null $shop */
                $shop = $reports->first()->shop;

                $openReports = $reports->whereIn('status', ['submitted', 'under_review']);

                $warningStrike = (int) ($warningCountsByShop->get((int) $shopOwnerId) ?? 0);
                $warningsUntilSuspension = max(0, self::WARNINGS_BEFORE_SUSPENSION - $warningStrike);

                $patternFlags = ShopReport::detectPatterns($shopOwnerId);

                return [
                    'shop_owner_id'   => $shopOwnerId,
                    'business_name'   => $shop?->business_name ?? '—',
                    'shop_email'      => $shop?->email ?? '—',
                    'shop_status'     => $shop?->status ?? '—',
                    'total_reports'   => $reports->count(),
                    'open_reports'    => $openReports->count(),
                    'latest_reason'   => $reports->first() ? ShopReport::REASON_LABELS[$reports->first()->reason] ?? $reports->first()->reason : '—',
                    'latest_date'     => $reports->first()?->created_at?->toDateTimeString(),
                    'pattern_flags'   => $patternFlags,
                    'warning_strike'  => $warningStrike,
                    'warning_limit'   => self::WARNINGS_BEFORE_SUSPENSION,
                    'warnings_until_suspension' => $warningsUntilSuspension,
                    'next_warn_will_suspend' => $warningsUntilSuspension <= 1,
                    'priority'        => $openReports->count() >= 5 ? 'high'
                        : ($openReports->count() >= 3 ? 'medium' : 'normal'),
                    'reports'         => $reports->map(fn($r) => self::formatReport($r))->values(),
                ];
            })
            ->sortByDesc(fn($g) => $g['open_reports'])
            ->values();

        // ── 3. Stats cards ─────────────────────────────────────────────────
        $stats = [
            'total_reports'    => $allReports->count(),
            'pending_review'   => $allReports->whereIn('status', ['submitted', 'under_review'])->count(),
            'high_priority'    => $shopGroups->where('priority', 'high')->count(),
            'resolved'         => $allReports->whereIn('status', ['dismissed', 'warned', 'suspended'])->count(),
        ];

        return Inertia::render('superAdmin/Shops/ShopReports', [
            'shopGroups' => $shopGroups,
            'stats'      => $stats,
        ]);
    }

    /**
     * Take action on a report (dismiss / warn / suspend).
     *
     * @param  int  $id  The shop_owner_id (we act on ALL open reports for this shop)
     */
    public function action(Request $request, int $id, SuspensionAppealService $suspensionAppealService): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'action'      => ['required', 'in:dismiss,warn,suspend'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $admin = Auth::guard('super_admin')->user();

        // Fetch all open reports for this shop
        $openReports = ShopReport::where('shop_owner_id', $id)
            ->whereIn('status', ['submitted', 'under_review'])
            ->get();

        if ($openReports->isEmpty()) {
            return back()->with('error', 'No open reports found for this shop.');
        }

        $priorWarningActions = AuditLog::query()
            ->where('target_type', 'ShopOwner')
            ->where('target_id', $id)
            ->where('action', 'shop_report_warn')
            ->count();

        $effectiveAction = $validated['action'];
        $currentWarningStrike = null;

        if ($validated['action'] === 'warn') {
            $currentWarningStrike = $priorWarningActions + 1;

            if ($currentWarningStrike >= self::WARNINGS_BEFORE_SUSPENSION) {
                $effectiveAction = 'suspend';
            }
        }

        $newStatus = match ($effectiveAction) {
            'dismiss'  => 'dismissed',
            'warn'     => 'warned',
            'suspend'  => 'suspended',
        };

        // ── Update all open reports ────────────────────────────────────────
        ShopReport::where('shop_owner_id', $id)
            ->whereIn('status', ['submitted', 'under_review'])
            ->update([
                'status'      => $newStatus,
                'admin_notes' => $validated['admin_notes'] ?? null,
                'reviewed_by' => $admin?->id,
                'reviewed_at' => now(),
            ]);

        // ── If warning: notify the shop owner by email ───────────────────
        if ($effectiveAction === 'warn') {
            $shopOwner = ShopOwner::query()->find($id);
            $email = trim((string) ($shopOwner?->email ?? ''));

            if ($shopOwner && $email !== '') {
                $accountName = trim((string) (
                    ($shopOwner->business_name ?? '')
                    ?: (($shopOwner->first_name ?? '') . ' ' . ($shopOwner->last_name ?? ''))
                ));

                $firstReason = (string) ($openReports->first()?->reason ?? '');
                $primaryReason = ShopReport::REASON_LABELS[$firstReason] ?? ($firstReason !== '' ? $firstReason : 'Policy violation');

                try {
                    Mail::to($email)->send(new ShopReportWarningMail(
                        accountName: $accountName !== '' ? $accountName : 'Shop Owner',
                        reportCount: $openReports->count(),
                        primaryReason: $primaryReason,
                        adminNotes: $validated['admin_notes'] ?? null,
                        reviewedAtLabel: now()->format('M d, Y h:i A')
                    ));

                    Log::info('Shop report warning email sent', [
                        'shop_owner_id' => $shopOwner->id,
                        'email' => $email,
                        'report_count' => $openReports->count(),
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Failed to send shop report warning email', [
                        'shop_owner_id' => $shopOwner->id,
                        'email' => $email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // ── If suspension: update the shop ─────────────────────────────────
        if ($effectiveAction === 'suspend') {
            $reason = $validated['admin_notes']
                ?? (
                    $validated['action'] === 'warn'
                        ? 'Suspended automatically after reaching 3 warnings from shop report reviews.'
                        : 'Suspended due to multiple verified reports from customers.'
                );

            $shopOwner = ShopOwner::query()->find($id);

            if ($shopOwner) {
                $shopOwner->update([
                    'status'            => 'suspended',
                    'suspension_reason' => $reason,
                ]);

                $suspensionAppealService->createAndSendForShopOwner(
                    $shopOwner,
                    $reason,
                    $admin?->id
                );
            } else {
                ShopOwner::where('id', $id)->update([
                    'status'            => 'suspended',
                    'suspension_reason' => $reason,
                ]);
            }
        }

        // ── Audit log ─────────────────────────────────────────────────────
        AuditLog::create([
            'shop_owner_id' => $id,
            'actor_user_id' => null,
            'action'        => 'shop_report_' . $effectiveAction,
            'target_type'   => 'ShopOwner',
            'target_id'     => $id,
            'data'          => [
                'requested_action' => $validated['action'],
                'applied_action'   => $effectiveAction,
                'report_count'     => $openReports->count(),
                'admin_id'         => $admin?->id,
                'admin_email'      => $admin?->email,
                'notes'            => $validated['admin_notes'] ?? null,
                'warning_strike'   => $currentWarningStrike,
                'warning_limit'    => self::WARNINGS_BEFORE_SUSPENSION,
            ],
        ]);

        $message = $effectiveAction === 'suspend' && $validated['action'] === 'warn'
            ? 'Shop reached 3 warnings and has been suspended automatically.'
            : match ($effectiveAction) {
                'dismiss' => 'Reports dismissed successfully.',
                'warn'    => 'Shop has been warned and reports updated.',
                'suspend' => 'Shop has been suspended and reports resolved.',
            };

        return back()->with('success', $message);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private static function formatReport(ShopReport $r): array
    {
        return [
            'id'               => $r->id,
            'reason'           => $r->reason,
            'reason_label'     => ShopReport::REASON_LABELS[$r->reason] ?? $r->reason,
            'description'      => $r->description,
            'status'           => $r->status,
            'status_label'     => ShopReport::STATUS_LABELS[$r->status] ?? $r->status,
            'transaction_type' => $r->transaction_type,
            'transaction_id'   => $r->transaction_id,
            'admin_notes'      => $r->admin_notes,
            'reviewed_at'      => $r->reviewed_at?->toDateTimeString(),
            'ip_address'       => $r->ip_address,
            'created_at'       => $r->created_at->toDateTimeString(),
            'reporter'         => $r->reporter ? [
                'id'         => $r->reporter->id,
                'name'       => trim(($r->reporter->first_name ?? '') . ' ' . ($r->reporter->last_name ?? '')),
                'email'      => $r->reporter->email,
                'created_at' => $r->reporter->created_at->toDateTimeString(),
                'days_old'   => $r->reporter->created_at->diffInDays(now()),
            ] : null,
        ];
    }
}
