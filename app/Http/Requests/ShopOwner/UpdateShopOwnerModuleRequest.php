<?php

namespace App\Http\Requests\ShopOwner;

use App\Models\ShopOwner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class UpdateShopOwnerModuleRequest extends FormRequest
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
            'module_key' => [
                'required',
                'string',
                Rule::in(array_keys(config('shop_modules.modules', []))),
            ],
            'enabled' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'module_key' => (string) $this->route('moduleKey'),
        ]);
    }
}
