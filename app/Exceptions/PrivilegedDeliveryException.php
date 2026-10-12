<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class PrivilegedDeliveryException extends RuntimeException
{
    public static function fromTransport(Throwable $exception): self
    {
        return new self('Privileged workflow delivery failed.');
    }
}
