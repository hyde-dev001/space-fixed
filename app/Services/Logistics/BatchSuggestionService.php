<?php

namespace App\Services\Logistics;

use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use Carbon\CarbonInterface;

class BatchSuggestionService
{
    public function suggest(ShopOwner $shop, CarbonInterface $date, string $window, string $module = 'all'): array
    {
        $settings = $shop->logisticsSetting()->firstOrCreate([]);
        $legs = ShipmentLeg::query()
            ->with('shipment')
            ->whereHas('shipment', fn ($query) => $query->where('shop_owner_id', $shop->id))
            ->whereNull('delivery_batch_id')->where('status', 'pending')
            ->where('schedule_status', 'scheduled')->whereDate('scheduled_delivery_date', $date->toDateString())
            ->where('delivery_window', $window)->get()
            ->filter(fn ($leg) => is_numeric(data_get($leg->destination_snapshot, 'latitude'))
                && is_numeric(data_get($leg->destination_snapshot, 'longitude')))
            ->filter(function ($leg) use ($shop, $module) {
                $legModule = Shipment::moduleForSourceType((string) $leg->shipment->source_type);
                return $legModule !== null
                    && in_array($legModule, $shop->logisticsModules(), true)
                    && ($module === 'all' || $module === $legModule);
            })
            ->values();
        $riders = RiderProfile::query()
            ->where('shop_owner_id', $shop->id)->where('active', true)
            ->where('availability_status', 'available')->get()
            ->filter(fn ($rider) => (!$rider->work_days || in_array($date->dayOfWeekIso, $rider->work_days, true))
                && !in_array($date->toDateString(), $rider->leave_dates ?? [], true));

        return $legs
            ->groupBy(fn ($leg) => Shipment::moduleForSourceType((string) $leg->shipment->source_type))
            ->flatMap(function ($moduleLegs, string $legModule) use ($riders, $settings, $shop) {
                $orderedIds = $this->nearest(
                    $moduleLegs->all(),
                    (float) $shop->shop_latitude,
                    (float) $shop->shop_longitude,
                );

                return $riders->map(function ($rider) use ($settings, $orderedIds, $legModule) {
                    $capacity = $rider->daily_capacity ?? $settings->daily_rider_capacity;
                    $legIds = array_slice($orderedIds, 0, $capacity);
                    return [
                        'rider_profile_id' => $rider->id,
                        'capacity' => $capacity,
                        'assigned_count' => 0,
                        'overload_count' => max(0, count($orderedIds) - $capacity),
                        'leg_ids' => $legIds,
                        'module' => $legModule,
                    ];
                })->filter(fn ($suggestion) => count($suggestion['leg_ids']) >= 2);
            })
            ->values()
            ->all();
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
