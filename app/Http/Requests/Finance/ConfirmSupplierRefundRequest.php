<?php

namespace App\Http\Requests\Finance;

use App\Models\SupplierPaymentAttempt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmSupplierRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'payment_method' => ['required', Rule::in(SupplierPaymentAttempt::MANUAL_PAYMENT_METHODS)],
            'external_transaction_reference' => ['required', 'string', 'max:160'],
            'received_at' => ['required', 'date'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'finance_confirmation_proof' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
                'max:10240',
            ],
        ];
    }
}
