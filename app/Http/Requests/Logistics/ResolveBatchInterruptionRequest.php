<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ResolveBatchInterruptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('user')->user()?->can('resolve-logistics-exceptions') ?? false;
    }

    public function rules(): array { return self::decisionRules(); }

    public static function decisionRules(): array
    {
        return [
            'resolution' => ['required', 'in:resume,reassign,return_to_shop,escalate,authorize_replacement'],
            'note' => ['required', 'string', 'max:1000'], 'expected_revision' => ['required', 'integer', 'min:0'],
            'decision_key' => ['required', 'uuid'],
            'rider_profile_id' => ['required_if:resolution,reassign', 'integer'],
            'delivery_date' => ['required_if:resolution,reassign', 'date_format:Y-m-d', 'after_or_equal:'.now(config('app.shop_timezone', 'Asia/Manila'))->toDateString()],
            'delivery_window' => ['required_if:resolution,reassign', 'in:morning,afternoon'],
            'handoff_route' => ['required_if:resolution,reassign', 'in:direct,via_shop'],
            'capacity_override_reason' => ['nullable', 'string', 'max:1000'],
            'appointment_agreements' => ['sometimes', 'array', 'max:100'],
            'appointment_agreements.*' => ['string', 'min:1', 'max:1000'],
            ...ResolveDeliveryRetryRequest::immediateRules(),
        ];
    }
}
