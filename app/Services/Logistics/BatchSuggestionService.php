<?php

namespace App\Services\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
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
            ->where('availability_status', 'available')
            ->where(function ($query) {
                $query->where('rider_type', '!=', 'employee')
                    ->orWhere(function ($query) {
                        $query->where('linked_type', \App\Models\User::class)
                            ->whereNotNull('linked_id');
                    });
            })
            ->get()
            ->filter(fn ($rider) => (!$rider->work_days || in_array($date->dayOfWeekIso, $rider->work_days, true))
                && !in_array($date->toDateString(), $rider->leave_dates ?? [], true));
        $batchWorkload = DeliveryBatch::query()
            ->where('shop_owner_id', $shop->id)
            ->whereDate('delivery_date', $date->toDateString())
            ->whereIn('status', ['offered', 'accepted', 'in_progress', 'completed'])
            ->selectRaw('rider_profile_id, SUM(assigned_stop_count) as stop_count')
            ->groupBy('rider_profile_id')
            ->pluck('stop_count', 'rider_profile_id');
        $standaloneWorkload = DeliveryAssignment::query()
            ->whereIn('rider_profile_id', $riders->pluck('id'))
            ->whereIn('status', ['assigned', 'accepted'])
            ->whereHas('leg', fn ($query) => $query
                ->whereNull('delivery_batch_id')
                ->whereDate('scheduled_delivery_date', $date->toDateString())
                ->whereHas('shipment', fn ($shipment) => $shipment->where('shop_owner_id', $shop->id)))
            ->selectRaw('rider_profile_id, COUNT(*) as stop_count')
            ->groupBy('rider_profile_id')
            ->pluck('stop_count', 'rider_profile_id');

        return $legs
            ->groupBy(fn ($leg) => Shipment::moduleForSourceType((string) $leg->shipment->source_type))
            ->flatMap(function ($moduleLegs, string $legModule) use ($riders, $settings, $shop, $batchWorkload, $standaloneWorkload) {
                $orderedIds = $this->nearest(
                    $moduleLegs->all(),
                    (float) $shop->shop_latitude,
                    (float) $shop->shop_longitude,
                );
                $remainingLegIds = $orderedIds;

                return $riders->map(function ($rider) use (&$remainingLegIds, $settings, $orderedIds, $legModule, $batchWorkload, $standaloneWorkload) {
                    $capacity = $rider->daily_capacity ?? $settings->daily_rider_capacity;
                    $assignedCount = (int) ($batchWorkload[$rider->id] ?? 0)
                        + (int) ($standaloneWorkload[$rider->id] ?? 0);
                    $remainingCapacity = max(0, $capacity - $assignedCount);
                    $legIds = array_splice($remainingLegIds, 0, $remainingCapacity);
                    return [
                        'rider_profile_id' => $rider->id,
                        'capacity' => $capacity,
                        'assigned_count' => $assignedCount,
                        'overload_count' => max(0, count($orderedIds) - $remainingCapacity),
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
