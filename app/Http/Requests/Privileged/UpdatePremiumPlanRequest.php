<?php

declare(strict_types=1);

namespace App\Http\Requests\Privileged;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

final class UpdatePremiumPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = Auth::guard('super_admin')->user();

        return $actor instanceof SuperAdmin
            && $actor->isActive()
            && $actor->hasCapability(SuperAdmin::CAP_MANAGE_PLANS);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'plan_code' => ['sometimes', 'string', 'max:50', 'alpha_dash'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'duration_days' => ['sometimes', 'required', 'integer', 'between:1,3650'],
            'showroom_slot_limit' => ['sometimes', 'required', 'integer', 'between:1,150'],
            'benefits' => ['sometimes', 'array', 'max:20'],
            'benefits.*' => ['required', 'string', 'max:200'],
        ];
    }
}
