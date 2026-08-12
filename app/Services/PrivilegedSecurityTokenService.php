<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PrivilegedSecurityToken;
use App\Models\SuperAdmin;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PrivilegedSecurityTokenService
{
    /** @return array{raw_token: string, token: PrivilegedSecurityToken} */
    public function issue(SuperAdmin $admin, string $purpose, ?SuperAdmin $creator): array
    {
        $this->assertPurpose($purpose);

        return DB::transaction(function () use ($admin, $purpose, $creator): array {
            PrivilegedSecurityToken::query()
                ->where('super_admin_id', $admin->id)
                ->where('purpose', $purpose)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $token = PrivilegedSecurityToken::create([
                'super_admin_id' => $admin->id,
                'created_by_super_admin_id' => $creator?->id,
                'purpose' => $purpose,
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => now()->addMinutes($this->ttlMinutes($purpose)),
            ]);

            return [
                'raw_token' => $rawToken,
                'token' => $token,
            ];
        });
    }

    /** @return array{token_id: int, subject_id: int, purpose: string, expires_at: \Illuminate\Support\Carbon}
     */
    public function authorize(string $rawToken, string $purpose): array
    {
        $this->assertPurpose($purpose);
        $token = PrivilegedSecurityToken::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->where('purpose', $purpose)
            ->first();

        if (! $token instanceof PrivilegedSecurityToken) {
            throw new InvalidArgumentException('Invalid security token.');
        }

        $subject = SuperAdmin::query()->find($token->super_admin_id);
        $this->assertUsable($token, $subject, $purpose);

        return $this->authorizationFor($token);
    }

    public function consume(string $rawToken, string $purpose, Closure $mutation): mixed
    {
        $this->assertPurpose($purpose);

        return DB::transaction(function () use ($rawToken, $purpose, $mutation): mixed {
            $token = PrivilegedSecurityToken::query()
                ->where('token_hash', hash('sha256', $rawToken))
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();
            $subject = $token instanceof PrivilegedSecurityToken
                ? SuperAdmin::query()->lockForUpdate()->find($token->super_admin_id)
                : null;

            $this->assertUsable($token, $subject, $purpose);
            $result = $mutation($subject);
            $token->forceFill(['used_at' => now()])->save();

            return $result;
        });
    }

    public function consumeAuthorized(int $tokenId, int $subjectId, string $purpose, Closure $mutation): mixed
    {
        $this->assertPurpose($purpose);

        return DB::transaction(function () use ($tokenId, $subjectId, $purpose, $mutation): mixed {
            $token = PrivilegedSecurityToken::query()->lockForUpdate()->find($tokenId);
            $subject = $token instanceof PrivilegedSecurityToken
                ? SuperAdmin::query()->lockForUpdate()->find($token->super_admin_id)
                : null;

            if ($token?->super_admin_id !== $subjectId) {
                throw new InvalidArgumentException('Invalid security token.');
            }

            $this->assertUsable($token, $subject, $purpose);
            $result = $mutation($subject);
            $token->forceFill(['used_at' => now()])->save();

            return $result;
        });
    }

    private function assertPurpose(string $purpose): void
    {
        if (! in_array($purpose, [
            PrivilegedSecurityToken::PURPOSE_SETUP,
            PrivilegedSecurityToken::PURPOSE_PASSWORD_RESET,
            PrivilegedSecurityToken::PURPOSE_RECOVERY_ACK,
        ], true)) {
            throw new InvalidArgumentException('Invalid security token.');
        }
    }

    private function assertUsable(?PrivilegedSecurityToken $token, ?SuperAdmin $subject, string $purpose): void
    {
        if (! $token instanceof PrivilegedSecurityToken
            || ! $subject instanceof SuperAdmin
            || $token->purpose !== $purpose
            || $token->used_at !== null
            || $token->expires_at === null
            || $token->expires_at->isPast()) {
            throw new InvalidArgumentException('Invalid security token.');
        }

        $validSubjectState = match ($purpose) {
            PrivilegedSecurityToken::PURPOSE_SETUP => $subject->status === SuperAdmin::STATUS_PENDING_SETUP
                && ! $subject->hasCompletedMfaSetup(),
            PrivilegedSecurityToken::PURPOSE_PASSWORD_RESET => $subject->status === SuperAdmin::STATUS_ACTIVE,
            PrivilegedSecurityToken::PURPOSE_RECOVERY_ACK => in_array($subject->status, [
                SuperAdmin::STATUS_PENDING_SETUP,
                SuperAdmin::STATUS_ACTIVE,
            ], true) && $subject->mfa_secret !== null && $subject->mfa_recovery_codes !== null,
        };

        if (! $validSubjectState) {
            throw new InvalidArgumentException('Invalid security token.');
        }
    }

    /** @return array{token_id: int, subject_id: int, purpose: string, expires_at: \Illuminate\Support\Carbon} */
    private function authorizationFor(PrivilegedSecurityToken $token): array
    {
        return [
            'token_id' => (int) $token->id,
            'subject_id' => (int) $token->super_admin_id,
            'purpose' => $token->purpose,
            'expires_at' => $token->expires_at,
        ];
    }

    private function ttlMinutes(string $purpose): int
    {
        return match ($purpose) {
            PrivilegedSecurityToken::PURPOSE_SETUP => (int) config('privileged_security.setup_token_minutes', 1440),
            PrivilegedSecurityToken::PURPOSE_PASSWORD_RESET => (int) config('privileged_security.reset_token_minutes', 60),
            PrivilegedSecurityToken::PURPOSE_RECOVERY_ACK => (int) config('privileged_security.recovery_ack_minutes', 15),
        };
    }
}
