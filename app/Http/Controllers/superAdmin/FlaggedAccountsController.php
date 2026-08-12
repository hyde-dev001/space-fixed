<?php

declare(strict_types=1);

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\FlaggedAccountDecisionRequest;
use App\Models\ReviewReport;
use App\Models\SuperAdmin;
use App\Services\FlaggedAccountModerationService;
use App\Support\PrivilegedFailureResponse;
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
        PrivilegedFailureResponse $failures,
    ): JsonResponse {
        return $this->decide($request, $id, 'mark_reviewed', $moderation, $failures);
    }

    public function dismiss(
        FlaggedAccountDecisionRequest $request,
        int $id,
        FlaggedAccountModerationService $moderation,
        PrivilegedFailureResponse $failures,
    ): JsonResponse {
        return $this->decide($request, $id, 'dismiss', $moderation, $failures);
    }

    public function ban(
        FlaggedAccountDecisionRequest $request,
        int $id,
        FlaggedAccountModerationService $moderation,
        PrivilegedFailureResponse $failures,
    ): JsonResponse {
        return $this->decide($request, $id, 'account_suspended', $moderation, $failures);
    }

    private function decide(
        FlaggedAccountDecisionRequest $request,
        int $id,
        string $action,
        FlaggedAccountModerationService $moderation,
        PrivilegedFailureResponse $failures,
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
            if ($status === 409) {
                return $failures->conflict(
                    request: $request,
                    operation: 'flagged_account',
                    message: 'The flagged-account decision conflicts with current state.',
                    code: 'flagged_account_conflict',
                    forceJson: true,
                );
            }

            if ($status === 422) {
                return $failures->validation(
                    request: $request,
                    message: 'The flagged-account decision input is invalid.',
                    code: 'flagged_account_validation',
                    forceJson: true,
                );
            }

            return $failures->unexpected(
                request: $request,
                operation: 'flagged_account',
                exception: $exception,
                message: 'The flagged-account decision could not be completed.',
                code: 'flagged_account_error',
                forceJson: true,
            );
        } catch (Throwable $exception) {
            return $failures->unexpected(
                request: $request,
                operation: 'flagged_account',
                exception: $exception,
                message: 'The flagged-account decision could not be completed.',
                code: 'flagged_account_error',
                forceJson: true,
            );
        }
    }
}
