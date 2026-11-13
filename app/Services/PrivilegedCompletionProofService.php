<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PrivilegedSecurityToken;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Throwable;

final class PrivilegedCompletionProofService
{
    public function issue(
        int $tokenId,
        int $subjectId,
        string $purpose,
        CarbonInterface $tokenExpiresAt,
    ): string {
        $this->assertPurpose($purpose);

        if ($tokenId < 1 || $subjectId < 1) {
            throw new InvalidArgumentException('Privileged completion proof identifiers are invalid.');
        }

        $now = now();
        $expiresAt = min(
            $tokenExpiresAt->getTimestamp(),
            $now->copy()->addMinutes($this->lifetimeMinutes())->timestamp,
        );

        return Crypt::encryptString(json_encode([
            'token_id' => $tokenId,
            'subject_id' => $subjectId,
            'purpose' => $purpose,
            'issued_at' => $now->timestamp,
            'expires_at' => $expiresAt,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{token_id: int, subject_id: int} */
    public function authorization(string $proof, string $purpose): array
    {
        $this->assertPurpose($purpose);

        try {
            $payload = json_decode(Crypt::decryptString($proof), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($payload)
                || ! $this->isPositiveInteger($payload['token_id'] ?? null)
                || ! $this->isPositiveInteger($payload['subject_id'] ?? null)
                || ($payload['purpose'] ?? null) !== $purpose
                || ! is_int($payload['issued_at'] ?? null)
                || ! is_int($payload['expires_at'] ?? null)
            ) {
                throw new InvalidArgumentException;
            }

            $now = now()->timestamp;
            $issuedAt = $payload['issued_at'];
            $expiresAt = $payload['expires_at'];

            if ($issuedAt > $now
                || $expiresAt <= $now
                || $expiresAt <= $issuedAt
                || $expiresAt > $issuedAt + ($this->lifetimeMinutes() * 60)
            ) {
                throw new InvalidArgumentException;
            }

            return [
                'token_id' => $payload['token_id'],
                'subject_id' => $payload['subject_id'],
            ];
        } catch (Throwable) {
            throw new InvalidArgumentException('The privileged completion proof is invalid or expired.');
        }
    }

    private function assertPurpose(string $purpose): void
    {
        if (! in_array($purpose, [
            PrivilegedSecurityToken::PURPOSE_SETUP,
            PrivilegedSecurityToken::PURPOSE_PASSWORD_RESET,
        ], true)) {
            throw new InvalidArgumentException('Invalid privileged completion proof purpose.');
        }
    }

    private function lifetimeMinutes(): int
    {
        return max(1, (int) config('privileged_security.token_authorization_minutes', 15));
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }
}
