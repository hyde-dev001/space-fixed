<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class RecordRiderLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) Auth::guard('user')->user()?->can('update-logistics-status');
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => [
                'nullable',
                'numeric',
                'min:0',
                'max:'.config('logistics_tracking.gps.max_accuracy_m', 50000),
            ],
            'speed_mps' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'heading_deg' => ['nullable', 'numeric', 'between:0,360'],
            'recorded_at' => ['required', 'date'],
        ];
    }
}
