<?php

namespace App\Services\Logistics;

use App\Models\Logistics\ShipmentLeg;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class DeliveryStartEligibility
{
    public function __construct(private AssignmentService $assignments) {}

    public function blockedReason(ShipmentLeg $leg): ?string
    {
        if (! $leg->customer_appointment_date) {
            return null;
        }
        if (! in_array($leg->customer_appointment_window, ['morning', 'afternoon'], true)) {
            return 'Confirm the customer appointment window before starting.';
        }
        $timezone = config('app.shop_timezone', 'Asia/Manila');
        $settings = $leg->shipment->shopOwner->logisticsSetting;
        $window = $leg->customer_appointment_window;
        $defaults = ['morning_start' => '08:00', 'morning_end' => '12:00', 'afternoon_start' => '13:00', 'afternoon_end' => '18:00'];
        $day = $leg->customer_appointment_date->toDateString();
        $start = CarbonImmutable::parse($day.' '.($settings?->{$window.'_start'} ?? $defaults[$window.'_start']), $timezone);
        $end = CarbonImmutable::parse($day.' '.($settings?->{$window.'_end'} ?? $defaults[$window.'_end']), $timezone);
        $now = CarbonImmutable::now($timezone);
        return $now->lessThan($start) || $now->greaterThanOrEqualTo($end)
            ? 'Start during the customer-confirmed appointment, or ask dispatch to record an agreed reschedule.' : null;
    }

    public function assertCanStart(ShipmentLeg $leg, ?\App\Models\Logistics\RiderProfile $rider = null): void
    {
        if ($rider) $this->assignments->assertEligibleForDate($rider, $leg->shipment->shopOwner, now(config('app.shop_timezone', 'Asia/Manila'))->toDateString(), $leg->id, checkCapacity: false);
        if ($reason = $this->blockedReason($leg)) {
            throw ValidationException::withMessages(['customer_appointment' => $reason]);
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
