<?php

declare(strict_types=1);

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\DecideSuspensionAppealRequest;
use App\Models\AccountSuspension;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use App\Services\SuspensionAppealService;
use App\Support\PrivilegedFailureResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class SuspensionAppealsController extends Controller
{
    public function index(Request $request, SuspensionAppealService $appealService): Response
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in([
                'all',
                'eligible',
                'submitted',
                'approved',
                'rejected',
                'expired',
                'superseded',
                'stale',
            ])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $baseQuery = SuspensionAppeal::query();
        $query = clone $baseQuery;

        if (($validated['search'] ?? null) !== null && $validated['search'] !== '') {
            $search = (string) $validated['search'];
            $query->where(function (Builder $searchQuery) use ($search): void {
                $this->whereContains($searchQuery, 'account_name', $search);
                $this->whereContains($searchQuery, 'recipient_email', $search, 'or');
                $this->whereContains($searchQuery, 'account_type', $search, 'or');
                $this->whereContains($searchQuery, 'suspension_reason', $search, 'or');
            });
        }

        $status = $validated['status'] ?? 'all';
        if ($status !== 'all') {
            $this->applyStatusFilter($query, $status);
        }

        $appeals = $query
            ->select([
                'id',
                'account_type',
                'account_id',
                'suspension_id',
                'account_name',
                'recipient_email',
                'suspension_reason',
                'status',
                'appeal_message',
                'reviewer_notes',
                'submitted_at',
                'reviewed_at',
                'expires_at',
                'created_at',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString();

        [$accounts, $currentSuspensions] = $this->presentationContext($appeals->getCollection());
        $appeals->setCollection($appeals->getCollection()->map(
            fn (SuspensionAppeal $appeal): array => $this->formatAppeal(
                $appeal,
                $appealService,
                $accounts->get($this->accountKey($appeal)),
                $currentSuspensions->get((int) ($appeal->suspension_id ?? 0)),
            ),
        ));

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'eligible' => $this->countForStatus(clone $baseQuery, 'eligible'),
            'submitted' => $this->countForStatus(clone $baseQuery, 'submitted'),
            'approved' => $this->countForStatus(clone $baseQuery, 'approved'),
            'rejected' => $this->countForStatus(clone $baseQuery, 'rejected'),
            'expired' => $this->countForStatus(clone $baseQuery, 'expired'),
            'superseded' => $this->countForStatus(clone $baseQuery, 'superseded'),
            'stale' => $this->countForStatus(clone $baseQuery, 'stale'),
        ];

        return Inertia::render('superAdmin/Users/SuspensionAppeals', [
            'appeals' => $appeals,
            'stats' => $stats,
            'filters' => [
                'search' => $validated['search'] ?? null,
                'status' => $status,
            ],
        ]);
    }

    public function approve(
        DecideSuspensionAppealRequest $request,
        int $id,
        SuspensionAppealService $appealService,
        PrivilegedFailureResponse $failures,
    ): mixed {
        return $this->decide($request, $id, 'approve', $appealService, $failures);
    }

    public function reject(
        DecideSuspensionAppealRequest $request,
        int $id,
        SuspensionAppealService $appealService,
        PrivilegedFailureResponse $failures,
    ): mixed {
        return $this->decide($request, $id, 'reject', $appealService, $failures);
    }

    private function formatAppeal(
        SuspensionAppeal $appeal,
        SuspensionAppealService $appealService,
        User|ShopOwner|null $account,
        ?AccountSuspension $currentSuspension,
    ): array {
        $presentation = $appealService->presentation($appeal, $account, $currentSuspension);
        $displayStatus = $presentation['state'] === 'stale'
            ? 'stale'
            : $presentation['status'];

        return [
            'id' => (int) $appeal->id,
            'account_type' => $appeal->account_type,
            'account_id' => (int) $appeal->account_id,
            'account_name' => $appeal->account_name,
            'recipient_email' => $appeal->recipient_email,
            'suspension_reason' => $appeal->suspension_reason,
            'status' => $displayStatus,
            'persisted_status' => $presentation['persisted_status'],
            'state' => $presentation['state'],
            'current' => $presentation['current'],
            'actionable' => $presentation['actionable'],
            'suspension_id' => $presentation['suspension_id'],
            'appeal_message' => $appeal->appeal_message,
            'reviewer_notes' => $appeal->reviewer_notes,
            'submitted_at' => $appeal->submitted_at?->toDateTimeString(),
            'reviewed_at' => $appeal->reviewed_at?->toDateTimeString(),
            'expires_at' => $appeal->expires_at?->toDateTimeString(),
            'created_at' => $appeal->created_at?->toDateTimeString(),
        ];
    }

    /** @return array{0: Collection<string, User|ShopOwner>, 1: Collection<int, AccountSuspension>} */
    private function presentationContext(Collection $appeals): array
    {
        $customerIds = $appeals->where('account_type', AccountSuspension::ACCOUNT_TYPE_CUSTOMER)
            ->pluck('account_id')->filter()->unique()->values();
        $shopOwnerIds = $appeals->where('account_type', AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER)
            ->pluck('account_id')->filter()->unique()->values();
        $suspensionIds = $appeals->pluck('suspension_id')->filter()->unique()->values();

        $customers = $customerIds->isEmpty()
            ? collect()
            : User::withTrashed()->whereIn('id', $customerIds)->get([
                'id', 'status', 'current_suspension_id', 'deleted_at',
            ]);
        $shops = $shopOwnerIds->isEmpty()
            ? collect()
            : ShopOwner::withTrashed()->whereIn('id', $shopOwnerIds)->get([
                'id', 'status', 'current_suspension_id', 'deleted_at',
            ]);
        $suspensions = $suspensionIds->isEmpty()
            ? collect()
            : AccountSuspension::query()
                ->whereIn('id', $suspensionIds)
                ->whereNull('ended_at')
                ->get(['id', 'account_type', 'account_id', 'ended_at']);

        $accounts = $customers->mapWithKeys(fn (User $account): array => [
            $this->accountKeyFor(AccountSuspension::ACCOUNT_TYPE_CUSTOMER, (int) $account->getKey()) => $account,
        ])->union($shops->mapWithKeys(fn (ShopOwner $account): array => [
            $this->accountKeyFor(AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER, (int) $account->getKey()) => $account,
        ]));

        return [$accounts, $suspensions->keyBy(fn (AccountSuspension $suspension): int => (int) $suspension->getKey())];
    }

    private function accountKey(SuspensionAppeal $appeal): string
    {
        return $this->accountKeyFor((string) $appeal->account_type, (int) $appeal->account_id);
    }

    private function accountKeyFor(string $accountType, int $accountId): string
    {
        return $accountType . ':' . $accountId;
    }

    private function countForStatus(Builder $query, string $status): int
    {
        $this->applyStatusFilter($query, $status);

        return (int) $query->count();
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        $now = now();

        if ($status === 'expired') {
            $query->where(function (Builder $statusQuery) use ($now): void {
                $statusQuery->where('status', 'expired')
                    ->orWhere(function (Builder $dueQuery) use ($now): void {
                        $dueQuery->whereIn('status', ['eligible', 'submitted'])
                            ->whereNotNull('expires_at')
                            ->where('expires_at', '<=', $now);
                    });
            });

            return;
        }

        if ($status === 'stale') {
            $query->whereIn('status', ['eligible', 'submitted'])
                ->where(function (Builder $staleQuery): void {
                    $staleQuery->whereNull('suspension_id')
                        ->orWhere(function (Builder $customerQuery): void {
                            $customerQuery->where('account_type', AccountSuspension::ACCOUNT_TYPE_CUSTOMER)
                                ->whereNotExists(function ($currentQuery): void {
                                    $currentQuery->selectRaw('1')
                                        ->from('users')
                                        ->join('account_suspensions AS current_suspension', function ($join): void {
                                            $join->on('current_suspension.id', '=', 'users.current_suspension_id')
                                                ->whereNull('current_suspension.ended_at');
                                        })
                                        ->whereColumn('users.id', 'suspension_appeals.account_id')
                                        ->whereColumn('current_suspension.id', 'suspension_appeals.suspension_id')
                                        ->where('users.status', 'suspended')
                                        ->whereNull('users.deleted_at');
                                });
                        })
                        ->orWhere(function (Builder $shopQuery): void {
                            $shopQuery->where('account_type', AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER)
                                ->whereNotExists(function ($currentQuery): void {
                                    $currentQuery->selectRaw('1')
                                        ->from('shop_owners')
                                        ->join('account_suspensions AS current_suspension', function ($join): void {
                                            $join->on('current_suspension.id', '=', 'shop_owners.current_suspension_id')
                                                ->whereNull('current_suspension.ended_at');
                                        })
                                        ->whereColumn('shop_owners.id', 'suspension_appeals.account_id')
                                        ->whereColumn('current_suspension.id', 'suspension_appeals.suspension_id')
                                        ->where('shop_owners.status', 'suspended')
                                        ->whereNull('shop_owners.deleted_at');
                                });
                        });
                });

            return;
        }

        if (in_array($status, ['eligible', 'submitted'], true)) {
            $query->where('status', $status)
                ->where(function (Builder $notDueQuery) use ($now): void {
                    $notDueQuery->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                });

            return;
        }

        $query->where('status', $status);
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

    private function decide(
        DecideSuspensionAppealRequest $request,
        int $id,
        string $decision,
        SuspensionAppealService $appealService,
        PrivilegedFailureResponse $failures,
    ): mixed {
        $actor = Auth::guard('super_admin')->user();
        abort_unless($actor instanceof SuperAdmin, 403);

        try {
            $result = $appealService->decide(
                appealId: $id,
                decision: $decision,
                reviewerNotes: $request->validated('reviewer_notes'),
                actor: $actor,
                request: $request,
            );

            $message = $decision === 'approve'
                ? 'Appeal approved and account access restored.'
                : 'Appeal rejected; the account remains suspended.';
            $payload = [
                'success' => true,
                'changed' => $result['changed'],
                'status' => (string) $result['appeal']->status,
                'account_status' => (string) $result['account']->getRawOriginal('status'),
                'suspension_id' => $result['suspension_id'],
                'message' => $message,
            ];

            if ($this->usesApiResponse($request)) {
                return response()->json($payload);
            }

            return back()->with('success', $message);
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();
            $forceJson = $this->usesApiResponse($request);
            if ($status === 409) {
                return $failures->conflict(
                    request: $request,
                    operation: 'suspension_appeal',
                    message: 'The appeal decision conflicts with current state.',
                    code: 'suspension_appeal_conflict',
                    forceJson: $forceJson,
                );
            }

            if ($status === 422) {
                return $failures->validation(
                    request: $request,
                    message: 'The appeal decision input is invalid.',
                    code: 'suspension_appeal_validation',
                    forceJson: $forceJson,
                );
            }

            return $failures->unexpected(
                request: $request,
                operation: 'suspension_appeal',
                exception: $exception,
                message: 'The appeal decision could not be completed.',
                code: 'suspension_appeal_error',
                forceJson: $forceJson,
            );
        } catch (Throwable $exception) {
            return $failures->unexpected(
                request: $request,
                operation: 'suspension_appeal',
                exception: $exception,
                message: 'The appeal decision could not be completed.',
                code: 'suspension_appeal_error',
                forceJson: $this->usesApiResponse($request),
            );
        }
    }

    private function usesApiResponse(Request $request): bool
    {
        return $request->expectsJson() || $request->ajax() || (bool) $request->header('X-Inertia');
    }
}
