<?php

namespace App\Services\Logistics;

use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use Carbon\CarbonInterface;

class BatchSuggestionService
{
    public function suggest(ShopOwner $shop, CarbonInterface $date, string $window): array
    {
        $settings = $shop->logisticsSetting()->firstOrCreate([]);
        $legs = ShipmentLeg::query()
            ->whereHas('shipment', fn ($query) => $query->where('shop_owner_id', $shop->id))
            ->whereNull('delivery_batch_id')->where('status', 'pending')
            ->where('schedule_status', 'scheduled')->whereDate('scheduled_delivery_date', $date->toDateString())
            ->where('delivery_window', $window)->get();
        $orderedIds = $this->nearest(
            $legs->all(), (float) $shop->shop_latitude, (float) $shop->shop_longitude,
        );

        return RiderProfile::query()
            ->where('shop_owner_id', $shop->id)->where('active', true)
            ->where('availability_status', 'available')->get()
            ->filter(fn ($rider) => (!$rider->work_days || in_array($date->dayOfWeekIso, $rider->work_days, true))
                && !in_array($date->toDateString(), $rider->leave_dates ?? [], true))
            ->map(function ($rider) use ($settings, $orderedIds) {
                $capacity = $rider->daily_capacity ?? $settings->daily_rider_capacity;
                return [
                    'rider_profile_id' => $rider->id,
                    'capacity' => $capacity,
                    'assigned_count' => 0,
                    'overload_count' => max(0, count($orderedIds) - $capacity),
                    'leg_ids' => array_slice($orderedIds, 0, $capacity),
                ];
            })->values()->all();
    }

    private function nearest(array $legs, float $latitude, float $longitude): array
    {
        $ordered = [];
        while ($legs) {
            usort($legs, function ($a, $b) use ($latitude, $longitude) {
                $aDistance = $this->distance($latitude, $longitude, (float) data_get($a->destination_snapshot, 'latitude'), (float) data_get($a->destination_snapshot, 'longitude'));
                $bDistance = $this->distance($latitude, $longitude, (float) data_get($b->destination_snapshot, 'latitude'), (float) data_get($b->destination_snapshot, 'longitude'));
                return $aDistance <=> $bDistance ?: $a->id <=> $b->id;
            });
            $next = array_shift($legs);
            $ordered[] = $next->id;
            $latitude = (float) data_get($next->destination_snapshot, 'latitude');
            $longitude = (float) data_get($next->destination_snapshot, 'longitude');
        }
        return $ordered;
    }

    private function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        return ($lat2 - $lat1) ** 2 + ($lon2 - $lon1) ** 2;
    }
}
