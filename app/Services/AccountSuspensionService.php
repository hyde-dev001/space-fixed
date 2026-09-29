<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountSuspension;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Owns suspension identity and linked-employee provenance.
 *
 * Callers must already hold the aggregate-root transaction and row lock.
 * This class deliberately does not begin or commit transactions and is not
 * exposed as a controller dependency.
 */
final class AccountSuspensionService
{
    public function __construct(private readonly SuspensionAppealService $appeals)
    {
    }

    /**
     * Suspend a locked active user or approved shop.
     *
     * The caller must hold the aggregate transaction and root lock.
     *
     * @return array{changed: bool, suspension: AccountSuspension, appeal: SuspensionAppeal}
     */
    public function suspendLocked(
        User|ShopOwner $account,
        SuperAdmin $actor,
        string $reason,
        string $source = AccountSuspension::SOURCE_RUNTIME,
    ): array {
        $accountType = $this->accountType($account);
        $reason = trim($reason);
        if ($reason === '') {
            throw new HttpException(422, 'A suspension reason is required.');
        }

        if (! in_array($source, [
            AccountSuspension::SOURCE_RUNTIME,
            AccountSuspension::SOURCE_LEGACY_RECONCILIATION,
        ], true)) {
            throw $this->conflict('The suspension source is invalid.');
        }

        if ($account->trashed()) {
            throw $this->conflict('An archived account must be restored before suspension.');
        }

        $current = $this->lockedCurrentSuspension($account, $accountType);
        $status = $this->rawStatus($account);
        $expectedStatus = $accountType === AccountSuspension::ACCOUNT_TYPE_CUSTOMER ? 'active' : 'approved';

        if ($status === 'suspended') {
            if (! $current) {
                throw $this->conflict('The account has no current suspension identity.');
            }

            if ((string) $current->reason === $reason && (string) $current->source === $source) {
                $appeal = $this->lockedAppeal($current);
                if (! $appeal) {
                    throw $this->conflict('The current suspension has no appeal identity.');
                }

                return ['changed' => false, 'suspension' => $current, 'appeal' => $appeal];
            }

            throw $this->conflict('The account is already suspended for a different operation.');
        }

        if ($status !== $expectedStatus) {
            throw $this->conflict('The account is not in a suspendable state.');
        }

        if ($current) {
            throw $this->conflict('The account has an inconsistent current suspension state.');
        }

        $suspension = AccountSuspension::query()->create([
            'account_type' => $accountType,
            'account_id' => (int) $account->getKey(),
            'source' => $source,
            'reason' => $reason,
            'suspended_by_super_admin_id' => (int) $actor->getKey(),
            'started_at' => now(),
        ]);

        $appeal = $this->appeals->createForSuspension($account, $suspension, $reason, (int) $actor->getKey());

        $linkedEmployee = $this->resolveEmployeeForSuspension($account, $suspension, $reason);
        if ($linkedEmployee) {
            $suspension->forceFill([
                'linked_employee_id' => (int) $linkedEmployee->getKey(),
                'linked_employee_prior_status' => 'active',
            ])->save();
        }

        $account->forceFill([
            'status' => 'suspended',
            'current_suspension_id' => (int) $suspension->getKey(),
        ]);

        if ($account instanceof ShopOwner) {
            $account->suspension_reason = $reason;
        }

        $account->save();

        return [
            'changed' => true,
            'suspension' => $suspension->fresh(),
            'appeal' => $appeal->fresh(),
        ];
    }

    /**
     * Reactivate a locked suspended user or shop.
     *
     * The caller must hold the aggregate transaction and root lock.
     *
     * @return array{changed: bool, suspension: AccountSuspension|null, appeal: SuspensionAppeal|null}
     */
    public function reactivateLocked(
        User|ShopOwner $account,
        SuperAdmin $actor,
        string $reason,
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new HttpException(422, 'A reactivation reason is required.');
        }

        if ($account->trashed()) {
            throw $this->conflict('An archived account must be restored separately.');
        }

        $accountType = $this->accountType($account);
        $status = $this->rawStatus($account);
        $activeStatus = $accountType === AccountSuspension::ACCOUNT_TYPE_CUSTOMER ? 'active' : 'approved';

        if ($status === $activeStatus && $account->current_suspension_id === null) {
            return ['changed' => false, 'suspension' => null, 'appeal' => null];
        }

        if ($status !== 'suspended') {
            throw $this->conflict('Only a suspended account can be reactivated.');
        }

        $suspension = $this->lockedCurrentSuspension($account, $accountType);
        if (! $suspension) {
            throw $this->conflict('The suspended account has no current suspension identity.');
        }

