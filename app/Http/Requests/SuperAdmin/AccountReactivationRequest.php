<?php

declare(strict_types=1);

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

final class AccountReactivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reactivation_reason')) {
            $this->merge(['reactivation_reason' => trim((string) $this->input('reactivation_reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'reactivation_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
