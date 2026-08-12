<?php

declare(strict_types=1);

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\FlaggedAccountDecisionRequest;
use App\Models\ReviewReport;
use App\Models\SuperAdmin;
use App\Services\FlaggedAccountModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class FlaggedAccountsController extends Controller
{
    public function index(): Response
    {
        $reports = ReviewReport::with([
                'customer' => fn ($query) => $query->withTrashed(),
                'shopOwner' => fn ($query) => $query->withTrashed(),
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ReviewReport $report): array {
                $shopName = $report->shopOwner?->business_name
                    ?? trim(($report->shopOwner?->first_name ?? '') . ' ' . ($report->shopOwner?->last_name ?? ''))
                    ?: 'Unknown Shop';

                return [
                    'id' => (string) $report->id,
                    'username' => $report->customer?->name ?? 'Unknown Customer',
                    'email' => $report->customer?->email ?? '',
                    'flaggedReason' => $report->reason_label,
                    'flaggedDate' => $report->created_at->toISOString(),
                    'status' => $report->domain_status,
                    'reviewType' => $report->review_type,
                    'reviewSnapshot' => $report->review_snapshot,
                    'reportNotes' => $report->notes,
                    'reportedBy' => $shopName,
                    'adminNotes' => $report->admin_notes,
                ];
            });

        return Inertia::render('superAdmin/Users/FlaggedAccounts', [
            'flaggedAccounts' => $reports,
        ]);
    }

    public function markReviewed(
        FlaggedAccountDecisionRequest $request,
        int $id,
        FlaggedAccountModerationService $moderation,
    ): JsonResponse {
        return $this->decide($request, $id, 'mark_reviewed', $moderation);
    }

    public function dismiss(
        FlaggedAccountDecisionRequest $request,
        int $id,
        FlaggedAccountModerationService $moderation,
    ): JsonResponse {
        return $this->decide($request, $id, 'dismiss', $moderation);
    }

    public function ban(
        FlaggedAccountDecisionRequest $request,
        int $id,
        FlaggedAccountModerationService $moderation,
    ): JsonResponse {
        return $this->decide($request, $id, 'account_suspended', $moderation);
    }

    private function decide(
        FlaggedAccountDecisionRequest $request,
        int $id,
        string $action,
        FlaggedAccountModerationService $moderation,
    ): JsonResponse {
        $actor = Auth::guard('super_admin')->user();
        abort_unless($actor instanceof SuperAdmin, 403);

        try {
            $result = $moderation->moderate(
                reportId: $id,
                action: $action,
                notes: $request->validated('admin_notes'),
                actor: $actor,
                request: $request,
            );

            $report = $result['report'];
            $status = $report->domain_status;

            return response()->json([
                'success' => true,
                'changed' => $result['changed'],
                'status' => $status,
                'legacy_status' => (string) $report->status,
                'suspension_id' => $result['suspension_id'],
            ]);
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();
            $message = in_array($status, [409, 422], true)
                ? $exception->getMessage()
                : 'The flagged-account decision could not be completed.';

            return response()->json([
                'success' => false,
                'message' => $message,
                'code' => $status === 409 ? 'flagged_account_conflict' : 'flagged_account_error',
            ], $status);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The flagged-account decision could not be completed.',
                'code' => 'flagged_account_error',
            ], 500);
        }
    }
}
