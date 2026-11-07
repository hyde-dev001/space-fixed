<?php

declare(strict_types=1);

namespace App\Casts;

use App\Enums\EmployeeStatus;
use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Keep legacy employee account-state values readable until reconciliation is complete.
 */
final class EmployeeStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): EmployeeStatus|string|null
    {
        if ($value instanceof EmployeeStatus) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if ($value === null) {
            return null;
        }

        return EmployeeStatus::tryFrom(strtolower(trim((string) $value))) ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value instanceof BackedEnum) {
            return [$key => $value->value];
        }

        return [$key => $value];
    }
}
