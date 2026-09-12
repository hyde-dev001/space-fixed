<?php

namespace App\Http\Requests\Finance;

use App\Models\SupplierPaymentAttempt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitSupplierPaymentProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', 'string', Rule::in(SupplierPaymentAttempt::PAYMENT_METHODS)],
            'amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'external_transaction_reference' => ['required', 'string', 'max:160'],
            'externally_paid_at' => ['required', 'date'],
            'finance_note' => ['nullable', 'string', 'max:2000'],
            'payment_proof' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,application/pdf',
                'mimes:jpg,jpeg,png,pdf',
                'max:10240',
            ],
        ];
    }
}
