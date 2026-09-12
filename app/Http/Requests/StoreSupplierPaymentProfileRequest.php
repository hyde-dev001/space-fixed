<?php

namespace App\Http\Requests;

use App\Models\SupplierPaymentProfile;
use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierPaymentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $supplierId = (int) $this->route('id');
        $profileExists = SupplierPaymentProfile::query()
            ->where('supplier_id', $supplierId)
            ->exists();

        return [
            'destination_type' => ['required', 'string', 'max:32'],
            'bank_name' => ['required', 'string', 'max:120'],
            'bank_code' => ['required', 'string', 'max:64'],
            'account_name' => ['required', 'string', 'max:160'],
            'account_number' => [$profileExists ? 'nullable' : 'required', 'string', 'max:64'],
        ];
    }
}
