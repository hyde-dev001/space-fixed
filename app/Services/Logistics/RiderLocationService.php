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
        ShipmentLegStatus::ASSIGNED,
        ShipmentLegStatus::PICKUP_SCHEDULED,
    ];

    public function __construct(
        private RouteEstimationService $routes,
        private DeliveryTypeResolver $deliveryTypes,
    ) {}

    public function activeAssignmentFor(ShipmentLeg $leg, User $rider): ?DeliveryAssignment
    {
        $leg->loadMissing(['shipment', 'deliveryBatch', 'latestAssignment']);

        if (! $leg->shipment
            || $leg->shipment->status !== ShipmentStatus::ACTIVE
            || $leg->rider_progress_state !== RiderProgressState::ACTIVE
            || ! in_array($leg->status, self::TRACKABLE_LEG_STATUSES, true)
            || ! $this->isCustomerTrackableLeg($leg)) {
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

        $accepted = false;
        $location = DB::transaction(function () use ($leg, $assignment, $payload, $recordedAt, $receivedAt, &$accepted): RiderCurrentLocation {
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
            if ($current && (int) $current->delivery_assignment_id !== (int) $assignment->id) {
                $attributes += [
                    'route_geometry' => null,
                    'route_distance_m' => null,
                    'route_duration_s' => null,
                    'route_source' => null,
                    'route_version' => 0,
                    'route_updated_at' => null,
                    'route_off_route_samples' => 0,
                    'route_off_route_state' => 'on_route',
                    'route_cooldown_until' => null,
                    'route_target_latitude' => null,
                    'route_target_longitude' => null,
                ];
            }
            $accepted = true;

            if ($current) {
                $current->fill($attributes);
                $current->save();

                return $current->fresh();
            }

            return RiderCurrentLocation::query()->create($attributes);
        });

        return $accepted ? $this->refreshCanonicalRoute($leg, $location) : $location;
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
            'delivery_type' => $payload['delivery_type'],
            'delivery_label' => $payload['delivery_label'],
            'status' => $payload['status'],
            'destination' => $payload['destination'],
            'location' => $payload['location'],
            'stale' => $payload['stale'],
            'route' => $payload['route'],
        ];
    }
    /** @return array<string, mixed>|null */
    public function routeFor(?ShipmentLeg $leg, RiderCurrentLocation $location): ?array
    {
        if (! $leg) {
            return null;
        }

        return $this->routePayload($leg, $location);
    }

    private function refreshCanonicalRoute(ShipmentLeg $leg, RiderCurrentLocation $location): RiderCurrentLocation
    {
        $target = $this->targetCoordinates($leg);
        if (! $target) {
            return $location;
        }

        return DB::transaction(function () use ($leg, $location, $target): RiderCurrentLocation {
            $current = RiderCurrentLocation::query()
                ->whereKey($location->id)
                ->where('shipment_leg_id', $leg->id)
                ->lockForUpdate()
                ->first();
            if (! $current) {
                return $location;
            }

            $currentPoint = [
                'latitude' => (float) $current->latitude,
                'longitude' => (float) $current->longitude,
            ];
            $targetChanged = $this->routeTargetChanged($current, $target);
            $geometry = is_array($current->route_geometry) ? $current->route_geometry : [];

            if ($targetChanged || count($geometry) < 2 || ! filled($current->route_source)) {
                // ponytail: hold one leg row lock through this bounded provider call to avoid duplicate reroutes without a second state machine.
                $route = $this->routes->estimate($currentPoint, $target);

                return $this->storeRoute(
                    $current,
                    $route,
                    $target,
                    $targetChanged ? max(1, (int) $current->route_version + 1) : max(1, (int) $current->route_version),
                );
            }

            if ($current->route_source !== 'road') {
                return $current;
            }

            $trimmed = $this->routes->trimRoute(
                $geometry,
                $currentPoint,
                (float) config('logistics_tracking.route_progress.trim_tolerance_m', 25),
            );
            if (! $trimmed) {
                return $current;
            }

            $offRoute = $trimmed['distance_to_route_m'] > (float) config(
                'logistics_tracking.route_progress.off_route_threshold_m',
                75,
            );
            if ($offRoute) {
                return $this->handleOffRoute($current, $target, $currentPoint, $trimmed);
            }

            $remainingDistance = $this->routes->distanceForGeometry($trimmed['geometry']);
            $current->forceFill([
                'route_geometry' => $trimmed['geometry'],
                'route_distance_m' => $remainingDistance,
                'route_duration_s' => $this->remainingDuration($current, $remainingDistance),
                'route_version' => max(1, (int) $current->route_version),
                'route_updated_at' => now(),
                'route_off_route_samples' => 0,
                'route_off_route_state' => $this->cooldownActive($current) ? 'cooldown' : 'on_route',
                'route_target_latitude' => $target['latitude'],
                'route_target_longitude' => $target['longitude'],
            ])->save();

            return $current->fresh();
        });
    }

    /** @param array{latitude: float, longitude: float} $target */
    /** @param array{latitude: float, longitude: float} $currentPoint */
    /** @param array<string, mixed> $trimmed */
    private function handleOffRoute(
        RiderCurrentLocation $current,
        array $target,
        array $currentPoint,
        array $trimmed,
    ): RiderCurrentLocation {
        $samples = min(
            255,
            (int) $current->route_off_route_samples + 1,
        );
        $requiredSamples = max(1, (int) config(
            'logistics_tracking.route_progress.off_route_consecutive_samples',
            3,
        ));

        if ($samples >= $requiredSamples && ! $this->cooldownActive($current)) {
            // ponytail: reroute only after confirmation; the existing row lock serializes requests per active leg.
            $route = $this->routes->estimate($currentPoint, $target, false);
            if ($route) {
                return $this->storeRoute(
                    $current,
                    $route,
                    $target,
                    max(1, (int) $current->route_version + 1),
                    'cooldown',
                    0,
                    now()->addSeconds(max(0, (int) config(
                        'logistics_tracking.route_progress.reroute_cooldown_seconds',
                        60,
                    ))),
                );
            }
        }

        $current->forceFill([
            'route_updated_at' => now(),
            'route_off_route_samples' => $samples,
            'route_off_route_state' => $this->cooldownActive($current) ? 'cooldown' : 'monitoring',
            'route_target_latitude' => $target['latitude'],
            'route_target_longitude' => $target['longitude'],
        ])->save();

        return $current->fresh();
    }

    /** @param array{latitude: float, longitude: float} $target */
    private function storeRoute(
        RiderCurrentLocation $current,
        ?array $route,
        array $target,
        int $version,
        string $state = 'on_route',
        int $offRouteSamples = 0,
        ?Carbon $cooldownUntil = null,
    ): RiderCurrentLocation {
        $current->forceFill([
            'route_geometry' => $route['geometry'] ?? null,
            'route_distance_m' => $route['distance_m'] ?? null,
            'route_duration_s' => $route['duration_s'] ?? null,
            'route_source' => $route['source'] ?? null,
            'route_version' => $route ? $version : (int) $current->route_version,
            'route_updated_at' => now(),
            'route_off_route_samples' => $offRouteSamples,
            'route_off_route_state' => $state,
            'route_cooldown_until' => $cooldownUntil,
            'route_target_latitude' => $target['latitude'],
            'route_target_longitude' => $target['longitude'],
        ])->save();

        return $current->fresh();
    }

    /** @return array{latitude: float, longitude: float}|null */
    private function targetCoordinates(ShipmentLeg $leg): ?array
    {
        $snapshot = $this->trackingTargetSnapshot($leg);
        if (! is_numeric($snapshot['latitude'] ?? null) || ! is_numeric($snapshot['longitude'] ?? null)) {
            return null;
        }

        return [
            'latitude' => (float) $snapshot['latitude'],
            'longitude' => (float) $snapshot['longitude'],
        ];
    }

    /** @param array{latitude: float, longitude: float} $target */
    private function routeTargetChanged(RiderCurrentLocation $location, array $target): bool
    {
        return $location->route_target_latitude === null
            || $location->route_target_longitude === null
            || abs((float) $location->route_target_latitude - $target['latitude']) > 0.000001
            || abs((float) $location->route_target_longitude - $target['longitude']) > 0.000001;
    }

    private function cooldownActive(RiderCurrentLocation $location): bool
    {
        return $location->route_cooldown_until?->isFuture() === true;
    }

    private function remainingDuration(RiderCurrentLocation $current, float $remainingDistance): int
    {
        $previousDistance = (float) ($current->route_distance_m ?? 0);
        $previousDuration = (int) ($current->route_duration_s ?? 0);
        if ($previousDistance > 0 && $previousDuration > 0) {
            return max(1, (int) ceil($previousDuration * ($remainingDistance / $previousDistance)));
        }

        return max(1, (int) ceil($remainingDistance / max(
            1,
            (float) config('logistics_tracking.routing.eta_speed_mps', 8.33),
        )));
    }

    /** @return array<string, mixed>|null */
    private function routePayload(ShipmentLeg $leg, RiderCurrentLocation $location): ?array
    {
        if (! is_array($location->route_geometry) || count($location->route_geometry) < 2) {
            return null;
        }

        return [
            'distance_m' => (float) ($location->route_distance_m ?? 0),
            'duration_s' => (int) ($location->route_duration_s ?? 0),
            'geometry' => $location->route_geometry,
            'source' => $location->route_source,
            'route_version' => (int) $location->route_version,
            'active_stop_id' => (int) $leg->id,
            'updated_at' => $location->route_updated_at?->toISOString(),
            'off_route_state' => $location->route_off_route_state ?: 'on_route',
        ];
    }

    /** @return array<int, string> */
    private function trackableStatusValues(): array
    {
        return array_map(
            static fn (ShipmentLegStatus $status): string => $status->value,
            self::TRACKABLE_LEG_STATUSES,
        );
    }

    private function isCustomerTrackableLeg(ShipmentLeg $leg): bool
    {
        if ($this->isRepairPickupTrackingLeg($leg) || $this->isRetailRefundReturnTrackingLeg($leg)) {
            return true;
        }

        return $leg->status === ShipmentLegStatus::IN_TRANSIT
            && data_get($leg->destination_snapshot, 'type') === 'customer';
    }

    private function isRepairPickupTrackingLeg(ShipmentLeg $leg): bool
    {
        $leg->loadMissing('shipment');

        if ($leg->shipment?->source_type !== 'repair_request'
            || $leg->shipment?->purpose !== 'repair_pickup'
            || $leg->leg_type !== 'inbound') {
            return false;
        }

        $prePickup = $leg->picked_up_at === null
            && in_array($leg->status, [
                ShipmentLegStatus::ASSIGNED,
                ShipmentLegStatus::PICKUP_SCHEDULED,
            ], true)
            && data_get($leg->origin_snapshot, 'type') === 'customer';
        $toShop = $leg->picked_up_at !== null
            && $leg->status === ShipmentLegStatus::IN_TRANSIT
            && data_get($leg->destination_snapshot, 'type') === 'shop';

        return $prePickup || $toShop;
    }

    private function isRetailRefundReturnTrackingLeg(ShipmentLeg $leg): bool
    {
        $leg->loadMissing('shipment');

        if ($leg->shipment?->source_type !== 'order_refund'
            || $leg->shipment?->purpose !== 'refund_return'
            || $leg->leg_type !== 'return_to_shop') {
            return false;
        }

        $prePickup = $leg->picked_up_at === null
            && in_array($leg->status, [
                ShipmentLegStatus::ASSIGNED,
                ShipmentLegStatus::PICKUP_SCHEDULED,
            ], true)
            && data_get($leg->origin_snapshot, 'type') === 'customer';
        $toShop = $leg->picked_up_at !== null
            && $leg->status === ShipmentLegStatus::IN_TRANSIT
            && data_get($leg->destination_snapshot, 'type') === 'shop';

        return $prePickup || $toShop;
    }

    /** @return array<string, mixed> */
    private function trackingTargetSnapshot(ShipmentLeg $leg): array
    {
        $isCustomerPickup = (
            $this->isRepairPickupTrackingLeg($leg)
            || $this->isRetailRefundReturnTrackingLeg($leg)
        ) && $leg->picked_up_at === null;
        $snapshot = $isCustomerPickup
            ? $leg->origin_snapshot
            : $leg->destination_snapshot;

        return is_array($snapshot) ? $snapshot : [];
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
            && $this->isCustomerTrackableLeg($leg)
            && (! $leg->delivery_batch_id || $this->isCurrentBatchLeg($leg, $assignment));
    }

    /** @return array<string, mixed> */
    private function liveLocationPayload(RiderCurrentLocation $location, Carbon $staleAt): array
    {
        $leg = $location->leg;
        $shipment = $leg?->shipment;
        $snapshot = $leg ? $this->trackingTargetSnapshot($leg) : [];
        $coordinate = static fn (string $key): ?float => is_numeric($snapshot[$key] ?? null)
            ? (float) $snapshot[$key]
            : null;
        $deliveryType = $this->deliveryTypes->resolve($shipment, $leg);

        return [
            'leg_id' => $leg?->id,
            'shipment_id' => $shipment?->id,
            'shipment_number' => $shipment?->shipment_number,
            'delivery_type' => $deliveryType['delivery_type'],
            'delivery_label' => $deliveryType['delivery_label'],
            'shipment_reference' => $shipment
                ? 'Shipment #' . ($shipment->shipment_number ?? $shipment->id)
                : null,
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
