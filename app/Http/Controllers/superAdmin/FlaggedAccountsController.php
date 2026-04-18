<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Mail\CustomerReviewWarningMail;
use App\Models\AuditLog;
use App\Models\ReviewReport;
use App\Services\SuspensionAppealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class FlaggedAccountsController extends Controller
{
    private const WARNINGS_BEFORE_SUSPENSION = 3;

    public function index(): Response
    {
        $reports = ReviewReport::with([
                'customer:id,name,email',
                'shopOwner:id,business_name,first_name,last_name',
            ])
            ->orderByDesc('created_at')
            ->get();

        $customerIds = $reports
            ->pluck('user_id')
            ->filter(fn ($id) => !is_null($id))
            ->unique()
            ->values();

        $warningCountsByCustomer = $customerIds->isEmpty()
            ? collect()
            : AuditLog::query()
                ->where('target_type', 'User')
                ->where('action', 'review_report_warn')
                ->whereIn('target_id', $customerIds)
                ->selectRaw('target_id, COUNT(*) as warning_count')
                ->groupBy('target_id')
                ->pluck('warning_count', 'target_id');

        $reports = $reports
            ->map(function ($report) use ($warningCountsByCustomer) {
                $shopName = $report->shopOwner?->business_name
                    ?? trim(($report->shopOwner?->first_name ?? '') . ' ' . ($report->shopOwner?->last_name ?? ''))
                    ?: 'Unknown Shop';

                $warningCount = (int) ($warningCountsByCustomer[$report->user_id] ?? 0);

                return [
                    'id'             => (string) $report->id,
                    'username'       => $report->customer?->name ?? 'Unknown Customer',
                    'email'          => $report->customer?->email ?? '',
                    'flaggedReason'  => ReviewReport::$reasonLabels[$report->reason] ?? ucfirst(str_replace('_', ' ', $report->reason)),
                    'flaggedDate'    => $report->created_at->toISOString(),
                    'status'         => $report->status,
                    // Extended review detail fields
                    'reviewType'     => $report->review_type,
                    'reviewSnapshot' => $report->review_snapshot,
                    'reportNotes'    => $report->notes,
                    'reportedBy'     => $shopName,
                    'adminNotes'     => $report->admin_notes,
                    'warningCount'   => $warningCount,
                    'warningLimit'   => self::WARNINGS_BEFORE_SUSPENSION,
                ];
            });

        return Inertia::render('superAdmin/Users/FlaggedAccounts', [
            'flaggedAccounts' => $reports,
        ]);
    }

    /** Mark a report as under investigation */
    public function markReviewed(int $id): JsonResponse
    {
        $report = ReviewReport::findOrFail($id);
        $report->update(['status' => 'under_investigation']);
        return response()->json(['status' => $report->status]);
    }

    /** Dismiss the report — keep the review live */
    public function dismiss(Request $request, int $id): JsonResponse
    {
        $report = ReviewReport::findOrFail($id);
        $report->update([
            'status'      => 'dismissed',
            'admin_notes' => $request->input('admin_notes'),
            'resolved_at' => now(),
        ]);
        return response()->json(['status' => $report->status]);
    }

    /** Apply warning/suspension policy for reported customer accounts. */
    public function ban(Request $request, int $id, SuspensionAppealService $suspensionAppealService): JsonResponse
    {
        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
            'enforcement_mode' => ['nullable', 'in:policy,immediate_suspend'],
        ]);

        $report = ReviewReport::with('customer')->findOrFail($id);
        $superAdminId = Auth::guard('super_admin')->id();
        $enforcementMode = $validated['enforcement_mode'] ?? 'policy';

        if (!$report->customer) {
            $report->update([
                'status'      => 'dismissed',
                'admin_notes' => $validated['admin_notes'] ?? null,
                'resolved_at' => now(),
            ]);

            return response()->json([
                'status' => $report->status,
                'applied_action' => 'warn',
                'enforcement_mode' => $enforcementMode,
                'warning_strike' => 0,
                'warning_limit' => self::WARNINGS_BEFORE_SUSPENSION,
                'message' => 'Customer account is no longer available. Report marked as resolved.',
            ]);
        }

        $priorWarningActions = AuditLog::query()
            ->where('target_type', 'User')
            ->where('target_id', $report->customer->id)
            ->where('action', 'review_report_warn')
            ->count();

        if ($enforcementMode === 'immediate_suspend') {
            $currentWarningStrike = $priorWarningActions;
            $effectiveAction = 'suspend';
        } else {
            $currentWarningStrike = $priorWarningActions + 1;
            $effectiveAction = $currentWarningStrike >= self::WARNINGS_BEFORE_SUSPENSION
                ? 'suspend'
                : 'warn';
        }

        $reason = $validated['admin_notes']
            ?: (
                $effectiveAction === 'suspend'
                    ? (
                        $enforcementMode === 'immediate_suspend'
                            ? 'Suspended immediately by super admin enforcement action.'
                            : 'Suspended after reaching 3 warnings from reported customer review behavior.'
                    )
                    : 'Warning issued after super admin review of reported customer behavior.'
            );

        if ($effectiveAction === 'warn') {
            $report->update([
                'status'      => 'dismissed',
                'admin_notes' => $validated['admin_notes'] ?? null,
                'resolved_at' => now(),
            ]);

            AuditLog::create([
                'shop_owner_id' => $report->shop_owner_id,
                'actor_user_id' => null,
                'action' => 'review_report_warn',
                'target_type' => 'User',
                'target_id' => $report->customer->id,
                'data' => [
                    'report_id' => $report->id,
                    'requested_action' => 'ban',
                    'applied_action' => 'warn',
                    'enforcement_mode' => $enforcementMode,
                    'admin_id' => $superAdminId,
                    'notes' => $validated['admin_notes'] ?? null,
                    'warning_strike' => $currentWarningStrike,
                    'warning_limit' => self::WARNINGS_BEFORE_SUSPENSION,
                ],
            ]);

            $email = trim((string) ($report->customer->email ?? ''));
            if ($email !== '') {
                $customerName = trim((string) ($report->customer->name ?? 'Customer'));
                $reasonLabel = ReviewReport::$reasonLabels[$report->reason]
                    ?? ucfirst(str_replace('_', ' ', (string) $report->reason));

                try {
                    Mail::to($email)->send(new CustomerReviewWarningMail(
                        customerName: $customerName !== '' ? $customerName : 'Customer',
                        warningStrike: $currentWarningStrike,
                        warningLimit: self::WARNINGS_BEFORE_SUSPENSION,
                        reasonLabel: $reasonLabel,
                        adminNotes: $validated['admin_notes'] ?? null,
                        reviewedAtLabel: now()->format('M d, Y h:i A')
                    ));
                } catch (\Throwable $e) {
                    Log::error('Failed to send customer review warning email', [
                        'user_id' => $report->customer->id,
                        'email' => $email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'status' => $report->status,
                'applied_action' => 'warn',
                'enforcement_mode' => $enforcementMode,
                'warning_strike' => $currentWarningStrike,
                'warning_limit' => self::WARNINGS_BEFORE_SUSPENSION,
                'message' => "Warning {$currentWarningStrike}/" . self::WARNINGS_BEFORE_SUSPENSION . ' issued to customer.',
            ]);
        }

        $report->update([
            'status'      => 'banned',
            'admin_notes' => $validated['admin_notes'] ?? null,
            'resolved_at' => now(),
        ]);

        $report->customer->update(['status' => 'suspended']);
        $suspensionAppealService->createAndSendForCustomer(
            $report->customer,
            $reason,
            $superAdminId
        );

        AuditLog::create([
            'shop_owner_id' => $report->shop_owner_id,
            'actor_user_id' => null,
            'action' => 'review_report_suspend',
            'target_type' => 'User',
            'target_id' => $report->customer->id,
            'data' => [
                'report_id' => $report->id,
                'requested_action' => 'ban',
                'applied_action' => 'suspend',
                'enforcement_mode' => $enforcementMode,
                'admin_id' => $superAdminId,
                'notes' => $validated['admin_notes'] ?? null,
                'warning_strike' => $currentWarningStrike,
                'warning_limit' => self::WARNINGS_BEFORE_SUSPENSION,
            ],
        ]);

        $message = $enforcementMode === 'immediate_suspend'
            ? 'Customer was suspended immediately by super admin override.'
            : 'Customer reached 3 warnings and was suspended.';

        return response()->json([
            'status' => $report->status,
            'applied_action' => 'suspend',
            'enforcement_mode' => $enforcementMode,
            'warning_strike' => $currentWarningStrike,
            'warning_limit' => self::WARNINGS_BEFORE_SUSPENSION,
            'message' => $message,
        ]);
    }
}

