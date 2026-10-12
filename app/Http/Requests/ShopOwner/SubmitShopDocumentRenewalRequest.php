<?php

declare(strict_types=1);

namespace App\Http\Requests\ShopOwner;

use App\Models\ShopOwner;
use Illuminate\Foundation\Http\FormRequest;

final class SubmitShopDocumentRenewalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $owner = $this->user('shop_owner');

        return $owner instanceof ShopOwner;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'document_type' => ['required', 'string', 'max:120', 'in:dti_registration,sec_registration,mayors_permit,bir_certificate,valid_id,supporting_document'],
            'logical_slot' => ['required', 'string', 'max:120'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'expiration_mode' => ['required', 'in:dated,none'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'submission_key' => ['required', 'uuid'],
        ];
    }
}
