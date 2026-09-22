<?php

namespace App\Exceptions;

use RuntimeException;

final class CodEligibilityException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message,
    ) {
        parent::__construct($message);
    }
}
