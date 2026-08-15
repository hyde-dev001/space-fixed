<?php

namespace App\Services\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArrivalService
{
    private const EXCEPTION_REASONS = [
        'gps_inaccurate',
        'pin_incorrect',
        'alternate_meeting_point',
        'access_restriction',
        'safety_concern',
        'other',
    ];

    public function __construct(
        private DeliveryEventService $events,
        private RiderActiveWorkGuard $activeWork,
    ) {}

    public function record(ShipmentLeg $leg, Authenticatable $actor, array $payload): DeliveryEvent
    {
        return DB::transaction(function () use ($leg, $actor, $payload) {
            $leg = ShipmentLeg::query()
                ->with('shipment.shopOwner.logisticsSetting')
                ->lockForUpdate()
                ->findOrFail($leg->id);
            $eventType = $payload['arrival_type'] === 'pickup'
                ? 'pickup_arrived'
                : 'dropoff_arrived';
            $assignment = $leg->assignments()
                ->whereIn('status', ['assigned', 'accepted'])
                ->whereHas('riderProfile', fn ($query) => $query
                    ->where('linked_type', $actor::class)
                    ->where('linked_id', $actor->getAuthIdentifier()))
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $assignment) {
                throw ValidationException::withMessages([
                    'assignment' => ['This delivery is no longer assigned to you. Refresh and try again.'],
                ]);
            }

            if ($existing = $this->eventForAssignment($leg, $eventType, $assignment)) {
                return $existing;
            }

            $this->activeWork->assertCanAdvanceLeg($assignment->riderProfile, $leg);

            if ($leg->delivery_batch_id) {
                $batch = DeliveryBatch::query()->lockForUpdate()->find($leg->delivery_batch_id);
                if (! $batch || $batch->status !== 'in_progress') {
                    throw ValidationException::withMessages([
                        'batch' => ['Start this batch before recording an arrival.'],
                    ]);
                }
            }

            $allowed = $payload['arrival_type'] === 'pickup'
                ? ['assigned', 'pickup_scheduled']
                : ['in_transit'];
            if (! in_array($leg->status->value, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => ['This delivery is no longer ready for that arrival action. Refresh and try again.'],
                ]);
            }

            $radius = (int) ($leg->shipment->shopOwner->logisticsSetting?->arrival_radius_m ?? 100);
            $target = $payload['arrival_type'] === 'pickup'
                ? $leg->origin_snapshot
                : $leg->destination_snapshot;
            $check = $this->classify($target, $payload, $radius);
            $this->requireExceptionReason($check, $payload);

            return $this->events->record($leg->shipment, $leg, [
                'event_type' => $eventType,
                'visibility' => 'internal',
                'message' => $this->message($payload['arrival_type'], $check['result']),
                'metadata' => [
                    ...$check,
                    'accuracy_m' => $payload['accuracy_m'] ?? null,
                    'captured_at' => $payload['captured_at'] ?? null,
                    'latitude' => $payload['latitude'] ?? null,
                    'longitude' => $payload['longitude'] ?? null,
                    'exception_reason' => $payload['exception_reason'] ?? null,
                    'exception_notes' => $payload['exception_notes'] ?? null,
                    'delivery_assignment_id' => $assignment->id,
                ],
                'created_by_type' => $actor::class,
                'created_by_id' => $actor->getAuthIdentifier(),
            ]);
        });
    }

    public function eventForAssignment(
        ShipmentLeg $leg,
        string $eventType,
        ?DeliveryAssignment $assignment,
    ): ?DeliveryEvent {
        $events = $leg->relationLoaded('events')
            ? $leg->events->where('event_type', $eventType)
            : $leg->events()->where('event_type', $eventType)->get();

        if (! $assignment) {
            return $events->sortByDesc('id')->first();
        }

        $assignmentStartedAt = $assignment->accepted_at
            ?? $assignment->assigned_at
            ?? $assignment->created_at;

        return $events->sortByDesc('id')->first(function (DeliveryEvent $event) use ($assignment, $assignmentStartedAt) {
            $eventAssignmentId = data_get($event->metadata, 'delivery_assignment_id');
            if ($eventAssignmentId !== null) {
                return (int) $eventAssignmentId === (int) $assignment->id;
            }

            return $assignmentStartedAt
                && $event->created_at
                && $event->created_at->greaterThanOrEqualTo($assignmentStartedAt);
        });
    }

    private function classify(?array $target, array $payload, int $radius): array
    {
        $targetLatitude = data_get($target, 'latitude');
        $targetLongitude = data_get($target, 'longitude');
        if (! is_numeric($targetLatitude) || ! is_numeric($targetLongitude)) {
            return $this->check('location_unavailable', null, $radius);
        }

        foreach (['latitude', 'longitude', 'accuracy_m', 'captured_at'] as $field) {
            if (! isset($payload[$field])) {
                return $this->check('location_unavailable', null, $radius);
            }
        }

        $capturedAt = Carbon::parse($payload['captured_at']);
        $now = now();
        if ($capturedAt->lt($now->copy()->subMinutes(5)) || $capturedAt->gt($now->copy()->addMinute())) {
            return $this->check('location_unavailable', null, $radius);
        }

        if ((float) $payload['accuracy_m'] > 5000) {
            return $this->check('location_unavailable', null, $radius);
        }

        if ((float) $payload['accuracy_m'] > $radius) {
            return $this->check('low_accuracy', null, $radius);
        }

        $distance = $this->distanceInMetres(
            (float) $targetLatitude,
            (float) $targetLongitude,
            (float) $payload['latitude'],
            (float) $payload['longitude']
        );

        return $this->check($distance > $radius ? 'outside_geofence' : 'verified', round($distance, 1), $radius);
    }

    private function check(string $result, ?float $distance, int $radius): array
    {
        return ['result' => $result, 'distance_m' => $distance, 'radius_m' => $radius];
    }

    private function requireExceptionReason(array $check, array $payload): void
    {
        if ($check['result'] === 'verified') {
            return;
        }

        $messages = [
            'arrival_result' => [$check['result']],
        ];

        if (! in_array($payload['exception_reason'] ?? null, self::EXCEPTION_REASONS, true)) {
            throw ValidationException::withMessages([
                ...$messages,
                'exception_reason' => ['Choose why you need to continue outside the normal arrival check.'],
            ]);
        }

        if ($payload['exception_reason'] === 'other' && blank($payload['exception_notes'] ?? null)) {
            throw ValidationException::withMessages([
                ...$messages,
                'exception_notes' => ['Add a short note for the other reason.'],
            ]);
        }
    }

    private function message(string $type, string $result): string
    {
        $place = $type === 'pickup' ? 'pickup' : 'customer location';

        return $result === 'verified'
            ? "Rider arrived at the {$place}."
            : "Rider recorded arrival at the {$place} with a location exception.";
    }

    private function distanceInMetres(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $latitude = deg2rad($lat2 - $lat1);
        $longitude = deg2rad($lon2 - $lon1);
        $a = sin($latitude / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($longitude / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
