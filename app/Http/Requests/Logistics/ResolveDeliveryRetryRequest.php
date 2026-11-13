<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ResolveDeliveryRetryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = Auth::guard('user')->user();
        return $actor && ($actor->can('resolve-logistics-exceptions') || $actor->can('assign-logistics-deliveries'));
    }

    public function rules(): array
    {
        return self::retryRules();
    }

    public static function retryRules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'delivery_date' => ['required', 'date_format:Y-m-d'],
            'delivery_window' => ['required', 'in:morning,afternoon'],
            'rider_profile_id' => ['required', 'integer'],
            'expected_assignment_id' => ['required', 'integer'],
            'request_key' => ['required', 'uuid'],
            'handoff_route' => ['required', 'in:direct,via_shop'],
            'appointment_confirmed' => ['sometimes', 'boolean'],
            'appointment_agreement_note' => ['required_if:appointment_confirmed,true', 'nullable', 'string', 'max:1000'],
            'capacity_override_reason' => ['nullable', 'string', 'max:1000'],
            ...self::immediateRules(),
        ];
    }

    public static function immediateRules(): array
    {
        return [
            'immediate_recovery' => ['sometimes', 'boolean'],
            'effective_start' => ['required_if:immediate_recovery,true', 'nullable', 'date'],
            'effective_end' => ['required_if:immediate_recovery,true', 'nullable', 'date', 'after:effective_start'],
            'immediate_reason' => ['required_if:immediate_recovery,true', 'nullable', 'string', 'max:1000'],
        ];
    }
}
