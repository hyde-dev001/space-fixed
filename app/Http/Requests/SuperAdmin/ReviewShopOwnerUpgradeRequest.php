<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class ReviewShopOwnerUpgradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = Auth::guard('super_admin')->user();

        if (! $admin) {
            return false;
        }

        $status = $admin->getRawOriginal('status') ?? $admin->getAttribute('status');
        if ($status instanceof \BackedEnum) {
            $status = $status->value;
        }

        return (string) $status === 'active';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['approved', 'rejected']),
            ],
            'decision_reason' => [
                'nullable',
                'string',
                'max:1000',
                'required_if:decision,rejected',
            ],
            'documents' => [
                'required_if:decision,approved',
                'array',
                'min:1',
            ],
            'documents.*' => [
                'required',
                'array',
            ],
            'documents.*.id' => [
                'required',
                'integer',
                'min:1',
                'distinct',
            ],
            'documents.*.viewed' => [
                'required',
                'accepted',
            ],
            'status' => [
                'sometimes',
                'string',
                Rule::in(['pending', 'approved', 'rejected', 'superseded']),
            ],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('search')) {
            $this->merge(['search' => trim((string) $this->input('search'))]);
        }
    }
}
