<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\Rules\Password;

final class EmployeePasswordRules
{
    /** @return list<mixed> */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            'confirmed',
            Password::min(8)->mixedCase()->numbers()->symbols(),
        ];
    }
}
