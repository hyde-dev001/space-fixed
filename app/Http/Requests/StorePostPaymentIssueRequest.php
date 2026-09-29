<?php

namespace App\Http\Requests;

use App\Models\SupplierAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostPaymentIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'reported_quantity' => ['required', 'integer', 'min:1'],
            'reason_category' => ['required', 'string', Rule::in(SupplierAdjustment::REASON_CATEGORIES)],
            'inventory_notes' => ['required', 'string', 'max:2000'],
            'defect_evidence' => ['required', 'array', 'min:1', 'max:5'],
            'defect_evidence.*' => [
                'file',
                'mimetypes:image/jpeg,image/png,image/webp',
                'mimes:jpg,jpeg,png,webp',
                'max:10240',
            ],
        ];
    }
}
