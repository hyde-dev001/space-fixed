<?php

declare(strict_types=1);

namespace App\Http\Requests\SuperAdmin;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;

final class ReviewShopDocumentRenewalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super_admin') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        if ($this->routeIs('admin.document-renewals.reject')) {
            return [
                'rejection_reason' => ['required', 'string', 'min:3', 'max:500'],
            ];
        }

        return [
            'document_type' => ['required', 'string', 'max:120'],
            'logical_slot' => ['required', 'string', 'max:120'],
            'version_number' => ['required', 'integer', 'min:1'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'expiration_mode' => ['required', 'in:dated,none'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'viewed' => ['required', 'accepted'],
        ];
    }
}
