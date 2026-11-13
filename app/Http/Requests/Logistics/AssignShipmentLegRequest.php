<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class AssignShipmentLegRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (Auth::guard('shop_owner')->check()) {
            return true;
        }

        return (bool) Auth::guard('user')->user()?->can('assign-logistics-deliveries');
    }

    public function rules(): array
    {
        return [
            'assignment_type' => ['required', 'in:internal_rider'],
            'rider_profile_id' => ['required', 'integer', 'exists:rider_profiles,id'],
        ];
    }
}
