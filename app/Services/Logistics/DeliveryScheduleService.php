<?php

namespace App\Services\Logistics;

use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class DeliveryScheduleService
{
    public function estimate(
        ShopOwner $shop,
        CarbonInterface $readyAt,
        ?float $destinationLatitude,
        ?float $destinationLongitude,
    ): array {
        if ($destinationLatitude === null || $destinationLongitude === null) {
            return $this->result('needs_coordinates');
        }
        if ($shop->shop_latitude === null || $shop->shop_longitude === null) {
            return $this->result('needs_shop_coordinates');
        }

        $settings = $shop->logisticsSetting ?: LogisticsSetting::firstOrCreate(['shop_owner_id' => $shop->id]);
        $distance = $this->distanceKm(
            (float) $shop->shop_latitude,
            (float) $shop->shop_longitude,
            $destinationLatitude,
            $destinationLongitude,
        );
        if ($distance > (float) $settings->coverage_radius_km) {
            return $this->result('outside_coverage', distance: $distance);
        }

        $riders = RiderProfile::query()
            ->where('shop_owner_id', $shop->id)
            ->where('active', true)
            ->where('availability_status', 'available')
            ->count();
        if ($riders === 0) {
            return $this->result('needs_capacity', distance: $distance);
        }

        $date = CarbonImmutable::instance($readyAt)->setTimezone(config('app.shop_timezone', 'Asia/Manila'));
        if ($date->format('H:i') >= substr($settings->cutoff_time, 0, 5)) {
            $date = $date->addDay()->startOfDay();
        }
        $date = $this->nextOperatingDate($date, $settings);
        for ($i = 0; $i < $settings->lead_time_days; $i++) {
            $date = $this->nextOperatingDate($date->addDay(), $settings);
        }

        $capacity = $riders * $settings->daily_rider_capacity;
        for ($days = 0; $days < 366; $days++) {
            $used = ShipmentLeg::query()
                ->whereHas('shipment', fn ($query) => $query->where('shop_owner_id', $shop->id))
                ->whereDate('scheduled_delivery_date', $date->toDateString())
                ->where('schedule_status', 'scheduled')
                ->count();
            if ($used < $capacity) {
                return $this->result(
                    'scheduled',
                    $date->toDateString(),
                    $used < (int) ceil($capacity / 2) ? 'morning' : 'afternoon',
                    $distance,
                );
            }
            $date = $this->nextOperatingDate($date->addDay(), $settings);
        }

        return $this->result('needs_capacity', distance: $distance);
    }

    private function nextOperatingDate(CarbonImmutable $date, LogisticsSetting $settings): CarbonImmutable
    {
        while (!in_array($date->dayOfWeekIso, $settings->operating_days, true)
            || in_array($date->toDateString(), $settings->blackout_dates, true)) {
            $date = $date->addDay();
        }

        return $date;
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return round(6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    private function result(
        string $status,
        ?string $date = null,
        ?string $window = null,
        ?float $distance = null,
    ): array {
        return [
            'scheduled_delivery_date' => $date,
            'delivery_window' => $window,
            'schedule_status' => $status,
            'distance_km' => $distance,
        ];
    }
}
