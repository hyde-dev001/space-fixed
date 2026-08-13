<?php

namespace App\Services;

use App\Enums\PrivilegedDeliveryType;
use App\Models\AccountSuspension;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SuspensionAppealService
{
    public function __construct(
        private readonly PrivilegedMailDispatcher $privilegedMailDispatcher,
        private readonly PrivilegedAudit $audit,
    ) {
    }

    public function createForSuspension(
        User|ShopOwner $account,
        AccountSuspension $suspension,
        string $reason,
        ?int $suspendedBySuperAdminId,
    ): SuspensionAppeal {
        $accountType = $account instanceof ShopOwner
            ? AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER
            : AccountSuspension::ACCOUNT_TYPE_CUSTOMER;
        $expectedName = $account instanceof ShopOwner
            ? trim(($account->business_name ?? '') ?: (($account->first_name ?? '') . ' ' . ($account->last_name ?? '')))
            : trim((string) ($account->name ?? (($account->first_name ?? '') . ' ' . ($account->last_name ?? ''))));
        $recipientEmail = trim((string) ($account->email ?? ''));

        if ($recipientEmail === '') {
            throw new \RuntimeException('A suspension appeal recipient email is required.');
        }

        SuspensionAppeal::query()
            ->where('account_type', $accountType)
            ->where('account_id', (int) $account->getKey())
            ->where(function ($query) use ($suspension): void {
                $query->whereNull('suspension_id')
                    ->orWhere('suspension_id', '!=', (int) $suspension->getKey());
            })
            ->whereIn('status', ['eligible', 'submitted'])
            ->lockForUpdate()
            ->get()
            ->each(function (SuspensionAppeal $appeal): void {
                $appeal->forceFill([
                    'status' => 'superseded',
                    'reviewed_at' => now(),
                ])->save();
            });

        return SuspensionAppeal::query()->create([
            'account_type' => $accountType,
            'account_id' => (int) $account->getKey(),
            'suspension_id' => (int) $suspension->getKey(),
            'account_name' => $expectedName,
            'recipient_email' => $recipientEmail,
            'suspension_reason' => trim($reason),
            'suspended_by_super_admin_id' => $suspendedBySuperAdminId,
            'status' => 'eligible',
            'appeal_token' => hash('sha256', (string) Str::uuid()),
            'expires_at' => now()->addHours((int) config('reporting.suspension_appeal_link_hours', 168)),
        ]);
    }

    /**
     * Submit an appeal against the exact current suspension identity.
     *
     * @return array{changed: bool, appeal: SuspensionAppeal, expired?: bool}
     */
    public function submit(string $token, string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            throw new HttpException(422, 'An appeal message is required.');
        }

        $result = DB::transaction(function () use ($token, $message): array {
            $candidate = SuspensionAppeal::query()
                ->where('appeal_token', $token)
                ->first();

            if (! $candidate) {
                throw new HttpException(404, 'The appeal was not found.');
            }

            $account = $this->lockAccountForAppeal($candidate);

            if ($this->isDue($candidate)
                && in_array((string) $candidate->status, ['eligible', 'submitted'], true)) {
                $appeal = $this->lockAppeal($candidate->getKey());
                $this->assertAppealAccount($appeal, $account);
                $appeal->forceFill([
                    'status' => 'expired',
                    'reviewed_at' => now(),
                ])->save();

                return [
                    'changed' => false,
                    'expired' => true,
                    'appeal' => $appeal->fresh(),
                ];
            }

            if (! in_array((string) $candidate->status, ['eligible', 'submitted'], true)) {
                throw new HttpException(409, 'This appeal is no longer actionable.');
            }

            $suspension = $this->lockCurrentSuspension($account, $candidate->suspension_id);
            $appeal = $this->lockAppeal($candidate->getKey());
            $this->assertAppealAccount($appeal, $account, $suspension);

            if ((string) $appeal->status === 'submitted') {
                if ($this->normalizeText($appeal->appeal_message) === $message) {
                    return ['changed' => false, 'appeal' => $appeal->fresh()];
                }

                throw new HttpException(409, 'A different appeal message is already committed.');
            }

            if ((string) $appeal->status !== 'eligible') {
                throw new HttpException(409, 'This appeal is no longer eligible for submission.');
            }

            $appeal->forceFill([
                'status' => 'submitted',
                'appeal_message' => $message,
                'submitted_at' => now(),
            ])->save();

            $this->queueSubmissionNotifications($appeal);

            return ['changed' => true, 'appeal' => $appeal->fresh()];
        });

        if (($result['expired'] ?? false) === true) {
            throw new HttpException(410, 'This appeal link has already expired.');
        }

        return $result;
    }

    /**
     * Return the read-only state presented to privileged and public pages.
     * Expiry is computed here; GET requests never persist it.
     *
     * @return array{status: string, persisted_status: string, state: string, current: bool, actionable: bool, suspension_id: int|null}
     */
    public function presentation(
        SuspensionAppeal $appeal,
        User|ShopOwner|null $account = null,
        ?AccountSuspension $currentSuspension = null,
    ): array
    {
        $persistedStatus = (string) $appeal->status;
        $status = $this->effectiveStatus($appeal);
        $current = false;

        if (in_array($persistedStatus, ['eligible', 'submitted'], true) && $appeal->suspension_id !== null) {
            $account ??= $this->findAccountForAppeal($appeal);
            if ($account && ! $account->trashed()) {
                $currentSuspension ??= AccountSuspension::query()
                    ->whereKey((int) $account->current_suspension_id)
                    ->whereNull('ended_at')
                    ->first();

                $current = $currentSuspension !== null
                    && $this->accountType($account) === (string) $currentSuspension->account_type
                    && (int) $currentSuspension->account_id === (int) $account->getKey()
                    && (int) $currentSuspension->getKey() === (int) $appeal->suspension_id
                    && (string) $account->getRawOriginal('status') === 'suspended';
            }
        }

        $state = $status;
        if (in_array($persistedStatus, ['eligible', 'submitted'], true) && ! $current && $status !== 'expired') {
            $state = 'stale';
        }

        return [
            'status' => $status,
            'persisted_status' => $persistedStatus,
            'state' => $state,
            'current' => $current,
            'actionable' => $status === 'submitted' && $current,
            'suspension_id' => $appeal->suspension_id !== null ? (int) $appeal->suspension_id : null,
        ];
    }

    /**
     * Approve or reject one submitted appeal. Only a Super Admin may call
     * this workflow; route middleware remains a second authorization layer.
     *
     * @return array{changed: bool, account: User|ShopOwner, appeal: SuspensionAppeal, suspension_id: int|null}
     */
    public function decide(
        int $appealId,
        string $decision,
        ?string $reviewerNotes,
        SuperAdmin $actor,
        \Illuminate\Http\Request $request,
    ): array {
        if ($actor->role !== SuperAdmin::ROLE_SUPER_ADMIN) {
            throw new HttpException(403, 'Only a Super Admin may resolve suspension appeals.');
        }

        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw new HttpException(422, 'The appeal decision is invalid.');
        }

        $reviewerNotes = $this->normalizeText($reviewerNotes);

        $result = DB::transaction(function () use ($appealId, $decision, $reviewerNotes, $actor, $request): array {
            $candidate = SuspensionAppeal::query()->whereKey($appealId)->first();
            if (! $candidate) {
                throw new HttpException(404, 'The appeal was not found.');
            }

            $account = $this->lockAccountForAppeal($candidate);
            $candidateStatus = (string) $candidate->status;

            if (in_array($candidateStatus, ['approved', 'rejected'], true)) {
                $appeal = $this->lockAppeal($candidate->getKey());
                $this->assertAppealAccount($appeal, $account);

                $terminalStatus = $decision === 'approve' ? 'approved' : 'rejected';
                if ($candidateStatus === $terminalStatus && $this->normalizeText($appeal->reviewer_notes) === $reviewerNotes) {
                    return [
                        'changed' => false,
                        'account' => $account->fresh(),
                        'appeal' => $appeal->fresh(),
                        'suspension_id' => $appeal->suspension_id !== null ? (int) $appeal->suspension_id : null,
                    ];
                }

                throw new HttpException(409, 'This appeal already has a different terminal decision.');
            }

            $suspension = $this->lockCurrentSuspension($account, $candidate->suspension_id);
            $appeal = $this->lockAppeal($candidate->getKey());
            $this->assertAppealAccount($appeal, $account, $suspension);

            if ($this->isDue($appeal)) {
                if (in_array((string) $appeal->status, ['eligible', 'submitted'], true)) {
                    $appeal->forceFill([
                        'status' => 'expired',
                        'reviewed_at' => now(),
                    ])->save();
                }

                throw new HttpException(409, 'An expired appeal cannot be decided.');
            }

            if ((string) $appeal->status !== 'submitted') {
                throw new HttpException(409, 'Only submitted appeals can be decided.');
            }

            if ($decision === 'approve') {
                $accountSuspensions = app(AccountSuspensionService::class);
                $reactivation = $accountSuspensions->reactivateLocked(
                    $account,
                    $actor,
                    $reviewerNotes ?? 'Suspension appeal approved.',
                );

                $appeal->forceFill([
                    'status' => 'approved',
                    'reviewer_id' => (int) $actor->getKey(),
                    'reviewer_notes' => $reviewerNotes,
                    'reviewed_at' => now(),
                ])->save();
            } else {
                $reactivation = ['suspension' => $suspension];
                $appeal->forceFill([
                    'status' => 'rejected',
                    'reviewer_id' => (int) $actor->getKey(),
                    'reviewer_notes' => $reviewerNotes,
                    'reviewed_at' => now(),
                ])->save();
            }

            $this->decidedAudit(
                request: $request,
                actor: $actor,
                appeal: $appeal,
                account: $account,
                decision: $decision,
                reviewerNotes: $reviewerNotes,
                suspensionId: (int) $suspension->getKey(),
            );

            $this->queueDecisionEmail($appeal, $account, $request);

            return [
                'changed' => true,
                'account' => $account->fresh(),
                'appeal' => $appeal->fresh(),
                'suspension_id' => (int) ($reactivation['suspension']?->getKey() ?? $suspension->getKey()),
            ];
        });

        return $result;
    }

    public function expireDue(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new HttpException(422, 'The expiry limit must be between 1 and 1000.');
        }

        $processed = 0;
        SuspensionAppeal::query()
            ->whereIn('status', ['eligible', 'submitted'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(min(100, $limit), function ($appeals) use (&$processed, $limit): bool {
                foreach ($appeals as $candidate) {
                    if ($processed >= $limit) {
                        return false;
                    }

                    $changed = false;
                    DB::transaction(function () use ($candidate, &$changed): void {
                        $appeal = SuspensionAppeal::query()->whereKey($candidate->getKey())->lockForUpdate()->first();
                        if (! $appeal || ! in_array((string) $appeal->status, ['eligible', 'submitted'], true) || ! $this->isDue($appeal)) {
                            return;
                        }

                        $appeal->forceFill([
                            'status' => 'expired',
                            'reviewed_at' => now(),
                        ])->save();
                        $changed = true;
                    });

                    if ($changed) {
                        $processed++;
                    }
                }

                return $processed < $limit;
            });

        return $processed;
    }

    private function effectiveStatus(SuspensionAppeal $appeal): string
    {
        return in_array((string) $appeal->status, ['eligible', 'submitted'], true) && $this->isDue($appeal)
            ? 'expired'
            : (string) $appeal->status;
    }

    private function isDue(SuspensionAppeal $appeal): bool
    {
        return $appeal->expires_at !== null && now()->greaterThanOrEqualTo($appeal->expires_at);
    }

    private function lockAccountForAppeal(SuspensionAppeal $appeal): User|ShopOwner
    {
        $model = match ((string) $appeal->account_type) {
            AccountSuspension::ACCOUNT_TYPE_CUSTOMER => User::class,
            AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER => ShopOwner::class,
            default => throw new HttpException(409, 'The appeal account type is invalid.'),
        };

        /** @var User|ShopOwner|null $account */
        $account = $model::withTrashed()
            ->whereKey((int) $appeal->account_id)
            ->lockForUpdate()
            ->first();

        if (! $account || $account->trashed()) {
            throw new HttpException(409, 'The appeal account is unavailable.');
        }

        return $account;
    }

    private function findAccountForAppeal(SuspensionAppeal $appeal): User|ShopOwner|null
    {
        $model = match ((string) $appeal->account_type) {
            AccountSuspension::ACCOUNT_TYPE_CUSTOMER => User::class,
            AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER => ShopOwner::class,
            default => null,
        };

        if ($model === null) {
            return null;
        }

        /** @var User|ShopOwner|null $account */
        return $model::withTrashed()->whereKey((int) $appeal->account_id)->first();
    }

    private function lockAppeal(int|string $appealId): SuspensionAppeal
    {
        $appeal = SuspensionAppeal::query()->whereKey($appealId)->lockForUpdate()->first();
        if (! $appeal) {
            throw new HttpException(409, 'The appeal changed while it was being reviewed.');
        }

        return $appeal;
    }

    private function lockCurrentSuspension(User|ShopOwner $account, int|string|null $expectedSuspensionId): AccountSuspension
    {
        if ($expectedSuspensionId === null || (int) $expectedSuspensionId <= 0 || $account->current_suspension_id === null) {
            throw new HttpException(409, 'The appeal is not linked to a current suspension.');
        }

        if ((int) $account->current_suspension_id !== (int) $expectedSuspensionId
            || (string) $account->getRawOriginal('status') !== 'suspended') {
            throw new HttpException(409, 'The appeal is stale and is not linked to the current suspension.');
        }

        $suspension = AccountSuspension::query()
            ->whereKey((int) $account->current_suspension_id)
            ->lockForUpdate()
            ->first();

        if (! $suspension
            || ! $suspension->isCurrent()
            || $suspension->account_type !== $this->accountType($account)
            || (int) $suspension->account_id !== (int) $account->getKey()) {
            throw new HttpException(409, 'The current suspension identity is invalid.');
        }

        return $suspension;
    }

    private function assertAppealAccount(
        SuspensionAppeal $appeal,
        User|ShopOwner $account,
        ?AccountSuspension $suspension = null,
    ): void {
        if ((int) $appeal->account_id !== (int) $account->getKey()
            || (string) $appeal->account_type !== $this->accountType($account)) {
            throw new HttpException(409, 'The appeal account identity is invalid.');
        }

        if ($suspension !== null && (int) $appeal->suspension_id !== (int) $suspension->getKey()) {
            throw new HttpException(409, 'The appeal is linked to a different suspension.');
        }
    }

    private function accountType(User|ShopOwner $account): string
    {
        return $account instanceof User
            ? AccountSuspension::ACCOUNT_TYPE_CUSTOMER
            : AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER;
    }

    private function normalizeText(?string $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function decidedAudit(
        \Illuminate\Http\Request $request,
        SuperAdmin $actor,
        SuspensionAppeal $appeal,
        User|ShopOwner $account,
        string $decision,
        ?string $reviewerNotes,
        int $suspensionId,
    ): void {
        app(PrivilegedAudit::class)->suspensionAppealDecided(
            request: $request,
            actor: $actor,
            appeal: $appeal,
            account: $account,
            decision: $decision,
            reviewerNotes: $reviewerNotes,
            suspensionId: $suspensionId,
        );
    }

    public function queueSuspensionNotice(SuspensionAppeal $appeal, ?Request $request = null): void
    {
        if ((string) $appeal->status !== 'eligible') {
            return;
        }

        $recipientEmail = trim((string) $appeal->recipient_email);
        if ($recipientEmail === '') {
            return;
        }

        $expiresAt = $appeal->expires_at ?? now()->addHours((int) config('reporting.suspension_appeal_link_hours', 168));
        $isShopOwner = (string) $appeal->account_type === AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER;

        $this->privilegedMailDispatcher->dispatch(
            type: $isShopOwner
                ? PrivilegedDeliveryType::SHOP_SUSPENSION_NOTICE
                : PrivilegedDeliveryType::CUSTOMER_SUSPENSION_NOTICE,
            businessEventId: $appeal->suspension_id !== null
                ? 'account-suspension:'.$appeal->suspension_id.':notice'
                : 'suspension-appeal:'.$appeal->getKey().':notice',
            recipientType: $isShopOwner ? 'shop_owner' : 'user',
            recipientId: (int) $appeal->account_id,
            payload: [
                'recipient_email' => $recipientEmail,
                'account_name' => (string) ($appeal->account_name ?: 'User'),
                'account_type_label' => $isShopOwner ? 'shop owner' : 'customer',
                'reason' => $appeal->suspension_reason,
                'appeal_url' => URL::temporarySignedRoute(
                    'appeals.show',
                    $expiresAt,
                    ['token' => $appeal->appeal_token],
                ),
                'expires_at_label' => $expiresAt->format('M d, Y h:i A'),
            ],
            correlationId: $request ? $this->audit->correlationId($request) : null,
        );
    }

    /** @deprecated Use queueSuspensionNotice() inside the owning transaction. */
    public function sendSuspensionNotice(SuspensionAppeal $appeal): void
    {
        $this->queueSuspensionNotice($appeal);
    }

    public function createAndSendForShopOwner(ShopOwner $shopOwner, ?string $reason, ?int $suspendedBySuperAdminId): ?SuspensionAppeal
    {
        $email = trim((string) ($shopOwner->email ?? ''));
        if ($email === '') {
            return null;
        }

        $name = trim(($shopOwner->business_name ?? '') ?: (($shopOwner->first_name ?? '') . ' ' . ($shopOwner->last_name ?? '')));

        return $this->createAndSend(
            accountType: 'shop_owner',
            accountId: (int) $shopOwner->id,
            accountName: $name,
            recipientEmail: $email,
            reason: $reason,
            suspendedBySuperAdminId: $suspendedBySuperAdminId,
            accountTypeLabel: 'shop owner'
        );
    }

    public function createAndSendForCustomer(User $user, ?string $reason, ?int $suspendedBySuperAdminId): ?SuspensionAppeal
    {
        $email = trim((string) ($user->email ?? ''));
        if ($email === '') {
            return null;
        }

        $name = trim((string) ($user->name ?? (($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))));

        return $this->createAndSend(
            accountType: 'customer',
            accountId: (int) $user->id,
            accountName: $name,
            recipientEmail: $email,
            reason: $reason,
            suspendedBySuperAdminId: $suspendedBySuperAdminId,
            accountTypeLabel: 'customer'
        );
    }

    public function sendDecisionEmail(SuspensionAppeal $appeal): void
    {
        $account = $this->findAccountForAppeal($appeal);
        if ($account) {
            $this->queueDecisionEmail($appeal, $account);
        }
    }

    public function sendSubmissionNotificationToSuperAdmins(SuspensionAppeal $appeal): void
    {
        $this->queueSubmissionNotifications($appeal);
    }

    private function createAndSend(
        string $accountType,
        int $accountId,
        string $accountName,
        string $recipientEmail,
        ?string $reason,
        ?int $suspendedBySuperAdminId,
        string $accountTypeLabel
    ): SuspensionAppeal {
        $expiresAt = now()->addHours((int) config('reporting.suspension_appeal_link_hours', 168));

        SuspensionAppeal::query()
            ->where('account_type', $accountType)
            ->where('account_id', $accountId)
            ->whereIn('status', ['eligible', 'submitted'])
            ->update([
                'status' => 'expired',
                'reviewed_at' => now(),
            ]);

        $appeal = SuspensionAppeal::create([
            'account_type' => $accountType,
            'account_id' => $accountId,
            'account_name' => $accountName,
            'recipient_email' => $recipientEmail,
            'suspension_reason' => $reason,
            'suspended_by_super_admin_id' => $suspendedBySuperAdminId,
            'status' => 'eligible',
            'appeal_token' => hash('sha256', (string) Str::uuid()),
            'expires_at' => $expiresAt,
        ]);

        $this->queueSuspensionNotice($appeal);

        return $appeal;
    }

    private function queueDecisionEmail(
        SuspensionAppeal $appeal,
        User|ShopOwner $account,
        ?Request $request = null,
    ): void {
        $decision = (string) $appeal->status;
        $recipientEmail = trim((string) $appeal->recipient_email);
        if (! in_array($decision, ['approved', 'rejected'], true) || $recipientEmail === '') {
            return;
        }

        $isShopOwner = $account instanceof ShopOwner;
        $this->privilegedMailDispatcher->dispatch(
            type: PrivilegedDeliveryType::SUSPENSION_APPEAL_DECIDED,
            businessEventId: 'suspension-appeal-decided:'.$appeal->getKey(),
            recipientType: $isShopOwner ? 'shop_owner' : 'user',
            recipientId: (int) $account->getKey(),
            payload: [
                'recipient_email' => $recipientEmail,
                'account_name' => (string) ($appeal->account_name ?: 'User'),
                'account_type_label' => $isShopOwner ? 'shop owner' : 'customer',
                'decision' => $decision,
                'reviewer_notes' => $appeal->reviewer_notes,
            ],
            correlationId: $request ? $this->audit->correlationId($request) : null,
        );
    }

    private function queueSubmissionNotifications(SuspensionAppeal $appeal): void
    {
        if ((string) $appeal->status !== 'submitted' || trim((string) $appeal->appeal_message) === '') {
            return;
        }

        $typeLabel = (string) $appeal->account_type === AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER
            ? 'shop owner'
            : 'customer';
        $reviewUrl = route('admin.suspension-appeals');
        $submittedAtLabel = ($appeal->submitted_at ?? now())->format('M d, Y h:i A');

        SuperAdmin::query()
            ->active()
            ->get()
            ->filter(fn (SuperAdmin $recipient): bool => $recipient->hasCompletedMfaSetup()
                && $recipient->hasCapability(SuperAdmin::CAP_VIEW_APPEALS)
                && trim((string) $recipient->email) !== '')
            ->each(function (SuperAdmin $recipient) use ($appeal, $typeLabel, $reviewUrl, $submittedAtLabel): void {
                $this->privilegedMailDispatcher->dispatch(
                    type: PrivilegedDeliveryType::SUSPENSION_APPEAL_SUBMITTED,
                    businessEventId: 'suspension-appeal-submitted:'.$appeal->getKey(),
                    recipientType: 'super_admin',
                    recipientId: (int) $recipient->getKey(),
                    payload: [
                        'recipient_email' => (string) $recipient->email,
                        'account_name' => (string) ($appeal->account_name ?: 'User'),
                        'account_type_label' => $typeLabel,
                        'account_recipient_email' => (string) ($appeal->recipient_email ?? ''),
                        'suspension_reason' => $appeal->suspension_reason,
                        'appeal_message' => (string) $appeal->appeal_message,
                        'submitted_at_label' => $submittedAtLabel,
                        'review_url' => $reviewUrl,
                    ],
                );
            });
    }
}
