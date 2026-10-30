<?php

namespace App\Http\Requests\Finance;

use App\Models\SupplierPaymentAttempt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitiateSupplierPaymentRequest extends FormRequest
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
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
