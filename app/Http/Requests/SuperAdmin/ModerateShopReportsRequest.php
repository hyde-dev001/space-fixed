<?php

declare(strict_types=1);

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ModerateShopReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('admin_notes')) {
            $this->merge(['admin_notes' => trim((string) $this->input('admin_notes'))]);
        }
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['dismiss', 'warn', 'suspend'])],
            'report_ids' => ['required', 'array', 'min:1', 'max:100'],
            'report_ids.*' => ['integer', 'distinct', 'min:1'],
            'admin_notes' => ['nullable', 'string', 'max:2000', 'required_if:action,suspend'],
            'decision_key' => ['prohibited'],
        ];
    }
}
