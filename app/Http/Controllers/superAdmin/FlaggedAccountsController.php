<?php

declare(strict_types=1);

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\FlaggedAccountDecisionRequest;
use App\Models\ReviewReport;
use App\Models\SuperAdmin;
use App\Services\FlaggedAccountModerationService;
use App\Support\PrivilegedFailureResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class FlaggedAccountsController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in([
                'all',
                ReviewReport::STATUS_PENDING_REVIEW,
                ReviewReport::STATUS_UNDER_INVESTIGATION,
                ReviewReport::STATUS_DISMISSED,
                ReviewReport::STATUS_ACCOUNT_SUSPENDED,
            ])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $baseQuery = ReviewReport::query();
        $query = (clone $baseQuery)
            ->select([
                'id',
                'review_type',
                'review_id',
                'shop_owner_id',
                'user_id',
                'reason',
                'notes',
                'review_snapshot',
                'status',
                'admin_notes',
                'created_at',
            ])
            ->with([
                'customer' => static function ($customerQuery): void {
                    $customerQuery->withTrashed()->select([
                        'id',
                        'name',
                        'first_name',
                        'last_name',
                        'email',
                        'created_at',
                        'deleted_at',
                    ]);
                },
                'shopOwner' => static function ($shopQuery): void {
                    $shopQuery->withTrashed()->select([
                        'id',
                        'business_name',
                        'first_name',
                        'last_name',
                        'email',
                        'deleted_at',
                    ]);
                },
            ]);

        if (($validated['search'] ?? null) !== null && $validated['search'] !== '') {
            $search = (string) $validated['search'];
            $query->where(function (Builder $searchQuery) use ($search): void {
                $this->whereContains($searchQuery, 'reason', $search);
                $this->whereContains($searchQuery, 'notes', $search, 'or');
                $searchQuery->orWhereHas('customer', function (Builder $customerQuery) use ($search): void {
                    $customerQuery->withTrashed()->where(function (Builder $nameQuery) use ($search): void {
                        $this->whereContains($nameQuery, 'name', $search);
                        $this->whereContains($nameQuery, 'first_name', $search, 'or');
                        $this->whereContains($nameQuery, 'last_name', $search, 'or');
                        $this->whereContains($nameQuery, 'email', $search, 'or');
                    });
                });
                $searchQuery->orWhereHas('shopOwner', function (Builder $shopQuery) use ($search): void {
                    $shopQuery->withTrashed()->where(function (Builder $nameQuery) use ($search): void {
                        $this->whereContains($nameQuery, 'business_name', $search);
                        $this->whereContains($nameQuery, 'first_name', $search, 'or');
                        $this->whereContains($nameQuery, 'last_name', $search, 'or');
                        $this->whereContains($nameQuery, 'email', $search, 'or');
                    });
                });
            });
        }

        $status = $validated['status'] ?? 'all';
        if ($status !== 'all') {
            $query->when(
                $status === ReviewReport::STATUS_ACCOUNT_SUSPENDED,
                fn (Builder $builder) => $builder->whereIn('status', [
                    ReviewReport::STATUS_ACCOUNT_SUSPENDED,
                    ReviewReport::STATUS_LEGACY_BANNED,
                ]),
                fn (Builder $builder) => $builder->where('status', $status),
            );
        }

        $reports = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString()
            ->through(fn (ReviewReport $report): array => $this->formatReport($report));

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'pending_review' => (clone $baseQuery)->where('status', ReviewReport::STATUS_PENDING_REVIEW)->count(),
            'under_investigation' => (clone $baseQuery)->where('status', ReviewReport::STATUS_UNDER_INVESTIGATION)->count(),
            'dismissed' => (clone $baseQuery)->where('status', ReviewReport::STATUS_DISMISSED)->count(),
            'account_suspended' => (clone $baseQuery)->whereIn('status', [
                ReviewReport::STATUS_ACCOUNT_SUSPENDED,
                ReviewReport::STATUS_LEGACY_BANNED,
            ])->count(),
        ];

        return Inertia::render('superAdmin/Users/FlaggedAccounts', [
            'flaggedAccounts' => $reports,
            'stats' => $stats,
            'filters' => [
                'search' => $validated['search'] ?? null,
                'status' => $status,
            ],
        ]);
    }

    private function formatReport(ReviewReport $report): array
    {
        $shopName = $report->shopOwner?->business_name
            ?? trim(($report->shopOwner?->first_name ?? '') . ' ' . ($report->shopOwner?->last_name ?? ''))
            ?: 'Unknown Shop';

        return [
            'id' => (string) $report->id,
            'username' => $report->customer?->name
                ?? trim(($report->customer?->first_name ?? '') . ' ' . ($report->customer?->last_name ?? ''))
                ?: 'Unknown Customer',
            'email' => $report->customer?->email ?? '',
            'flaggedReason' => $report->reason_label,
            'flaggedDate' => $report->created_at?->toISOString(),
            'status' => $report->domain_status,
            'reviewType' => $report->review_type,
            'reviewSnapshot' => $report->review_snapshot,
            'reportNotes' => $report->notes,
            'reportedBy' => $shopName,
            'adminNotes' => $report->admin_notes,
        ];
    }

    private function whereContains(Builder $query, string $column, string $value, string $boolean = 'and'): void
    {
        $escaped = addcslashes($value, "\\%_");
        $query->whereRaw(
            "{$column} LIKE ? ESCAPE '\\'",
            ["%{$escaped}%"],
            $boolean,
        );
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
