<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\PrivilegedSetupLinkMail;
use App\Models\PrivilegedSecurityToken;
use App\Models\SuperAdmin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class AdministratorIdentityService
{
    public function __construct(
        private readonly PrivilegedSecurityTokenService $tokens,
        private readonly PrivilegedSessionService $sessions,
        private readonly PrivilegedAudit $audit,
    ) {
    }

    public function suspend(Request $request, SuperAdmin $actor, int $targetId): SuperAdmin
    {
        $result = $this->mutate(
            $actor,
            $targetId,
            SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
            function (Collection $admins, SuperAdmin $lockedActor, SuperAdmin $target) use ($request): array {
                if ($target->status !== SuperAdmin::STATUS_ACTIVE) {
                    throw new RuntimeException('The administrator is not active.');
                }

                $this->assertFinalSuperAdminRemains($admins, $target, false);
                $target->forceFill([
                    'status' => SuperAdmin::STATUS_SUSPENDED,
                    'security_version' => (int) $target->security_version + 1,
                ])->save();
                $this->audit->privilegedAdministratorSuspended($request, $lockedActor, $target);

                return ['admin' => $target, 'setup_token' => null];
            },
        );

        return $this->finalize($result);
    }

    public function deactivate(Request $request, SuperAdmin $actor, int $targetId): SuperAdmin
    {
        $result = $this->mutate(
            $actor,
            $targetId,
            SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
            function (Collection $admins, SuperAdmin $lockedActor, SuperAdmin $target) use ($request): array {
                if (! in_array($target->status, [SuperAdmin::STATUS_ACTIVE, SuperAdmin::STATUS_SUSPENDED], true)) {
                    throw new RuntimeException('The administrator is already inactive.');
                }

                $this->assertFinalSuperAdminRemains($admins, $target, false);
                $target->forceFill([
                    'status' => SuperAdmin::STATUS_INACTIVE,
                    'security_version' => (int) $target->security_version + 1,
                ])->save();
                $this->audit->privilegedAdministratorDeactivated($request, $lockedActor, $target);

                return ['admin' => $target, 'setup_token' => null];
            },
        );

        return $this->finalize($result);
    }

    public function activate(Request $request, SuperAdmin $actor, int $targetId): SuperAdmin
    {
        $result = $this->mutate(
            $actor,
            $targetId,
            SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
            function (Collection $admins, SuperAdmin $lockedActor, SuperAdmin $target) use ($request): array {
                if ($target->status === SuperAdmin::STATUS_SUSPENDED) {
                    $target->forceFill([
                        'status' => SuperAdmin::STATUS_ACTIVE,
                        'security_version' => (int) $target->security_version + 1,
                    ])->save();
                    $this->audit->privilegedAdministratorActivated($request, $lockedActor, $target);

                    return ['admin' => $target, 'setup_token' => null];
                }

                if ($target->status !== SuperAdmin::STATUS_INACTIVE) {
                    throw new RuntimeException('The administrator cannot be activated from the current state.');
                }

                $target->forceFill([
                    'status' => SuperAdmin::STATUS_PENDING_SETUP,
                    'password' => Str::random(64),
                    'remember_token' => Str::random(60),
                    'password_changed_at' => now(),
                    'mfa_secret' => null,
                    'mfa_recovery_codes' => null,
                    'mfa_confirmed_at' => null,
                    'mfa_last_used_timestep' => null,
                    'security_version' => (int) $target->security_version + 1,
                ])->save();
                $issued = $this->tokens->issue($target, PrivilegedSecurityToken::PURPOSE_SETUP, $lockedActor);
                $this->audit->privilegedAdministratorReturnedToSetup($request, $lockedActor, $target);

                return ['admin' => $target, 'setup_token' => $issued['raw_token']];
            },
        );

        return $this->finalize($result);
    }

    public function updateRole(Request $request, SuperAdmin $actor, int $targetId, string $role): SuperAdmin
    {
        if (! in_array($role, [SuperAdmin::ROLE_ADMIN, SuperAdmin::ROLE_SUPER_ADMIN], true)) {
            throw new InvalidArgumentException('The administrator role is invalid.');
        }

        $result = $this->mutate(
            $actor,
            $targetId,
            SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
            function (Collection $admins, SuperAdmin $lockedActor, SuperAdmin $target) use ($request, $role): array {
                if ($target->role === $role) {
                    return ['admin' => $target, 'setup_token' => null];
                }

                $remainsQualified = $role === SuperAdmin::ROLE_SUPER_ADMIN
                    && $target->status === SuperAdmin::STATUS_ACTIVE
                    && $target->hasCompletedMfaSetup();
                $this->assertFinalSuperAdminRemains($admins, $target, $remainsQualified);
                $fromRole = (string) $target->role;
                $target->forceFill([
                    'role' => $role,
                    'security_version' => (int) $target->security_version + 1,
                ])->save();
                $this->audit->privilegedAdministratorRoleChanged(
                    $request,
                    $lockedActor,
                    $target,
                    $fromRole,
                    $role,
                );

                return ['admin' => $target, 'setup_token' => null];
            },
        );

        return $this->finalize($result);
    }

    public function resetMfa(Request $request, SuperAdmin $actor, int $targetId): SuperAdmin
    {
        $result = $this->mutate(
            $actor,
            $targetId,
            SuperAdmin::CAP_MANAGE_PLATFORM_SECURITY,
            function (Collection $admins, SuperAdmin $lockedActor, SuperAdmin $target) use ($request): array {
                if ($target->status !== SuperAdmin::STATUS_ACTIVE || ! $target->hasCompletedMfaSetup()) {
                    throw new RuntimeException('The administrator MFA state cannot be reset.');
                }

                $this->assertFinalSuperAdminRemains($admins, $target, false);
                $this->clearMfa($target);
                $this->audit->privilegedAdministratorMfaReset($request, $lockedActor, $target);

                return ['admin' => $target, 'setup_token' => null];
            },
        );

        return $this->finalize($result);
    }

    public function resetOwnMfa(Request $request, SuperAdmin $actor): SuperAdmin
    {
        $result = DB::transaction(function () use ($request, $actor): array {
            $admins = SuperAdmin::query()->orderBy('id')->lockForUpdate()->get();
            $lockedActor = $admins->first(fn (SuperAdmin $candidate): bool => (int) $candidate->getKey() === (int) $actor->getKey());

            if (! $lockedActor instanceof SuperAdmin
                || ! $lockedActor->isActive()
                || ! $lockedActor->hasCapability(SuperAdmin::CAP_MANAGE_OWN_SECURITY)
                || ! $lockedActor->hasCompletedMfaSetup()) {
                throw new AuthorizationException('The security operation is not permitted.');
            }

            $this->assertFinalSuperAdminRemains($admins, $lockedActor, false);
            $this->clearMfa($lockedActor);
            $this->audit->privilegedOwnMfaReset($request, $lockedActor);

            return ['admin' => $lockedActor, 'setup_token' => null];
        });

        return $this->finalize($result);
    }

    /** @param callable(Collection, SuperAdmin, SuperAdmin): array{admin: SuperAdmin, setup_token: string|null} $mutation
     *  @return array{admin: SuperAdmin, setup_token: string|null}
     */
    private function mutate(
        SuperAdmin $actor,
        int $targetId,
        string $capability,
        callable $mutation,
    ): array {
        return DB::transaction(function () use ($actor, $targetId, $capability, $mutation): array {
            $admins = SuperAdmin::query()->orderBy('id')->lockForUpdate()->get();
            $lockedActor = $admins->first(fn (SuperAdmin $candidate): bool => (int) $candidate->getKey() === (int) $actor->getKey());
            $target = $admins->first(fn (SuperAdmin $candidate): bool => (int) $candidate->getKey() === $targetId);

            if (! $lockedActor instanceof SuperAdmin || ! $lockedActor->isActive()) {
                throw new AuthorizationException('The security operation is not permitted.');
            }

            if (! $lockedActor->hasCapability($capability)) {
                throw new AuthorizationException('The security operation is not permitted.');
            }

            if (! $target instanceof SuperAdmin) {
                throw new RuntimeException('The administrator was not found.');
            }

            if ((int) $target->getKey() === (int) $lockedActor->getKey()) {
                throw new AuthorizationException('Self-management is not permitted.');
            }

            return $mutation($admins, $lockedActor, $target);
        });
    }

    /** @return array{admin: SuperAdmin, setup_token: string|null} */
    private function finalize(array $result): SuperAdmin
    {
        $admin = $result['admin'];
        $this->sessions->invalidateAllAfterCommit($admin);

        if (is_string($result['setup_token'])) {
            try {
                Mail::to((string) $admin->email)->queue(new PrivilegedSetupLinkMail(
                    trim($admin->first_name.' '.$admin->last_name),
                    (string) $admin->email,
                    $result['setup_token'],
                ));
            } catch (\Throwable) {
                // The pending account remains resumable if delivery handoff fails.
            }
        }

        return $admin;
    }

    private function clearMfa(SuperAdmin $admin): void
    {
        $admin->forceFill([
            'mfa_secret' => null,
            'mfa_recovery_codes' => null,
            'mfa_confirmed_at' => null,
            'mfa_last_used_timestep' => null,
            'security_version' => (int) $admin->security_version + 1,
        ])->save();
    }

    private function assertFinalSuperAdminRemains(
        Collection $admins,
        SuperAdmin $target,
        bool $targetRemainsQualified,
    ): void {
        if (! $this->qualifiesAsFinalSuperAdmin($target) || $targetRemainsQualified) {
            return;
        }

        $otherQualified = $admins->contains(
            fn (SuperAdmin $candidate): bool => (int) $candidate->getKey() !== (int) $target->getKey()
                && $this->qualifiesAsFinalSuperAdmin($candidate),
        );

        if (! $otherQualified) {
            throw new AuthorizationException('The final active Super Admin cannot be removed.');
        }
    }

    private function qualifiesAsFinalSuperAdmin(SuperAdmin $admin): bool
    {
        return $admin->role === SuperAdmin::ROLE_SUPER_ADMIN
            && $admin->status === SuperAdmin::STATUS_ACTIVE
            && $admin->hasCompletedMfaSetup();
    }
}
