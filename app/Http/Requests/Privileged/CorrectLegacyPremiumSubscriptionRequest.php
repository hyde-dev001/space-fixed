<?php

declare(strict_types=1);

namespace App\Http\Requests\Privileged;

use App\Models\SuperAdmin;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class CorrectLegacyPremiumSubscriptionRequest extends FormRequest
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
            'target_status' => is_string($this->input('target_status'))
                ? strtolower(trim($this->input('target_status')))
                : $this->input('target_status'),
            'correction_reason' => is_string($this->input('correction_reason'))
                ? trim($this->input('correction_reason'))
                : $this->input('correction_reason'),
            'correction_notes' => is_string($this->input('correction_notes'))
                ? trim($this->input('correction_notes'))
                : $this->input('correction_notes'),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'target_status' => ['required', 'string', Rule::in(['cancelled', 'expired'])],
            'effective_ends_at' => ['required', 'date'],
            'correction_reason' => ['required', 'string', 'max:120'],
            'correction_notes' => ['nullable', 'string', 'max:1000'],
            'paid_amount' => ['prohibited'],
            'amount_due' => ['prohibited'],
            'amount_paid' => ['prohibited'],
            'payment_status' => ['prohibited'],
            'premium_plan_id' => ['prohibited'],
            'plan_code' => ['prohibited'],
            'showroom_slot_limit' => ['prohibited'],
            'shop_owner_id' => ['prohibited'],
            'starts_at' => ['prohibited'],
            'ends_at' => ['prohibited'],
            'paymongo_payment_id' => ['prohibited'],
            'paymongo_session_id' => ['prohibited'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->filled('effective_ends_at') || ! $this->filled('target_status')) {
                return;
            }

            try {
                $effectiveEndsAt = Carbon::parse((string) $this->input('effective_ends_at'));
            } catch (\Throwable) {
                return;
            }

            if ($this->input('target_status') === 'expired' && $effectiveEndsAt->isFuture()) {
                $validator->errors()->add('effective_ends_at', 'Expired corrections require an end date at or before now.');
            }

            if ($this->input('target_status') === 'cancelled' && ! $effectiveEndsAt->isFuture()) {
                $validator->errors()->add('effective_ends_at', 'Cancelled corrections require a future effective end date.');
            }
        });
    }
}
