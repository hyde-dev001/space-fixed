<?php

namespace App\Http\Requests;

use App\Models\SupplierPaymentProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierPaymentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $supplierId = $this->route('id') ? (int) $this->route('id') : null;

        return self::profileRules($supplierId, $this->input('destination_type'));
    }

    /** @return array<string, mixed> */
    public static function profileRules(?int $supplierId = null, ?string $destinationType = null): array
    {
        $profileExists = SupplierPaymentProfile::query()
            ->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
            ->when($supplierId === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->exists();

        return [
            'destination_type' => ['required', 'string', Rule::in(SupplierPaymentProfile::supportedDestinationTypes())],
            'wallet_provider' => [
                'nullable',
                'string',
                'max:120',
                'required_if:destination_type,' . SupplierPaymentProfile::DESTINATION_E_WALLET,
                'prohibited_if:destination_type,' . SupplierPaymentProfile::DESTINATION_BANK_ACCOUNT,
            ],
            'bank_name' => [
                'nullable',
                'string',
                'max:120',
                'required_if:destination_type,' . SupplierPaymentProfile::DESTINATION_BANK_ACCOUNT,
                'prohibited_if:destination_type,' . SupplierPaymentProfile::DESTINATION_E_WALLET,
            ],
            'bank_code' => [
                'nullable',
                'string',
                'max:64',
                'required_if:destination_type,' . SupplierPaymentProfile::DESTINATION_BANK_ACCOUNT,
                'prohibited_if:destination_type,' . SupplierPaymentProfile::DESTINATION_E_WALLET,
            ],
            'account_name' => ['required', 'string', 'max:160'],
            'account_number' => [
                'nullable',
                'string',
                'max:64',
                Rule::requiredIf(fn (): bool => $destinationType === SupplierPaymentProfile::DESTINATION_BANK_ACCOUNT && ! $profileExists),
                'prohibited_if:destination_type,' . SupplierPaymentProfile::DESTINATION_E_WALLET,
            ],
            'account_identifier' => [
                'nullable',
                'string',
                'max:64',
                Rule::requiredIf(fn (): bool => $destinationType === SupplierPaymentProfile::DESTINATION_E_WALLET && ! $profileExists),
                'prohibited_if:destination_type,' . SupplierPaymentProfile::DESTINATION_BANK_ACCOUNT,
            ],
        ];
    }
}
