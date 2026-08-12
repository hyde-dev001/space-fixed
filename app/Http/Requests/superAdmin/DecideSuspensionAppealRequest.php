<?php

declare(strict_types=1);

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

final class DecideSuspensionAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reviewer_notes')) {
            $this->merge(['reviewer_notes' => trim((string) $this->input('reviewer_notes'))]);
        }
    }

    public function rules(): array
    {
        return [
            'reviewer_notes' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
