<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountSuspension;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AccountLifecycleService
{
    public function __construct(
        private readonly AccountSuspensionService $suspensions,
        private readonly SuspensionAppealService $appeals,
        private readonly PrivilegedAudit $audit,
    ) {
    }

    /** @return array{changed: bool, account: User|ShopOwner, suspension: AccountSuspension|null, appeal: \App\Models\SuspensionAppeal|null} */
    public function suspend(string $accountType, int $accountId, SuperAdmin $actor, Request $request, string $reason): array
    {
        $result = DB::transaction(function () use ($accountType, $accountId, $actor, $request, $reason): array {
            $account = $this->lockAccount($accountType, $accountId);
            $priorStatus = $this->rawStatus($account);
            $result = $this->suspensions->suspendLocked($account, $actor, $reason);

            if ($result['changed']) {
                $this->writeAudit(
                    event: 'suspended',
                    request: $request,
                    actor: $actor,
                    account: $account,
                    priorStatus: $priorStatus,
                    newStatus: 'suspended',
                    reason: trim($reason),
                    suspensionId: (int) $result['suspension']->getKey(),
                );

                $this->appeals->queueSuspensionNotice($result['appeal'], $request);
            }

            return $result + ['account' => $account->fresh()];
        });

        return $result;
    }

    /** @return array{changed: bool, account: User|ShopOwner, suspension: AccountSuspension|null, appeal: \App\Models\SuspensionAppeal|null} */
    public function reactivate(string $accountType, int $accountId, SuperAdmin $actor, Request $request, string $reason): array
    {
        return DB::transaction(function () use ($accountType, $accountId, $actor, $request, $reason): array {
            $account = $this->lockAccount($accountType, $accountId);
            $priorStatus = $this->rawStatus($account);
            $result = $this->suspensions->reactivateLocked($account, $actor, $reason);

            if ($result['changed']) {
                $this->writeAudit(
                    event: 'reactivated',
                    request: $request,
                    actor: $actor,
                    account: $account,
                    priorStatus: $priorStatus,
                    newStatus: $this->rawStatus($account),
                    reason: trim($reason),
                    suspensionId: $result['suspension']?->getKey(),
                );
            }

            return $result + ['account' => $account->fresh()];
        });
    }

    /** @return array{changed: bool, account: User|ShopOwner} */
    public function archive(string $accountType, int $accountId, SuperAdmin $actor, Request $request, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new HttpException(422, 'An archive reason is required.');
        }

        return DB::transaction(function () use ($accountType, $accountId, $actor, $request, $reason): array {
            $account = $this->lockAccount($accountType, $accountId);

            if ($account->trashed()) {
                return ['changed' => false, 'account' => $account];
            }

            $priorStatus = $this->rawStatus($account);
            if (! $account->delete()) {
                throw new HttpException(500, 'The account could not be archived.');
            }

            $this->writeAudit(
                event: 'archived',
                request: $request,
                actor: $actor,
                account: $account,
                priorStatus: $priorStatus,
                newStatus: $priorStatus,
                reason: trim($reason),
                suspensionId: $account->current_suspension_id,
            );

            return ['changed' => true, 'account' => $account];
        });
    }

    /** @return array{changed: bool, account: User|ShopOwner} */
    public function restore(string $accountType, int $accountId, SuperAdmin $actor, Request $request, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new HttpException(422, 'A restore reason is required.');
        }

        return DB::transaction(function () use ($accountType, $accountId, $actor, $request, $reason): array {
            $account = $this->lockAccount($accountType, $accountId);

            if (! $account->trashed()) {
                return ['changed' => false, 'account' => $account->fresh()];
            }

            $priorStatus = $this->rawStatus($account);
            if (! $account->restore()) {
                throw new HttpException(500, 'The account could not be restored.');
            }

            $this->writeAudit(
                event: 'restored',
                request: $request,
                actor: $actor,
                account: $account,
                priorStatus: $priorStatus,
                newStatus: $priorStatus,
                reason: trim($reason),
                suspensionId: $account->current_suspension_id,
            );

            return ['changed' => true, 'account' => $account->fresh()];
        });
    }

    private function lockAccount(string $accountType, int $accountId): User|ShopOwner
    {
        if ($accountId <= 0) {
            throw new HttpException(404, 'Account not found.');
        }

        $model = match ($accountType) {
            'user', AccountSuspension::ACCOUNT_TYPE_CUSTOMER => User::class,
            'shop', AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER => ShopOwner::class,
            default => throw new HttpException(404, 'Account not found.'),
        };

        /** @var User|ShopOwner $account */
        $account = $model::withTrashed()
            ->whereKey($accountId)
            ->lockForUpdate()
            ->first();

        if (! $account) {
            throw new HttpException(404, 'Account not found.');
        }

        return $account;
    }

    private function rawStatus(Model $model): string
    {
        return (string) $model->getRawOriginal('status');
    }

    private function writeAudit(
        string $event,
        Request $request,
        SuperAdmin $actor,
        User|ShopOwner $account,
        string $priorStatus,
        string $newStatus,
        string $reason,
        ?int $suspensionId,
    ): void {
        $type = $account instanceof User ? 'user' : 'shop';

        match ([$type, $event]) {
            ['user', 'suspended'] => $this->audit->userSuspended($request, $actor, $account, $priorStatus, $newStatus, $reason, $suspensionId),
            ['user', 'reactivated'] => $this->audit->userReactivated($request, $actor, $account, $priorStatus, $newStatus, $reason, $suspensionId),
            ['user', 'archived'] => $this->audit->userArchived($request, $actor, $account, $priorStatus, $newStatus, $reason, $suspensionId),
            ['user', 'restored'] => $this->audit->userRestored($request, $actor, $account, $priorStatus, $newStatus, $reason, $suspensionId),
            ['shop', 'suspended'] => $this->audit->shopSuspended($request, $actor, $account, $priorStatus, $newStatus, $reason, $suspensionId),
            ['shop', 'reactivated'] => $this->audit->shopReactivated($request, $actor, $account, $priorStatus, $newStatus, $reason, $suspensionId),
            ['shop', 'archived'] => $this->audit->shopArchived($request, $actor, $account, $priorStatus, $newStatus, $reason, $suspensionId),
            ['shop', 'restored'] => $this->audit->shopRestored($request, $actor, $account, $priorStatus, $newStatus, $reason, $suspensionId),
            default => throw new HttpException(500, 'The account lifecycle event is invalid.'),
        };
    }
}
