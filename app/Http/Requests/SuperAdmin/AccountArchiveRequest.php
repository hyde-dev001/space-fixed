<?php

declare(strict_types=1);

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

final class AccountArchiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('archive_reason')) {
            $this->merge(['archive_reason' => trim((string) $this->input('archive_reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'archive_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
