<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountSuspension;
use App\Models\AuditLog;
use App\Models\ShopOwner;
use App\Models\ShopReportModerationAction;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PhaseTwoStateReconciler
{
    private const LIVE_APPEAL_STATUSES = ['eligible', 'submitted'];

    private const WARNING_ACTIONS = ['dismiss', 'warn', 'suspend'];

    public function __construct(private readonly PrivilegedAudit $audit)
    {
    }

    /**
     * @return array{
     *     accounts_inspected: int,
     *     suspensions_proposed: int,
     *     suspensions_created: int,
     *     suspensions_existing: int,
     *     appeals_expired: int,
     *     appeals_linked: int,
     *     appeals_superseded: int,
     *     warning_actions_proposed: int,
     *     warning_actions_created: int,
     *     warning_actions_existing: int,
     *     operator_review_required: int,
     *     operator_review_accounts: array<int, string>,
     *     failures: array<int, string>
     * }
     */
    public function reconcile(string $operationId, bool $apply = false, int $chunkSize = 100): array
    {
        if (! Str::isUuid($operationId)) {
            throw new InvalidArgumentException('The reconciliation operation ID must be a UUID.');
        }

        $chunkSize = max(1, $chunkSize);
        $result = $this->emptyResult();

        $this->reconcileAccounts(
            modelClass: User::class,
            accountType: AccountSuspension::ACCOUNT_TYPE_CUSTOMER,
            suspendedStatus: 'suspended',
            operationId: $operationId,
            apply: $apply,
            chunkSize: $chunkSize,
            result: $result,
        );

        $this->reconcileAccounts(
            modelClass: ShopOwner::class,
            accountType: AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER,
            suspendedStatus: 'suspended',
            operationId: $operationId,
            apply: $apply,
            chunkSize: $chunkSize,
            result: $result,
        );

        $this->reconcileLegacyWarnings(
            operationId: $operationId,
            apply: $apply,
            chunkSize: $chunkSize,
            result: $result,
        );

        return $result;
    }

    /**
     * @param class-string<Model> $modelClass
     * @param array<string, mixed> $result
     */
    private function reconcileAccounts(
        string $modelClass,
        string $accountType,
        string $suspendedStatus,
        string $operationId,
        bool $apply,
        int $chunkSize,
        array &$result,
    ): void {
        $table = (new $modelClass)->getTable();

        $modelClass::query()
            ->withTrashed()
            ->where(function ($query) use ($accountType, $suspendedStatus, $table): void {
                $query
                    ->where('status', $suspendedStatus)
                    ->orWhereExists(function ($appeals) use ($accountType, $table): void {
                        $appeals
                            ->selectRaw('1')
                            ->from('suspension_appeals')
                            ->where('account_type', $accountType)
                            ->whereColumn('suspension_appeals.account_id', $table.'.id')
                            ->whereIn('status', self::LIVE_APPEAL_STATUSES);
                    });
            })
            ->select(['id', 'status', 'current_suspension_id', 'updated_at'])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($accounts) use (
                $modelClass,
                $accountType,
                $suspendedStatus,
                $operationId,
                $apply,
                &$result,
            ): void {
                foreach ($accounts as $account) {
                    $accountId = (int) $account->getKey();
                    $result['accounts_inspected']++;
                    $delta = $this->emptyDelta();

                    try {
                        if ($apply) {
                            DB::transaction(function () use (
                                $modelClass,
                                $accountType,
                                $suspendedStatus,
                                $operationId,
                                $accountId,
                                &$delta,
                            ): void {
                                $lockedAccount = $modelClass::query()
                                    ->withTrashed()
                                    ->lockForUpdate()
                                    ->find($accountId);

                                if (! $lockedAccount instanceof Model) {
                                    throw new RuntimeException('The account no longer exists.');
                                }

                                $this->reconcileAccount(
                                    account: $lockedAccount,
                                    accountType: $accountType,
                                    suspendedStatus: $suspendedStatus,
                                    operationId: $operationId,
                                    apply: true,
                                    delta: $delta,
                                );
                            });
                        } else {
                            $this->reconcileAccount(
                                account: $account,
                                accountType: $accountType,
                                suspendedStatus: $suspendedStatus,
                                operationId: $operationId,
                                apply: false,
                                delta: $delta,
                            );
                        }

                        $this->mergeDelta($result, $delta);
                    } catch (Throwable) {
                        $result['failures'][] = sprintf(
                            'account_type=%s account_id=%d',
                            $accountType,
                            $accountId,
                        );
                    }
                }
            });
    }

    /**
     * @param array<string, mixed> $delta
     */
    private function reconcileAccount(
        Model $account,
        string $accountType,
        string $suspendedStatus,
        string $operationId,
        bool $apply,
        array &$delta,
    ): void {
        $now = now();
        $status = (string) $account->getRawOriginal('status');
        $suspension = $status === $suspendedStatus
            ? $this->findCurrentSuspension($account, $accountType, $apply)
            : null;

        $appealsQuery = SuspensionAppeal::query()
            ->where('account_type', $accountType)
            ->where('account_id', (int) $account->getKey())
            ->orderBy('id');

        if ($apply) {
            $appealsQuery->lockForUpdate();
        }

        $appeals = $appealsQuery->get();
        foreach ($appeals as $appeal) {
            if (! $this->isExpiredAppeal($appeal, $now)) {
                continue;
            }

            if ($apply) {
                $appeal->forceFill(['status' => 'expired', 'reviewed_at' => $now])->saveQuietly();
            }

            $delta['appeals_expired']++;
        }

        $liveAppeals = $appeals
            ->filter(fn (SuspensionAppeal $appeal): bool => in_array($appeal->status, self::LIVE_APPEAL_STATUSES, true))
            ->values();

        if ($status !== $suspendedStatus) {
            foreach ($liveAppeals as $appeal) {
                $this->supersedeAppeal(
                    appeal: $appeal,
                    account: $account,
                    accountType: $accountType,
                    operationId: $operationId,
                    ambiguityCount: 0,
                    apply: $apply,
                    delta: $delta,
                );
            }

            return;
        }

        $suspensionCreated = false;
        $uniqueAppeal = $liveAppeals->count() === 1 ? $liveAppeals->first() : null;

        if (! $suspension) {
            $delta['suspensions_proposed']++;

            if ($apply) {
                $reason = $this->nullableText($account->getAttribute('suspension_reason'))
                    ?? ($uniqueAppeal instanceof SuspensionAppeal
                        ? $this->nullableText($uniqueAppeal->suspension_reason)
                        : null);
                $actorId = $uniqueAppeal instanceof SuspensionAppeal
                    ? $uniqueAppeal->suspended_by_super_admin_id
                    : null;

                $this->assertSuperAdminExists($actorId);

                $suspension = AccountSuspension::query()->create([
                    'account_type' => $accountType,
                    'account_id' => (int) $account->getKey(),
                    'source' => AccountSuspension::SOURCE_LEGACY_RECONCILIATION,
                    'reason' => $reason,
                    'suspended_by_super_admin_id' => $actorId,
                    'started_at' => null,
                ]);
                $suspensionCreated = true;
            }
        } else {
            $delta['suspensions_existing']++;
        }

        if ($suspension instanceof AccountSuspension
            && (int) $account->getRawOriginal('current_suspension_id') !== (int) $suspension->getKey()) {
            if ($apply) {
                $account->forceFill(['current_suspension_id' => $suspension->getKey()])->saveQuietly();
            }
        }

        $operatorReviewCount = 0;

        if ($liveAppeals->count() === 1) {
            /** @var SuspensionAppeal $appeal */
            $appeal = $liveAppeals->first();

            if ($appeal->suspension_id === null) {
                if ($suspension instanceof AccountSuspension || ! $apply) {
                    if ($apply && $suspension instanceof AccountSuspension) {
                        $appeal->forceFill(['suspension_id' => $suspension->getKey()])->saveQuietly();
                    }

                    $delta['appeals_linked']++;
                }
            } elseif (! $suspension instanceof AccountSuspension
                || (int) $appeal->suspension_id !== (int) $suspension->getKey()) {
                $operatorReviewCount = 1;
            }
        } elseif ($liveAppeals->count() > 1) {
            $operatorReviewCount = 1;

            foreach ($liveAppeals as $appeal) {
                $this->supersedeAppeal(
                    appeal: $appeal,
                    account: $account,
                    accountType: $accountType,
                    operationId: $operationId,
                    ambiguityCount: $liveAppeals->count(),
                    apply: $apply,
                    delta: $delta,
                );
            }
        } else {
            $operatorReviewCount = 1;
        }

        if ($operatorReviewCount > 0) {
            $this->markOperatorReview($accountType, (int) $account->getKey(), $delta);
        }

        if ($suspensionCreated && $suspension instanceof AccountSuspension) {
            $this->audit->legacyAccountSuspensionReconciled(
                subject: $account,
                correlationId: $operationId,
                accountType: $accountType,
                accountId: (int) $account->getKey(),
                suspensionId: (int) $suspension->getKey(),
                priorStatus: $status,
                newStatus: $status,
                operatorReviewCount: $operatorReviewCount,
            );

            $delta['suspensions_created']++;
        }
    }

    private function findCurrentSuspension(Model $account, string $accountType, bool $lock): ?AccountSuspension
    {
        $pointerId = (int) $account->getRawOriginal('current_suspension_id');
        if ($pointerId > 0) {
            $pointerQuery = AccountSuspension::query()->whereKey($pointerId);
            if ($lock) {
                $pointerQuery->lockForUpdate();
            }

            $pointer = $pointerQuery->first();
            if ($pointer instanceof AccountSuspension) {
                if ((string) $pointer->account_type !== $accountType
                    || (int) $pointer->account_id !== (int) $account->getKey()) {
                    throw new RuntimeException('The current suspension pointer has a different account identity.');
                }

                if ($pointer->isCurrent()) {
                    return $pointer;
                }
            }
        }

        $query = AccountSuspension::query()
            ->where('account_type', $accountType)
            ->where('account_id', (int) $account->getKey())
            ->current()
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $current = $query->get();
        if ($current->count() > 1) {
            throw new RuntimeException('Multiple current suspensions require operator review.');
        }

        return $current->first();
    }

    /**
     * @param array<string, mixed> $delta
     */
    private function supersedeAppeal(
        SuspensionAppeal $appeal,
        Model $account,
        string $accountType,
        string $operationId,
        int $ambiguityCount,
        bool $apply,
        array &$delta,
    ): void {
        if ((string) $appeal->status !== 'eligible' && (string) $appeal->status !== 'submitted') {
            return;
        }

        $priorStatus = (string) $appeal->status;
        if ($apply) {
            $appeal->forceFill([
                'status' => 'superseded',
                'reviewed_at' => now(),
            ])->saveQuietly();

            $this->audit->legacyAppealSuperseded(
                subject: $account,
                correlationId: $operationId,
                accountType: $accountType,
                accountId: (int) $account->getKey(),
                appealId: (int) $appeal->getKey(),
                priorStatus: $priorStatus,
                newStatus: 'superseded',
                ambiguityCount: $ambiguityCount,
            );
        }

        $delta['appeals_superseded']++;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function reconcileLegacyWarnings(
        string $operationId,
        bool $apply,
        int $chunkSize,
        array &$result,
    ): void {
        AuditLog::query()
            ->where('action', 'shop_report_warn')
            ->where('target_type', 'ShopOwner')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($audits) use (
                $operationId,
                $apply,
                &$result,
            ): void {
                foreach ($audits->groupBy(fn (AuditLog $audit): int => (int) $audit->target_id) as $shopId => $shopAudits) {
                    $shopId = (int) $shopId;
                    $auditIds = $shopAudits->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
                    $delta = $this->emptyDelta();

                    try {
                        if ($apply) {
                            DB::transaction(function () use (
                                $shopId,
                                $shopAudits,
                                $operationId,
                                &$delta,
                            ): void {
                                $shop = ShopOwner::query()->withTrashed()->lockForUpdate()->find($shopId);
                                if (! $shop instanceof ShopOwner) {
                                    throw new RuntimeException('The warning target shop no longer exists.');
                                }

                                $this->reconcileWarningBatch(
                                    shopOwnerId: $shopId,
                                    audits: $shopAudits,
                                    operationId: $operationId,
                                    apply: true,
                                    delta: $delta,
                                );
                            });
                        } else {
                            $this->reconcileWarningBatch(
                                shopOwnerId: $shopId,
                                audits: $shopAudits,
                                operationId: $operationId,
                                apply: false,
                                delta: $delta,
                            );
                        }

                        $this->mergeDelta($result, $delta);
                    } catch (Throwable) {
                        $result['failures'][] = sprintf(
                            'shop_owner_id=%d legacy_audit_log_ids=%s',
                            $shopId,
                            implode(',', $auditIds),
                        );
                    }
                }
            });
    }

    /**
     * @param iterable<int, AuditLog> $audits
     * @param array<string, mixed> $delta
     */
    private function reconcileWarningBatch(
        int $shopOwnerId,
        iterable $audits,
        string $operationId,
        bool $apply,
        array &$delta,
    ): void {
        $shop = ShopOwner::query()->withTrashed()->find($shopOwnerId);
        if (! $shop instanceof ShopOwner) {
            throw new RuntimeException('The warning target shop no longer exists.');
        }

        foreach ($audits as $audit) {
            $existing = ShopReportModerationAction::query()
                ->where('legacy_audit_log_id', (int) $audit->getKey())
                ->first();

            if ($existing instanceof ShopReportModerationAction) {
                if ((int) $existing->shop_owner_id !== $shopOwnerId) {
                    throw new RuntimeException('A legacy warning is linked to a different shop.');
                }

                $delta['warning_actions_existing']++;
                continue;
            }

            $data = is_array($audit->data) ? $audit->data : [];
            $adminId = (int) ($data['admin_id'] ?? 0);
            $this->assertSuperAdminExists($adminId);

            $requestedAction = $this->boundedWarningAction($data['requested_action'] ?? 'warn');
            $appliedAction = $this->boundedWarningAction($data['applied_action'] ?? 'warn');
            $warningStrikeNumber = $this->legacyWarningRank($audit, $shopOwnerId);

            $collision = ShopReportModerationAction::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('warning_strike_number', $warningStrikeNumber)
                ->first();

            if ($collision instanceof ShopReportModerationAction
                && (int) $collision->legacy_audit_log_id !== (int) $audit->getKey()) {
                throw new RuntimeException('The deterministic warning strike is already occupied.');
            }

            $delta['warning_actions_proposed']++;

            if (! $apply) {
                continue;
            }

            $createdAt = $audit->getAttribute('created_at') ?? now();
            $action = new ShopReportModerationAction();
            $action->forceFill([
                'shop_owner_id' => $shopOwnerId,
                'actor_id' => $adminId,
                'requested_action' => $requestedAction,
                'applied_action' => $appliedAction,
                'report_ids' => [],
                'decision_key' => null,
                'warning_strike_number' => $warningStrikeNumber,
                'source' => AccountSuspension::SOURCE_LEGACY_RECONCILIATION,
                'legacy_audit_log_id' => (int) $audit->getKey(),
                'notes' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();

            $this->audit->legacyWarningStrikeReconciled(
                subject: $action,
                correlationId: $operationId,
                shopOwnerId: $shopOwnerId,
                moderationActionId: (int) $action->getKey(),
                legacyAuditLogId: (int) $audit->getKey(),
            );

            $delta['warning_actions_created']++;
        }
    }

    private function legacyWarningRank(AuditLog $audit, int $shopOwnerId): int
    {
        $createdAt = $audit->getRawOriginal('created_at');
        $query = AuditLog::query()
            ->where('action', 'shop_report_warn')
            ->where('target_type', 'ShopOwner')
            ->where('target_id', $shopOwnerId);

        if ($createdAt === null) {
            $query->where(function ($ordered) use ($audit): void {
                $ordered
                    ->whereNull('created_at')
                    ->where('id', '<=', (int) $audit->getKey());
            });
        } else {
            $query->where(function ($ordered) use ($createdAt, $audit): void {
                $ordered
                    ->whereNull('created_at')
                    ->orWhere('created_at', '<', $createdAt)
                    ->orWhere(function ($sameTime) use ($createdAt, $audit): void {
                        $sameTime
                            ->where('created_at', '=', $createdAt)
                            ->where('id', '<=', (int) $audit->getKey());
                    });
            });
        }

        return max(1, (int) $query->count());
    }

    private function isExpiredAppeal(SuspensionAppeal $appeal, mixed $now): bool
    {
        return in_array($appeal->status, self::LIVE_APPEAL_STATUSES, true)
            && $appeal->expires_at !== null
            && $appeal->expires_at->lessThanOrEqualTo($now);
    }

    private function assertSuperAdminExists(?int $adminId): void
    {
        if ($adminId === null) {
            return;
        }

        if ($adminId <= 0) {
            throw new RuntimeException('Legacy privileged actor provenance is missing.');
        }

        if (! SuperAdmin::query()->whereKey($adminId)->exists()) {
            throw new RuntimeException('Legacy privileged actor provenance is invalid.');
        }
    }

    private function boundedWarningAction(mixed $action): string
    {
        $normalized = is_string($action) ? strtolower(trim($action)) : '';
        if (! in_array($normalized, self::WARNING_ACTIONS, true)) {
            throw new RuntimeException('Legacy moderation action is outside the bounded state set.');
        }

        return $normalized;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, int|array<int, string>>
     */
    private function emptyResult(): array
    {
        return array_merge($this->emptyDelta(), [
            'accounts_inspected' => 0,
            'failures' => [],
        ]);
    }

    /**
     * @return array<string, int|array<int, string>>
     */
    private function emptyDelta(): array
    {
        return [
            'suspensions_proposed' => 0,
            'suspensions_created' => 0,
            'suspensions_existing' => 0,
            'appeals_expired' => 0,
            'appeals_linked' => 0,
            'appeals_superseded' => 0,
            'warning_actions_proposed' => 0,
            'warning_actions_created' => 0,
            'warning_actions_existing' => 0,
            'operator_review_required' => 0,
            'operator_review_accounts' => [],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $delta
     */
    private function mergeDelta(array &$result, array $delta): void
    {
        foreach ([
            'suspensions_proposed',
            'suspensions_created',
            'suspensions_existing',
            'appeals_expired',
            'appeals_linked',
            'appeals_superseded',
            'warning_actions_proposed',
            'warning_actions_created',
            'warning_actions_existing',
            'operator_review_required',
        ] as $key) {
            $result[$key] += (int) ($delta[$key] ?? 0);
        }

        $result['operator_review_accounts'] = array_values(array_unique(array_merge(
            $result['operator_review_accounts'],
            $delta['operator_review_accounts'],
        )));
    }

    /** @param array<string, mixed> $delta */
    private function markOperatorReview(string $accountType, int $accountId, array &$delta): void
    {
        $delta['operator_review_required']++;
        $delta['operator_review_accounts'][] = sprintf('%s#%d', $accountType, $accountId);
    }
}
