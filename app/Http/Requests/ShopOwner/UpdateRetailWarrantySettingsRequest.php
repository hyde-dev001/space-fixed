<?php

namespace App\Http\Requests\ShopOwner;

use App\Models\ShopOwner;
use App\Services\BusinessAccessControlService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRetailWarrantySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $owner = $this->user('shop_owner');
        $businessType = $owner instanceof ShopOwner
            ? app(BusinessAccessControlService::class)->normalizeBusinessType((string) $owner->business_type)
            : '';

        return $owner instanceof ShopOwner && in_array($businessType, ['retail', 'both'], true);
    }

    public function rules(): array
    {
        $unit = $this->input('duration_unit', 'days');
        $limit = is_string($unit) ? (['days' => 3650, 'weeks' => 520, 'months' => 120, 'years' => 10][$unit] ?? 3650) : 3650;

        return [
            'enabled' => ['required', 'boolean'],
            'title' => ['required_if:enabled,true', 'nullable', 'string', 'max:160'],
            'duration_value' => ['required', 'integer', 'min:1', 'max:'.$limit],
            'duration_unit' => ['required', Rule::in(['days', 'weeks', 'months', 'years'])],
            'description' => ['nullable', 'string', 'max:5000'],
            'terms' => ['required_if:enabled,true', 'nullable', 'string', 'max:20000'],
            'exclusions' => ['nullable', 'string', 'max:10000'],
            'instructions' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
