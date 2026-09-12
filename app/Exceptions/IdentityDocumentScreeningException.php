<?php

namespace App\Exceptions;

use RuntimeException;

final class IdentityDocumentScreeningException extends RuntimeException
{
    /**
     * @param array<string, mixed> $decision
     */
    public function __construct(
        public readonly array $decision,
    ) {
        parent::__construct('Identity document screening could not be completed.');
    }
}
