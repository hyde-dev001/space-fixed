<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class RecordHandoffProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (Auth::guard('shop_owner')->check()) {
            return true;
        }

        return (bool) Auth::guard('user')->user()?->can('record-logistics-proof');
    }

    public function rules(): array
    {
        return [
            'handoff_type' => ['required', 'in:pickup,delivery,receive'],
            'proof_type' => ['required', 'in:photo,signature,qr,staff_confirmation,customer_confirmation,courier_receipt,tracking_confirmation'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'confirmed_by_type' => ['nullable', 'string', 'max:255'],
            'confirmed_by_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
