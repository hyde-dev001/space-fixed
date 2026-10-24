<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\EmployeeInvitation;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EmployeeAccountLinkService
{
    public function issueForEmployeeId(int $employeeId, User|ShopOwner $actor, bool $resetPassword): array
    {
        $employee = $this->employeeForActor($employeeId, $actor);

        return $this->issueSetupLink($employee, $actor, $resetPassword);
    }

    public function issueForUserId(int $userId, User|ShopOwner $actor, bool $resetPassword): array
    {
        $shopOwnerId = $this->shopOwnerId($actor);
        $user = User::query()
            ->whereKey($userId)
            ->where('shop_owner_id', $shopOwnerId)
            ->firstOrFail();
        $employee = Employee::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereRaw('LOWER(email) = ?', [strtolower(trim((string) $user->email))])
            ->firstOrFail();

        return $this->issueSetupLink($employee, $actor, $resetPassword);
    }

    public function sendToPersonalEmailForEmployeeId(
        int $employeeId,
        User|ShopOwner $actor,
        string $destination,
    ): array {
        $employee = $this->employeeForActor($employeeId, $actor);

        return $this->sendToPersonalEmail($employee, $actor, $destination);
    }

    public function sendToPersonalEmailForUserId(
        int $userId,
        User|ShopOwner $actor,
        string $destination,
    ): array {
        $shopOwnerId = $this->shopOwnerId($actor);
        $user = User::query()
            ->whereKey($userId)
            ->where('shop_owner_id', $shopOwnerId)
            ->firstOrFail();
        $employee = Employee::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereRaw('LOWER(email) = ?', [strtolower(trim((string) $user->email))])
            ->firstOrFail();

        return $this->sendToPersonalEmail($employee, $actor, $destination);
    }

    public function resendToWorkEmailForEmployeeId(int $employeeId, User|ShopOwner $actor): array
    {
        $employee = $this->employeeForActor($employeeId, $actor);
        $user = $this->linkedUser($employee, $actor);

        if (! $user->invite_token || ! $user->invite_expires_at) {
            throw ValidationException::withMessages([
                'invitation' => 'No pending invitation found.',
            ]);
        }

        if ($user->password !== null) {
            throw ValidationException::withMessages([
                'invitation' => 'User has already accepted the invitation.',
            ]);
        }

        if ($user->invite_expires_at && now()->greaterThan($user->invite_expires_at)) {
            throw ValidationException::withMessages([
                'invitation' => 'Invitation has expired. Please generate a new invitation.',
            ]);
        }

        $link = $this->linkPayload($user);
        Mail::to($user->email)->send(new EmployeeInvitation($user, $link['invite_url']));
        $this->writeAudit($employee, $user, $actor, 'employee_invitation_resent');

        return $link + [
            'email' => $user->email,
            'message' => 'Invitation email resent successfully.',
        ];
    }

    /**
     * Issue the same employee-specific link for both reset and setup flows.
     */
    private function issueSetupLink(Employee $employee, User|ShopOwner $actor, bool $resetPassword): array
    {
        $user = $this->linkedUser($employee, $actor);
        $token = Str::random(64);
        $expiresAt = Carbon::now()->addDays(7);

        $issued = DB::transaction(function () use ($employee, $actor, $user, $token, $expiresAt, $resetPassword): User {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->where('shop_owner_id', $this->shopOwnerId($actor))
                ->lockForUpdate()
                ->firstOrFail();

            $attributes = [
                'invite_token' => $token,
                'invite_expires_at' => $expiresAt,
                'invited_at' => now(),
                'invited_by' => $actor->getKey(),
            ];
            if ($resetPassword) {
                $attributes += [
                    'password' => null,
                    'force_password_change' => true,
                ];
            }

            $lockedUser->forceFill($attributes)->save();
            $this->writeAudit(
                $employee,
                $lockedUser,
                $actor,
                $resetPassword ? 'employee_password_reset' : 'employee_invitation_regenerated',
            );

            return $lockedUser;
        });

        return $this->linkPayload($issued);
    }

    private function sendToPersonalEmail(
        Employee $employee,
        User|ShopOwner $actor,
        string $destination,
    ): array {
        $user = $this->linkedUser($employee, $actor);

        if (! $user->invite_token || ! $user->invite_expires_at) {
            throw ValidationException::withMessages([
                'invitation' => 'No active invitation found. Please generate the invitation first.',
            ]);
        }

        if (now()->greaterThan($user->invite_expires_at)) {
            throw ValidationException::withMessages([
                'invitation' => 'Invitation has expired. Please generate a new invitation.',
            ]);
        }

        $link = $this->linkPayload($user);
        $user->loadMissing('shopOwner');
        Mail::to($destination)->send(new EmployeeInvitation($user, $link['invite_url']));
        $this->writeAudit($employee, $user, $actor, 'employee_invitation_personal_email_sent');

        return $link + [
            'message' => 'Invitation email sent successfully.',
        ];
    }

    private function employeeForActor(int $employeeId, User|ShopOwner $actor): Employee
    {
        return Employee::query()
            ->whereKey($employeeId)
            ->where('shop_owner_id', $this->shopOwnerId($actor))
            ->firstOrFail();
    }

    private function linkedUser(Employee $employee, User|ShopOwner $actor): User
    {
        if ((int) $employee->shop_owner_id !== $this->shopOwnerId($actor)) {
            throw (new ModelNotFoundException)->setModel(Employee::class, [$employee->getKey()]);
        }

        $user = User::query()
            ->where('shop_owner_id', $this->shopOwnerId($actor))
            ->whereRaw('LOWER(email) = ?', [strtolower(trim((string) $employee->email))])
            ->first();

        if (! $user) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }

        $sameUserAccount = $actor instanceof User && (int) $user->getKey() === (int) $actor->getKey();
        if ($sameUserAccount
            || strcasecmp((string) $user->email, (string) ($actor->email ?? '')) === 0) {
            throw ValidationException::withMessages([
                'employee' => 'You cannot reset the account you are currently using.',
            ]);
        }

        return $user;
    }

    private function shopOwnerId(User|ShopOwner $actor): int
    {
        return (int) ($actor instanceof ShopOwner ? $actor->getKey() : $actor->shop_owner_id);
    }

    /** @return array{invite_url: string, invite_expires_at: string, work_email: string, employee_name: string} */
    private function linkPayload(User $user): array
    {
        return [
            'invite_url' => url('/accept-invitation/'.$user->invite_token),
            'invite_expires_at' => $user->invite_expires_at->toIso8601String(),
            'work_email' => (string) $user->email,
            'employee_name' => (string) $user->name,
        ];
    }

    private function writeAudit(
        Employee $employee,
        User $user,
        User|ShopOwner $actor,
        string $action,
    ): void {
        AuditLog::query()->create([
            'shop_owner_id' => $employee->shop_owner_id,
            'user_id' => $actor instanceof User ? $actor->getKey() : null,
            'actor_user_id' => $actor->getKey(),
            'action' => $action,
            'target_type' => Employee::class,
            'target_id' => $employee->getKey(),
            'metadata' => [
                'account_id' => $user->getKey(),
            ],
        ]);
    }
}
