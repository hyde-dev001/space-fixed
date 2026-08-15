<?php

declare(strict_types=1);

namespace App\Http\Requests\ShopOwner;

use App\Enums\OwnerShellFallbackReason;
use App\Models\ShopOwner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class OpenOwnerErpFallbackRequest extends FormRequest
{
    /** @var list<string> */
    public const ALLOWED_SOURCES = [
        'home',
        'operate.retail',
        'operate.repair',
        'operate.customers',
        'operate.payments',
        'oversee.finance',
        'oversee.workforce',
        'oversee.inventory',
        'oversee.procurement',
        'oversee.logistics',
        'reports',
        'audit',
        'settings.profile',
        'settings.modules-team',
        'settings.payments-approvals',
        'settings.operations',
        'settings.policies-compliance',
        'settings.subscription',
    ];

    public function authorize(): bool
    {
        return Auth::guard('shop_owner')->user() instanceof ShopOwner;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason'),
            'source' => is_string($this->input('source')) ? trim($this->input('source')) : $this->input('source'),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::enum(OwnerShellFallbackReason::class)],
            'source' => ['required', 'string', Rule::in(self::ALLOWED_SOURCES)],
        ];
    }
}
