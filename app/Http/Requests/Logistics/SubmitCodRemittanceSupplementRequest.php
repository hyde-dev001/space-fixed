<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class SubmitCodRemittanceSupplementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ];
    }
}
