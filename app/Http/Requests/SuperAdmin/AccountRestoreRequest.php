<?php

declare(strict_types=1);

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

final class AccountRestoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('restore_reason')) {
            $this->merge(['restore_reason' => trim((string) $this->input('restore_reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'restore_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
