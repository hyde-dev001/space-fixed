<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmCodRemittanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'received_amount' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'dispute_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