        $appeal = $this->lockedAppeal($suspension);
        $this->restoreLinkedEmployee($suspension);

        $account->forceFill([
            'status' => $activeStatus,
            'current_suspension_id' => null,
        ]);
        if ($account instanceof ShopOwner) {
            $account->suspension_reason = null;
        }
        $account->save();

        $suspension->forceFill([
            'ended_at' => now(),
            'ended_by_super_admin_id' => (int) $actor->getKey(),
            'end_reason' => $reason,
        ])->save();

        if ($appeal && in_array((string) $appeal->status, ['eligible', 'submitted'], true)) {
            $appeal->forceFill([
                'status' => 'superseded',
                'reviewer_id' => (int) $actor->getKey(),
                'reviewed_at' => now(),
            ])->save();
        }

        return [
            'changed' => true,
            'suspension' => $suspension->fresh(),
            'appeal' => $appeal?->fresh(),
        ];
    }

    public function accountType(User|ShopOwner $account): string
    {
        return $account instanceof User
            ? AccountSuspension::ACCOUNT_TYPE_CUSTOMER
            : AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER;
    }

    private function lockedCurrentSuspension(User|ShopOwner $account, string $accountType): ?AccountSuspension
    {
        $pointer = $account->current_suspension_id;
        if ($pointer === null) {
            return null;
        }

        $suspension = AccountSuspension::query()
            ->whereKey((int) $pointer)
            ->lockForUpdate()
            ->first();

        if (! $suspension
            || $suspension->account_type !== $accountType
            || (int) $suspension->account_id !== (int) $account->getKey()
            || ! $suspension->isCurrent()) {
            throw $this->conflict('The account current suspension reference is invalid.');
        }

        return $suspension;
    }

    private function lockedAppeal(AccountSuspension $suspension): ?SuspensionAppeal
    {
        return SuspensionAppeal::query()
            ->where('suspension_id', $suspension->getKey())
            ->lockForUpdate()
            ->first();
    }

    private function resolveEmployeeForSuspension(
        User|ShopOwner $account,
        AccountSuspension $suspension,
        string $reason,
    ): ?Employee {
        $email = strtolower(trim((string) $account->email));
        if ($email === '') {
            return null;
        }

        $employees = Employee::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($employees->count() === 0) {
            return null;
        }

        if ($employees->count() > 1) {
            throw $this->conflict('The account has ambiguous linked employee records.');
        }

        /** @var Employee $employee */
        $employee = $employees->first();
        $status = $this->rawStatus($employee);

        if (in_array($status, ['inactive', 'terminated'], true)) {
            return null;
        }

        if ($employee->privileged_suspension_id !== null) {
            throw $this->conflict('The linked employee has stale suspension provenance.');
        }

        if ($status === 'suspended') {
            throw $this->conflict('The linked employee is already suspended without this operation\'s provenance.');
        }

        if ($status !== 'active') {
            throw $this->conflict('The linked employee is not in a synchronizable state.');
        }

        // This field is intentionally excluded from $fillable. Only this
        // trusted primitive may claim privileged suspension provenance.
        $employee->forceFill([
            'status' => 'suspended',
            'suspension_reason' => $reason,
            'privileged_suspension_id' => (int) $suspension->getKey(),
        ])->save();

        return $employee;
    }

    private function restoreLinkedEmployee(AccountSuspension $suspension): void
    {
        if ($suspension->linked_employee_id === null) {
            return;
        }

        $employee = Employee::withTrashed()
            ->whereKey((int) $suspension->linked_employee_id)
            ->lockForUpdate()
            ->first();

        if (! $employee || $employee->trashed()) {
            throw $this->conflict('The linked employee cannot be restored because it is missing.');
        }

        if ($this->rawStatus($employee) !== 'suspended'
            || (int) $employee->privileged_suspension_id !== (int) $suspension->getKey()) {
            throw $this->conflict('The linked employee suspension provenance no longer matches.');
        }

        $priorStatus = (string) ($suspension->linked_employee_prior_status ?: 'active');
        if (! in_array($priorStatus, ['active', 'inactive', 'terminated'], true)) {
            throw $this->conflict('The linked employee prior status is invalid.');
        }

        $employee->forceFill([
            'status' => $priorStatus,
            'privileged_suspension_id' => null,
            'suspension_reason' => null,
        ])->save();
    }

    private function rawStatus(Model $model): string
    {
        return (string) $model->getRawOriginal('status');
    }

    private function conflict(string $message): HttpException
    {
        return new HttpException(409, $message);
    }
}
