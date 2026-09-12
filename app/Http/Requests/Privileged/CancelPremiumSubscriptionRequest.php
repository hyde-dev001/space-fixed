<?php

declare(strict_types=1);

namespace App\Http\Requests\Privileged;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class CancelPremiumSubscriptionRequest extends FormRequest
{
    /** @var list<string> */
    public const ALLOWED_REASONS = [
        'reduce_costs',
        'low_value',
        'technical_issues',
        'missing_features',
        'subscribed_by_mistake',
        'temporary_pause',
        'others',
        'operator_correction',
    ];

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
            'cancellation_reason' => is_string($this->input('cancellation_reason'))
                ? trim($this->input('cancellation_reason'))
                : $this->input('cancellation_reason'),
            'cancellation_notes' => is_string($this->input('cancellation_notes'))
                ? trim($this->input('cancellation_notes'))
                : $this->input('cancellation_notes'),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', Rule::in(self::ALLOWED_REASONS)],
            'cancellation_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
