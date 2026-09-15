<?php

declare(strict_types=1);

namespace App\Http\Controllers\superAdmin;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\IdentityVerification;
use App\Models\Notification;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PrivilegedAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class IdentityVerificationReviewController extends Controller
{
    public function __construct(
        private readonly PrivilegedAudit $audit,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'screening' => ['sometimes', 'nullable', Rule::in(['all', 'passed', 'needs_review'])],
            'status' => ['sometimes', 'nullable', Rule::in(['all', 'pending', 'approved', 'rejected'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $screening = $filters['screening'] ?? 'all';
        $status = $filters['status'] ?? IdentityVerification::REVIEW_PENDING;
        $query = $this->verificationQuery();

        if (($filters['q'] ?? null) !== null && $filters['q'] !== '') {
            $search = (string) $filters['q'];
            $query->whereHas('user', function (Builder $userQuery) use ($search): void {
                $escaped = addcslashes($search, "\\%_");
                $userQuery->where(function (Builder $searchQuery) use ($escaped): void {
                    foreach (['first_name', 'last_name', 'name', 'email', 'phone'] as $column) {
                        $searchQuery->orWhereRaw(
                            "{$column} LIKE ? ESCAPE '\\'",
                            ["%{$escaped}%"],
                        );
                    }
                });
            });
        }

        if ($status !== 'all') {
            $query->where('review_status', $status);
        }

        if ($screening === 'passed') {
            $query->where('screening_status', IdentityVerification::SCREENING_AUTOMATED_CHECK_PASSED);
        } elseif ($screening === 'needs_review') {
            $query->where('screening_status', IdentityVerification::SCREENING_MANUAL_REVIEW_REQUIRED);
        }

        $reviews = $query
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString()
            ->through(fn (IdentityVerification $verification): array => $this->queuePayload($verification));

        $statsQuery = $this->verificationQuery();
        $stats = [
            'total' => (clone $statsQuery)->count(),
            'pending' => (clone $statsQuery)->where('review_status', IdentityVerification::REVIEW_PENDING)->count(),
            'approved' => (clone $statsQuery)->where('review_status', IdentityVerification::REVIEW_APPROVED)->count(),
            'rejected' => (clone $statsQuery)->where('review_status', IdentityVerification::REVIEW_REJECTED)->count(),
            'screening_passed' => (clone $statsQuery)->where('screening_status', IdentityVerification::SCREENING_AUTOMATED_CHECK_PASSED)->count(),
            'needs_review' => (clone $statsQuery)->where('screening_status', IdentityVerification::SCREENING_MANUAL_REVIEW_REQUIRED)->count(),
        ];

        return Inertia::render('superAdmin/IdentityVerificationReviews/Index', [
            'reviews' => $reviews,
            'stats' => $stats,
            'filters' => [
                'q' => $filters['q'] ?? null,
                'screening' => $screening,
                'status' => $status,
            ],
        ]);
    }

    public function inspect(Request $request, User $user, IdentityVerification $verification): JsonResponse
    {
        $actor = $this->actor($request);

        [$inspected, $applied] = DB::transaction(function () use ($request, $actor, $user, $verification): array {
            $lockedUser = User::query()->lockForUpdate()->find($user->getKey());
            $lockedVerification = IdentityVerification::query()
                ->lockForUpdate()
                ->find($verification->getKey());

            $this->assertCustomerVerification($lockedUser, $lockedVerification, $user);
            $this->assertLatestVerification($lockedUser, $lockedVerification);
            $this->assertScreened($lockedVerification);

            if ((string) $lockedVerification->review_status !== IdentityVerification::REVIEW_PENDING) {
                throw new ConflictHttpException('Only pending identity verifications can be inspected.');
            }

            if ($lockedVerification->inspected_at !== null && $lockedVerification->inspected_by !== null) {
                return [$lockedVerification->fresh(), false];
            }

            $lockedVerification->forceFill([
                'inspected_by' => $actor->getKey(),
                'inspected_at' => now(),
            ])->save();

            $this->audit->identityVerificationInspected(
                request: $request,
                actor: $actor,
                verification: $lockedVerification,
                user: $lockedUser,
            );

            return [$lockedVerification->fresh(), true];
        });

        return response()->json([
            'message' => $applied
                ? 'Identity verification marked as inspected.'
                : 'Identity verification was already marked as inspected.',
            'identity_verification' => $this->safePayload($inspected),
        ]);
    }

    public function approve(Request $request, User $user, IdentityVerification $verification): JsonResponse
    {
        return $this->review($request, $user, $verification, IdentityVerification::REVIEW_APPROVED);
    }

    public function reject(Request $request, User $user, IdentityVerification $verification): JsonResponse
    {
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', Rule::in(IdentityVerification::REJECTION_REASONS)],
            'rejection_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validated['rejection_reason'] === 'other' && trim((string) ($validated['rejection_notes'] ?? '')) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'rejection_notes' => 'Please provide a note when selecting Other.',
            ]);
        }

        return $this->review(
            $request,
            $user,
            $verification,
            IdentityVerification::REVIEW_REJECTED,
            $validated['rejection_reason'],
            trim((string) ($validated['rejection_notes'] ?? '')) ?: null,
        );
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'verification_ids' => ['required', 'array', 'min:1', 'max:50'],
            'verification_ids.*' => ['integer', 'min:1'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $validated['verification_ids'])));

        [$approved, $userIds] = DB::transaction(function () use ($request, $actor, $ids): array {
            $records = IdentityVerification::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($records->count() !== count($ids)) {
                throw new ConflictHttpException('One or more identity verifications are no longer available.');
            }

            $approved = [];
            $userIds = [];
            foreach ($records as $verification) {
                $lockedUser = User::query()->lockForUpdate()->find($verification->user_id);

                if (! $lockedUser instanceof User) {
                    abort(404);
                }

                $this->assertCustomerVerification($lockedUser, $verification, $lockedUser);
                $this->assertLatestVerification($lockedUser, $verification);
                $this->assertScreened($verification);

                if ((string) $verification->review_status !== IdentityVerification::REVIEW_PENDING
                    || $verification->inspected_at === null
                    || $verification->inspected_by === null) {
                    throw new ConflictHttpException('Bulk approve is limited to pending verifications already inspected by a reviewer.');
                }

                $verification->forceFill([
                    'review_status' => IdentityVerification::REVIEW_APPROVED,
                    'reviewed_by' => $actor->getKey(),
                    'reviewed_at' => now(),
                    'rejection_reason' => null,
                    'rejection_notes' => null,
                ])->save();

                $lockedUser->forceFill([
                    'identity_verification_status' => User::IDENTITY_APPROVED,
                ])->save();

                $this->audit->identityVerificationReviewed(
                    request: $request,
                    actor: $actor,
                    verification: $verification,
                    user: $lockedUser,
                    decision: 'approved',
                );

                $approved[] = $verification->fresh();
                $userIds[] = (int) $lockedUser->getKey();
            }

            return [$approved, $userIds];
        });

        foreach ($userIds as $userId) {
            $this->notifyCustomer(
                $userId,
                NotificationType::IDENTITY_VERIFICATION_APPROVED,
                'Identity verification approved',
                'Your identity verification has been approved. Cart, checkout, repair, and payment features are now available.',
                null,
                false,
            );
        }

        return response()->json([
            'message' => count($approved).' identity verification(s) approved.',
            'approved_count' => count($approved),
            'identity_verifications' => array_map(fn (IdentityVerification $verification): array => $this->safePayload($verification), $approved),
        ]);
    }

    private function review(
        Request $request,
        User $user,
        IdentityVerification $verification,
        string $reviewStatus,
        ?string $rejectionReason = null,
        ?string $rejectionNotes = null,
    ): JsonResponse {
        $actor = $this->actor($request);
        $event = $reviewStatus === IdentityVerification::REVIEW_APPROVED ? 'approved' : 'rejected';

        [$reviewedVerification, $applied, $reviewedUser] = DB::transaction(function () use (
            $request,
            $actor,
            $user,
            $verification,
            $reviewStatus,
            $event,
            $rejectionReason,
            $rejectionNotes,
        ): array {
            $lockedUser = User::query()->lockForUpdate()->find($user->getKey());
            $lockedVerification = IdentityVerification::query()
                ->lockForUpdate()
                ->find($verification->getKey());

            $this->assertCustomerVerification($lockedUser, $lockedVerification, $user);
            $this->assertLatestVerification($lockedUser, $lockedVerification);
            $this->assertScreened($lockedVerification);

            if ((string) $lockedVerification->review_status === $reviewStatus) {
                return [$lockedVerification->fresh(), false, $lockedUser];
            }

            if (! in_array((string) $lockedVerification->review_status, [
                IdentityVerification::REVIEW_NOT_REQUIRED,
                IdentityVerification::REVIEW_PENDING,
            ], true)) {
                throw new ConflictHttpException('This identity verification already has a different review decision.');
            }

            $lockedVerification->forceFill([
                'review_status' => $reviewStatus,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => $reviewStatus === IdentityVerification::REVIEW_REJECTED ? $rejectionReason : null,
                'rejection_notes' => $reviewStatus === IdentityVerification::REVIEW_REJECTED ? $rejectionNotes : null,
            ])->save();

            $lockedUser->forceFill([
                'identity_verification_status' => $reviewStatus === IdentityVerification::REVIEW_APPROVED
                    ? User::IDENTITY_APPROVED
                    : User::IDENTITY_REJECTED,
            ])->save();

            $this->audit->identityVerificationReviewed(
                request: $request,
                actor: $actor,
                verification: $lockedVerification,
                user: $lockedUser,
                decision: $event,
                rejectionReason: $rejectionReason,
            );

            return [$lockedVerification->fresh(), true, $lockedUser];
        });

        if ($applied) {
            if ($reviewStatus === IdentityVerification::REVIEW_APPROVED) {
                $this->notifyCustomer(
                    (int) $reviewedUser->getKey(),
                    NotificationType::IDENTITY_VERIFICATION_APPROVED,
                    'Identity verification approved',
                    'Your identity verification has been approved. Cart, checkout, repair, and payment features are now available.',
                    null,
                    false,
                );
            } else {
                $reason = $this->rejectionLabel($rejectionReason);
                $this->notifyCustomer(
                    (int) $reviewedUser->getKey(),
                    NotificationType::IDENTITY_VERIFICATION_REJECTED,
                    'Identity verification needs resubmission',
                    'We could not approve your submitted ID ('.$reason.'). Review the reason in your profile and submit a new valid ID.',
                    $rejectionReason,
                    true,
                );
            }
        }

        return response()->json([
            'message' => $applied
                ? 'Identity verification review recorded.'
                : 'Identity verification review was already recorded.',
            'identity_verification' => $this->safePayload($reviewedVerification),
        ]);
    }

    private function actor(Request $request): SuperAdmin
    {
        $actor = $request->user('super_admin');
        abort_unless($actor instanceof SuperAdmin, 401);

        return $actor;
    }

    private function assertCustomerVerification(
        ?User $lockedUser,
        ?IdentityVerification $lockedVerification,
        User $routeUser,
    ): void {
        if (
            ! $lockedUser instanceof User
            || ! $lockedVerification instanceof IdentityVerification
            || ! $lockedUser->isCustomerAccount()
            || (int) $lockedUser->getKey() !== (int) $routeUser->getKey()
            || (int) $lockedVerification->user_id !== (int) $lockedUser->getKey()
        ) {
            abort(404);
        }
    }

    private function assertLatestVerification(User $user, IdentityVerification $verification): void
    {
        $latestId = IdentityVerification::query()
            ->where('user_id', $user->getKey())
            ->max('id');

        if ((int) $latestId !== (int) $verification->getKey()) {
            throw new ConflictHttpException('A newer identity submission is already awaiting review.');
        }
    }

    private function assertScreened(IdentityVerification $verification): void
    {
        if (! in_array((string) $verification->screening_status, [
            IdentityVerification::SCREENING_AUTOMATED_CHECK_PASSED,
            IdentityVerification::SCREENING_MANUAL_REVIEW_REQUIRED,
        ], true)) {
            throw new ConflictHttpException('Only screened documents can be reviewed.');
        }
    }

    private function verificationQuery(): Builder
    {
        return IdentityVerification::query()
            ->whereHas('user', fn (Builder $query) => $query->whereNull('shop_owner_id'))
            ->with([
                'user:id,first_name,last_name,name,email,phone',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /** @return array<string, mixed> */
    private function queuePayload(IdentityVerification $verification): array
    {
        return array_merge($this->safePayload($verification), [
            'customer' => [
                'id' => (int) $verification->user->getKey(),
                'name' => $verification->user->name ?: trim($verification->user->first_name.' '.$verification->user->last_name),
                'email' => $verification->user->email,
            ],
            'submitted_at' => $verification->created_at?->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    private function safePayload(IdentityVerification $verification): array
    {
        $userId = (int) $verification->user_id;

        return [
            'id' => (int) $verification->getKey(),
            'user_id' => $userId,
            'document_type' => $verification->document_type,
            'screening_status' => $verification->screening_status,
            'screening_label' => $this->screeningLabel((string) $verification->screening_status),
            'review_status' => $verification->review_status,
            'failure_reason' => $verification->failure_reason,
            'rejection_reason' => $verification->rejection_reason,
            'rejection_notes' => $verification->rejection_notes,
            'inspected_at' => $verification->inspected_at?->toIso8601String(),
            'reviewed_at' => $verification->reviewed_at?->toIso8601String(),
            'front_url' => route('admin.users.identity-verifications.front', [
                'user' => $userId,
                'verification' => $verification->getKey(),
            ]),
            'back_url' => $verification->back_file_path
                ? route('admin.users.identity-verifications.back', [
                    'user' => $userId,
                    'verification' => $verification->getKey(),
                ])
                : null,
        ];
    }

    private function screeningLabel(string $status): string
    {
        return match ($status) {
            IdentityVerification::SCREENING_AUTOMATED_CHECK_PASSED => 'Passed',
            IdentityVerification::SCREENING_MANUAL_REVIEW_REQUIRED => 'Needs review',
            IdentityVerification::SCREENING_REJECTED => 'Rejected by screening',
            default => ucwords(str_replace('_', ' ', $status)),
        };
    }

    private function rejectionLabel(?string $reason): string
    {
        return match ($reason) {
            'id_unreadable' => 'the ID was unreadable',
            'wrong_document' => 'the wrong document was submitted',
            'incomplete_details' => 'the ID details were incomplete',
            'suspected_altered' => 'the document needs additional authenticity review',
            'front_back_mismatch' => 'the front and back images did not match',
            default => 'the submitted document needs another review',
        };
    }

    private function notifyCustomer(
        int $userId,
        NotificationType $type,
        string $title,
        string $message,
        ?string $rejectionReason,
        bool $requiresAction,
    ): void {
        $this->notifications->sendToUser(
            userId: $userId,
            type: $type,
            title: $title,
            message: $message,
            data: $rejectionReason ? ['rejection_reason' => $rejectionReason] : ['identity_status' => User::IDENTITY_APPROVED],
            actionUrl: '/customer-profile',
            priority: 'high',
            groupKey: 'identity-verification:'.$userId.':'.$type->value,
            requiresAction: $requiresAction,
        );
    }
}
