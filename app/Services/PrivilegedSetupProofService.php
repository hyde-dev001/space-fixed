<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PrivilegedSecurityToken;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class PrivilegedSetupProofService
{
    public function __construct(
        private readonly PrivilegedCompletionProofService $proofs,
    ) {
    }

    public function issue(int $tokenId, int $subjectId, CarbonInterface $tokenExpiresAt): string
    {
        return $this->proofs->issue(
            tokenId: $tokenId,
            subjectId: $subjectId,
            purpose: PrivilegedSecurityToken::PURPOSE_SETUP,
            tokenExpiresAt: $tokenExpiresAt,
        );
    }

    /** @return array{token_id: int, subject_id: int} */
    public function authorization(string $proof): array
    {
        try {
            return $this->proofs->authorization($proof, PrivilegedSecurityToken::PURPOSE_SETUP);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('The privileged setup proof is invalid or expired.', previous: $exception);
        }
    }
}
