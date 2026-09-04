<?php

namespace App\Services\Logistics;

use App\Enums\Logistics\RiderProgressState;
use App\Enums\Logistics\ShipmentLegStatus;
use App\Enums\Logistics\ShipmentStatus;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderCurrentLocation;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RiderLocationService
{
    /** @var array<int, ShipmentLegStatus> */
    private const TRACKABLE_LEG_STATUSES = [
        ShipmentLegStatus::IN_TRANSIT,
    ];

    public function __construct(
        private RouteEstimationService $routes,
    ) {}

    public function activeAssignmentFor(ShipmentLeg $leg, User $rider): ?DeliveryAssignment
    {
        $leg->loadMissing(['shipment', 'deliveryBatch', 'latestAssignment']);

        if (! $leg->shipment
            || $leg->shipment->status !== ShipmentStatus::ACTIVE
            || $leg->rider_progress_state !== RiderProgressState::ACTIVE
            || ! in_array($leg->status, self::TRACKABLE_LEG_STATUSES, true)
            || ! $this->isCustomerDeliveryLeg($leg)) {
            return null;
        }

        $assignment = $leg->latestAssignment;

        if (! $assignment
            || $assignment->assignment_type !== 'internal_rider'
            || ! in_array($assignment->status, ['assigned', 'accepted'], true)) {
            return null;
        }

        $assignment->loadMissing('riderProfile');

        if (! $this->matchesRider($assignment->riderProfile, $rider, $leg)) {
            return null;
        }

        if ($leg->delivery_batch_id && ! $this->isCurrentBatchLeg($leg, $assignment)) {
            return null;
        }

        return $assignment;
    }

    public function record(
        ShipmentLeg $leg,
        DeliveryAssignment $assignment,
        array $payload,
    ): RiderCurrentLocation {
        $recordedAt = Carbon::parse((string) $payload['recorded_at']);
        $receivedAt = now();

        if ($recordedAt->lt($receivedAt->copy()->subSeconds(
            (int) config('logistics_tracking.gps.max_record_age_seconds', 120)
        ))) {
            throw ValidationException::withMessages([
                'recorded_at' => 'The GPS reading is too old to accept.',
            ]);
        }

        if ($recordedAt->gt($receivedAt->copy()->addSeconds(
            (int) config('logistics_tracking.gps.max_future_seconds', 60)
        ))) {
            throw ValidationException::withMessages([
                'recorded_at' => 'The GPS reading cannot be in the future.',
            ]);
        }

        return DB::transaction(function () use ($leg, $assignment, $payload, $recordedAt, $receivedAt): RiderCurrentLocation {
            $current = RiderCurrentLocation::query()
                ->where('shipment_leg_id', $leg->id)
                ->lockForUpdate()
                ->first();

            if ($current && $recordedAt->lessThanOrEqualTo($current->recorded_at)) {
                return $current;
            }

            if ($current
                && (int) $current->delivery_assignment_id === (int) $assignment->id
                && $this->impliedSpeedMps($current, (float) $payload['latitude'], (float) $payload['longitude'], $recordedAt)
                    > (float) config('logistics_tracking.gps.max_implied_speed_mps', 100)) {
                throw ValidationException::withMessages([
                    'coordinates' => 'The GPS reading indicates an impossible movement jump.',
                ]);
            }

            $attributes = [
                'shipment_leg_id' => $leg->id,
                'rider_profile_id' => $assignment->rider_profile_id,
                'delivery_assignment_id' => $assignment->id,
                'latitude' => (float) $payload['latitude'],
                'longitude' => (float) $payload['longitude'],
                'accuracy_m' => isset($payload['accuracy_m']) ? (float) $payload['accuracy_m'] : null,
                'speed_mps' => isset($payload['speed_mps']) ? (float) $payload['speed_mps'] : null,
                'heading_deg' => isset($payload['heading_deg']) ? (float) $payload['heading_deg'] : null,
                'recorded_at' => $recordedAt,
                'received_at' => $receivedAt,
            ];

            if ($current) {
                $current->fill($attributes);
                $current->save();

                return $current->fresh();
            }

            return RiderCurrentLocation::query()->create($attributes);
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    public function liveLocationsForShop(int $shopOwnerId): Collection
    {
        $trackableStatuses = $this->trackableStatusValues();
        $staleAt = now()->subSeconds((int) config('logistics_tracking.stale_after_seconds', 90));

        return RiderCurrentLocation::query()
            ->with(['leg.shipment', 'leg.latestAssignment', 'leg.deliveryBatch', 'riderProfile'])
            ->whereHas('leg', function ($query) use ($shopOwnerId, $trackableStatuses): void {
                $query
                    ->whereIn('status', $trackableStatuses)
                    ->where('rider_progress_state', RiderProgressState::ACTIVE->value)
                    ->whereHas('shipment', fn ($shipments) => $shipments
                        ->where('shop_owner_id', $shopOwnerId)
                        ->where('status', ShipmentStatus::ACTIVE->value));
            })
            ->get()
            ->filter(fn (RiderCurrentLocation $location): bool => $this->isVisibleLocation($location))
            ->map(fn (RiderCurrentLocation $location): array => $this->liveLocationPayload($location, $staleAt))
            ->values();
    }

    /** @return array<string, mixed>|null */
    public function customerLiveLocationForShipment(Shipment $shipment): ?array
    {
        $location = RiderCurrentLocation::query()
            ->with(['leg.shipment', 'leg.latestAssignment', 'leg.deliveryBatch', 'riderProfile'])
            ->whereHas('leg', function ($query) use ($shipment): void {
                $query
                    ->where('shipment_id', $shipment->id)
                    ->whereIn('status', $this->trackableStatusValues())
                    ->where('rider_progress_state', RiderProgressState::ACTIVE->value)
                    ->whereHas('shipment', fn ($shipments) => $shipments
                        ->whereKey($shipment->id)
                        ->where('shop_owner_id', $shipment->shop_owner_id)
                        ->where('status', ShipmentStatus::ACTIVE->value));
            })
            ->orderByDesc('shipment_leg_id')
            ->first();

        if (! $location || ! $this->isVisibleLocation($location)) {
            return null;
        }

        $payload = $this->liveLocationPayload(
            $location,
            now()->subSeconds((int) config('logistics_tracking.stale_after_seconds', 90)),
        );

        return [
            'leg_id' => $payload['leg_id'],
            'status' => $payload['status'],
            'destination' => $payload['destination'],
            'location' => $payload['location'],
            'stale' => $payload['stale'],
            'route' => $payload['route'],
        ];
    }
    /** @return array{distance_m: float, duration_s: int, geometry: array<int, array{0: float, 1: float}>, source: string}|null */
    public function routeFor(?ShipmentLeg $leg, RiderCurrentLocation $location): ?array
    {
        if (! $leg) {
            return null;
        }
        $snapshot = is_array($leg->destination_snapshot) ? $leg->destination_snapshot : [];
        $coordinate = static fn (string $key): ?float => is_numeric($snapshot[$key] ?? null)
            ? (float) $snapshot[$key]
            : null;
        return $this->routes->estimate(
            [
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
            ],
            [
                'latitude' => $coordinate('latitude'),
                'longitude' => $coordinate('longitude'),
            ],
        );
    }

    /** @return array<int, string> */
    private function trackableStatusValues(): array
    {
        return array_map(
            static fn (ShipmentLegStatus $status): string => $status->value,
            self::TRACKABLE_LEG_STATUSES,
        );
    }

    private function isCustomerDeliveryLeg(ShipmentLeg $leg): bool
    {
        return data_get($leg->destination_snapshot, 'type') === 'customer';
    }

    private function isVisibleLocation(RiderCurrentLocation $location): bool
    {
        $leg = $location->leg;
        $assignment = $leg?->latestAssignment;
        $profile = $location->riderProfile;

        return $leg
            && $assignment
            && (int) $location->delivery_assignment_id === (int) $assignment->id
            && (int) $location->rider_profile_id === (int) $assignment->rider_profile_id
            && $assignment->assignment_type === 'internal_rider'
            && in_array($assignment->status, ['assigned', 'accepted'], true)
            && $profile?->active
            && $profile->availability_status !== 'inactive'
            && $profile->rider_type === 'employee'
            && $this->isCustomerDeliveryLeg($leg)
            && (! $leg->delivery_batch_id || $this->isCurrentBatchLeg($leg, $assignment));
    }

    /** @return array<string, mixed> */
    private function liveLocationPayload(RiderCurrentLocation $location, Carbon $staleAt): array
    {
        $leg = $location->leg;
        $shipment = $leg?->shipment;
        $snapshot = is_array($leg?->destination_snapshot) ? $leg->destination_snapshot : [];
        $coordinate = static fn (string $key): ?float => is_numeric($snapshot[$key] ?? null)
            ? (float) $snapshot[$key]
            : null;

        return [
            'leg_id' => $leg?->id,
            'shipment_id' => $shipment?->id,
            'shipment_reference' => $shipment ? "Shipment #{$shipment->id}" : null,
            'rider' => [
                'id' => $location->riderProfile?->id,
                'name' => $location->riderProfile?->name,
            ],
            'status' => $leg?->status?->value,
            'destination' => [
                'type' => $snapshot['type'] ?? null,
                'name' => is_string($snapshot['name'] ?? null) ? $snapshot['name'] : null,
                'address' => is_string($snapshot['address'] ?? null) ? $snapshot['address'] : null,
                'latitude' => $coordinate('latitude'),
                'longitude' => $coordinate('longitude'),
            ],
            'location' => [
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
                'accuracy_m' => $location->accuracy_m !== null ? (float) $location->accuracy_m : null,
                'speed_mps' => $location->speed_mps !== null ? (float) $location->speed_mps : null,
                'heading_deg' => $location->heading_deg !== null ? (float) $location->heading_deg : null,
                'recorded_at' => $location->recorded_at?->toISOString(),
                'received_at' => $location->received_at?->toISOString(),
            ],
            'stale' => ! $location->recorded_at || $location->recorded_at->lte($staleAt),
            'route' => $this->routeFor($leg, $location),
        ];
    }

    private function matchesRider(?RiderProfile $profile, User $rider, ShipmentLeg $leg): bool
    {
        return $profile
            && $profile->active
            && $profile->availability_status !== 'inactive'
            && $profile->rider_type === 'employee'
            && $profile->linked_type === User::class
            && (int) $profile->linked_id === (int) $rider->getAuthIdentifier()
            && (int) $profile->shop_owner_id === (int) $leg->shipment->shop_owner_id
            && (int) $rider->shop_owner_id === (int) $leg->shipment->shop_owner_id;
    }

    private function isCurrentBatchLeg(ShipmentLeg $leg, DeliveryAssignment $assignment): bool
    {
        if (! $leg->deliveryBatch || $leg->deliveryBatch->status !== 'in_progress') {
            return false;
        }

        $currentLegId = ShipmentLeg::query()
            ->where('delivery_batch_id', $leg->delivery_batch_id)
            ->where('rider_progress_state', RiderProgressState::ACTIVE->value)
            ->whereIn('status', $this->trackableStatusValues())
            ->whereHas('latestAssignment', fn ($query) => $query
                ->where('assignment_type', 'internal_rider')
                ->where('rider_profile_id', $assignment->rider_profile_id)
                ->whereIn('status', ['assigned', 'accepted']))
            ->orderByRaw('stop_sequence IS NULL')
            ->orderBy('stop_sequence')
            ->orderBy('id')
            ->value('id');

        return (int) $currentLegId === (int) $leg->id;
    }

    private function impliedSpeedMps(
        RiderCurrentLocation $current,
        float $latitude,
        float $longitude,
        Carbon $recordedAt,
    ): float {
        $elapsedSeconds = $recordedAt->timestamp - $current->recorded_at->timestamp;
        if ($elapsedSeconds <= 0) {
            return 0;
        }

        return $this->distanceMeters(
            (float) $current->latitude,
            (float) $current->longitude,
            $latitude,
            $longitude,
        ) / $elapsedSeconds;
    }

    private function distanceMeters(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $earthRadiusMeters = 6371000;
        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);
        $fromLatitude = deg2rad($fromLatitude);
        $toLatitude = deg2rad($toLatitude);

        $a = sin($latitudeDelta / 2) ** 2
            + cos($fromLatitude) * cos($toLatitude) * sin($longitudeDelta / 2) ** 2;

        return $earthRadiusMeters * 2 * asin(min(1, sqrt($a)));
    }
}
