<?php

declare(strict_types=1);

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

final class RejectShopOwnerRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super_admin') !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('rejection_reason')) {
            $this->merge([
                'rejection_reason' => trim((string) $this->input('rejection_reason')),
            ]);
        }
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['bail', 'required', 'string', 'max:500'],
        ];
    }
}
