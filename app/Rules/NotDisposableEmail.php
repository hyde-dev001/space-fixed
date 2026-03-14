<?php

namespace App\Rules;

use App\Services\DisposableEmailService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NotDisposableEmail implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $service = new DisposableEmailService();

        if ($service->isDisposable((string) $value)) {
            $fail('Disposable or temporary email addresses are not allowed. Please use a real email address.');
        }
    }
}
