<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class EmployeeInvitationService
{
    public const TTL_DAYS = 7;

    /**
     * Issue a single-use setup token. Only the returned plaintext token may be
     * placed in the handoff URL; the database stores its SHA-256 digest.
     *
     * @return array{token: string, expires_at: CarbonInterface}
     */
    public function issue(User $user, ?int $invitedBy = null, bool $resetPassword = false): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addDays(self::TTL_DAYS);

        $attributes = [
            'invite_token' => null,
            'invite_token_hash' => hash('sha256', $token),
            'invite_expires_at' => $expiresAt,
            'invited_at' => now(),
            'invited_by' => $invitedBy,
        ];

        if ($resetPassword) {
            $attributes['password'] = null;
            $attributes['force_password_change'] = true;
        }

        $user->forceFill($attributes)->save();

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    public function find(string $token, bool $lock = false): ?User
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $hash = hash('sha256', $token);
        $query = User::query()
            ->whereNotNull('shop_owner_id')
            ->where(function (Builder $query) use ($token, $hash): void {
                $query->where('invite_token_hash', $hash)
                    // Bounded compatibility for links created before the migration.
                    ->orWhere('invite_token', $token);
            });

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function clear(User $user): void
    {
        $user->forceFill([
            'invite_token' => null,
            'invite_token_hash' => null,
            'invite_expires_at' => null,
            'invited_at' => null,
            'invited_by' => null,
        ])->save();
    }
}
