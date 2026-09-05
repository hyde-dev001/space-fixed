<?php

declare(strict_types=1);

namespace App\Http\Requests\Privileged;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class RefundPremiumSubscriptionPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = Auth::guard('super_admin')->user();

        return $actor instanceof SuperAdmin
            && $actor->isActive()
            && $actor->hasCapability(SuperAdmin::CAP_INTERVENE_SUBSCRIPTIONS);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'business_reason' => is_string($this->input('business_reason'))
                ? trim($this->input('business_reason'))
                : $this->input('business_reason'),
            'provider_reason' => is_string($this->input('provider_reason'))
                ? strtolower(trim($this->input('provider_reason')))
                : $this->input('provider_reason'),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'business_reason' => ['required', 'string', 'max:500'],
            'provider_reason' => [
                'required',
                'string',
                Rule::in(['duplicate', 'fraudulent', 'requested_by_customer', 'others']),
            ],
        ];
    }
}
