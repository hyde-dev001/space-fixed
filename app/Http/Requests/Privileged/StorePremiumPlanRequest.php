<?php

declare(strict_types=1);

namespace App\Http\Requests\Privileged;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

final class StorePremiumPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = Auth::guard('super_admin')->user();

        return $actor instanceof SuperAdmin
            && $actor->isActive()
            && $actor->hasCapability(SuperAdmin::CAP_MANAGE_PLANS);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'plan_code' => strtolower(trim((string) $this->input('plan_code'))),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'plan_code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:premium_plans,plan_code'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration_days' => ['required', 'integer', 'between:1,3650'],
            'showroom_slot_limit' => ['required', 'integer', 'between:1,150'],
            'benefits' => ['present', 'array', 'max:20'],
            'benefits.*' => ['required', 'string', 'max:200'],
        ];
    }
}
