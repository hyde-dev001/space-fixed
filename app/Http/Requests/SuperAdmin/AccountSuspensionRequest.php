<?php

declare(strict_types=1);

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

final class AccountSuspensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('suspension_reason')) {
            $this->merge(['suspension_reason' => trim((string) $this->input('suspension_reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'suspension_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
