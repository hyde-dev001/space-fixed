<?php

namespace App\Services\Logistics;

use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\ShipmentLeg;
use App\Models\User;
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

    public function __construct(private DeliveryEventService $events) {}

    public function record(ShipmentLeg $leg, User $actor, array $payload): DeliveryEvent
    {
        return DB::transaction(function () use ($leg, $actor, $payload) {
            $leg = ShipmentLeg::query()
                ->with('shipment.shopOwner.logisticsSetting')
                ->lockForUpdate()
                ->findOrFail($leg->id);
            $eventType = $payload['arrival_type'] === 'pickup'
                ? 'pickup_arrived'
                : 'dropoff_arrived';

            if ($existing = $leg->events()->where('event_type', $eventType)->first()) {
                return $existing;
            }

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
                ],
                'created_by_type' => User::class,
                'created_by_id' => $actor->id,
            ]);
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

        if (! in_array($payload['exception_reason'] ?? null, self::EXCEPTION_REASONS, true)) {
            throw ValidationException::withMessages([
                'exception_reason' => ['Choose why you need to continue outside the normal arrival check.'],
            ]);
        }

        if ($payload['exception_reason'] === 'other' && blank($payload['exception_notes'] ?? null)) {
            throw ValidationException::withMessages([
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
