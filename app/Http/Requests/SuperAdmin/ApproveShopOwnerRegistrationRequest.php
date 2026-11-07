<?php

declare(strict_types=1);

namespace App\Http\Requests\SuperAdmin;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;

final class ApproveShopOwnerRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super_admin') instanceof SuperAdmin;
    }

    /**
     * Approval metadata is reviewer-entered and must identify every pending
     * candidate that will be promoted.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'documents' => ['nullable', 'array'],
            'documents.*' => ['required', 'array'],
            'documents.*.id' => ['required', 'integer', 'distinct'],
            'documents.*.document_type' => ['required', 'string', 'max:120'],
            'documents.*.logical_slot' => ['required', 'string', 'max:120'],
            'documents.*.version_number' => ['required', 'integer', 'min:1'],
            'documents.*.issued_on' => ['nullable', 'date_format:Y-m-d'],
            'documents.*.expiration_mode' => ['required', 'in:dated,none'],
            'documents.*.expires_on' => ['nullable', 'date_format:Y-m-d'],
            'documents.*.viewed' => ['required', 'accepted'],
        ];
    }
}
