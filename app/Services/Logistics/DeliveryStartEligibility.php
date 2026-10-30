<?php

namespace App\Services\Logistics;

use App\Models\Logistics\ShipmentLeg;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class DeliveryStartEligibility
{
    public function __construct(private AssignmentService $assignments) {}

    public function assertCanStart(ShipmentLeg $leg, ?\App\Models\Logistics\RiderProfile $rider = null): void
    {
        if ($rider) $this->assignments->assertEligibleForDate($rider, $leg->shipment->shopOwner, now(config('app.shop_timezone', 'Asia/Manila'))->toDateString(), $leg->id, checkCapacity: false);
    }

    public function hasActivated(ShipmentLeg $leg): bool
    {
        $assignment = $leg->relationLoaded('assignments') ? $leg->assignments->sortByDesc('id')->first() : $leg->assignments()->latest('id')->first();
        if (! $assignment || $assignment->status !== 'accepted') return false;
        if ($leg->relationLoaded('events')) {
            return $leg->events->contains(fn ($event) => in_array($event->event_type, ['in_transit', 'out_for_delivery'], true)
                && (int) data_get($event->metadata, 'delivery_assignment_id') === (int) $assignment->id
                && (! $assignment->accepted_at || $event->created_at->greaterThanOrEqualTo($assignment->accepted_at)));
        }

        // Parent batch starts, possession and another assignment's timestamps do not activate this stop.
        return $leg->events()->whereIn('event_type', ['in_transit', 'out_for_delivery'])
            ->where('metadata->delivery_assignment_id', $assignment->id)
            ->when($assignment->accepted_at, fn ($query) => $query->where('created_at', '>=', $assignment->accepted_at))
            ->exists();
    }

    public function scheduleFacts(ShipmentLeg $leg): array
    {
        return ['delivery_date' => $leg->scheduled_delivery_date?->toDateString(), 'delivery_window' => $leg->delivery_window,
            'appointment_date' => $leg->customer_appointment_date?->toDateString(), 'appointment_window' => $leg->customer_appointment_window,
            'appointment_agreed_at' => $leg->appointment_agreed_at?->toISOString()];
    }

    public function immediateRecovery(\App\Models\ShopOwner $shop, string $date, string $window, array $options, bool $validateCurrentEligibility = true): ?array
    {
        if (! ($options['immediate_recovery'] ?? false)) return null;
        \Illuminate\Support\Facades\Validator::make($options, [
            'effective_start' => ['required', 'date'], 'effective_end' => ['required', 'date', 'after:effective_start'],
            'immediate_reason' => ['required', 'string', 'max:1000'],
        ])->validate();
        $zone = config('app.shop_timezone', 'Asia/Manila');
        $settings = $shop->logisticsSetting()->firstOrCreate([]);
        $start = CarbonImmutable::parse($options['effective_start'], $zone);
        $end = CarbonImmutable::parse($options['effective_end'], $zone);
        $opens = CarbonImmutable::parse($date.' '.$settings->{$window.'_start'}, $zone);
        $closes = CarbonImmutable::parse($date.' '.$settings->{$window.'_end'}, $zone);
        if ($validateCurrentEligibility && ($start->lessThan($opens) || $end->greaterThan($closes) || $end->lessThanOrEqualTo(CarbonImmutable::now($zone)))) {
            throw ValidationException::withMessages(['effective_end' => 'Immediate recovery must have a future expiry within the configured operating window.']);
        }
        return ['effective_start' => $start->toISOString(), 'effective_end' => $end->toISOString(),
            'timezone' => $zone, 'delivery_date' => $date, 'delivery_window' => $window, 'reason' => $options['immediate_reason']];
    }

    public function assertActivated(ShipmentLeg $leg): void
    {
        if (! $this->hasActivated($leg)) {
            throw ValidationException::withMessages(['activation' => 'Current-assignment delivery activation is unconfirmed. Use the authorized Start Delivery action while eligible, or ask dispatch for recovery.']);
        }
    }

    public function validateRecoveryDate(\App\Models\ShopOwner $shop, string $date, string $window): void
    {
        \Illuminate\Support\Facades\Validator::make(['delivery_date' => $date, 'delivery_window' => $window], [
            'delivery_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now(config('app.shop_timezone', 'Asia/Manila'))->toDateString()],
            'delivery_window' => ['required', 'in:morning,afternoon'],
        ])->validate();
        $day = CarbonImmutable::parse($date, config('app.shop_timezone', 'Asia/Manila'));
        $settings = $shop->logisticsSetting()->firstOrCreate([]);
        if (! in_array($day->dayOfWeekIso, $settings->operating_days, true) || in_array($date, $settings->blackout_dates ?? [], true)) {
            throw ValidationException::withMessages(['delivery_date' => 'Choose an operating day that is not a blackout date.']);
        }
    }

    public function appointmentChanges(ShipmentLeg $leg, string $date, string $window, bool $confirmed, ?string $note, ?\App\Models\User $actor): array
    {
        if ($leg->customer_appointment_date && ($leg->customer_appointment_date->toDateString() !== $date || $leg->customer_appointment_window !== $window) && ! $confirmed) {
            throw ValidationException::withMessages(['appointment_confirmed' => 'Record customer agreement before changing the appointment.']);
        }
        if (! $confirmed) return [];
        if (! filled($note) || ! $actor) {
            throw ValidationException::withMessages(['appointment_agreement_note' => 'Record how the customer agreed to this appointment.']);
        }
        return ['customer_appointment_date' => $date, 'customer_appointment_window' => $window,
            'appointment_agreement_note' => $note, 'appointment_agreed_at' => now(), 'appointment_agreed_by_user_id' => $actor->id];
    }
}
