<?php

namespace App\Http\Requests\ShopOwner;

use App\Models\ShopOwner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreShopOwnerUpgradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $owner = Auth::guard('shop_owner')->user();

        if (! $owner instanceof ShopOwner) {
            return false;
        }

        $status = $owner->getRawOriginal('status') ?? $owner->getAttribute('status');
        if ($status instanceof \BackedEnum) {
            $status = $status->value;
        }

        return (string) $status === 'approved';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'requested_registration_type' => [
                'required',
                'string',
                Rule::in(['individual', 'company']),
            ],
            'requested_business_type' => [
                'required',
                'string',
                Rule::in(['retail', 'repair', 'both']),
            ],
            'documents' => ['sometimes', 'array'],
            'documents.*' => [
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'mimetypes:application/pdf,image/jpeg,image/png',
                'max:10240',
            ],
            'reuse_document_ids' => ['sometimes', 'array'],
            'reuse_document_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }
}
