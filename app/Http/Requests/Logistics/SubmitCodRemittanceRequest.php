<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class SubmitCodRemittanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'collection_ids' => ['required', 'array', 'min:1', 'max:100'],
            'collection_ids.*' => ['integer', 'distinct', 'exists:cod_collections,id'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ];
    }
}
