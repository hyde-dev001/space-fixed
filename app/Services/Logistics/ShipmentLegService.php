<?php

namespace App\Services\Logistics;

use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShipmentLegService
{
    public function __construct(
        private ProofService $proofs,
        private DeliveryEventService $events
    ) {
    }

    public function markPickedUp(ShipmentLeg $leg): ShipmentLeg
    {
        $leg->loadMissing('shipment');
        $this->assertTransitionAllowed($leg, ['assigned', 'pickup_scheduled', 'delivery_attempted'], 'picked up');

        if (!$this->proofs->hasRequiredPickupProof($leg)) {
            throw ValidationException::withMessages(['proof' => 'Pickup proof is required before marking this leg picked up.']);
        }

        return $this->transition($leg, 'picked_up', ['picked_up_at' => now()], 'picked_up', 'Shipment leg picked up.');
    }

    public function markInTransit(ShipmentLeg $leg): ShipmentLeg
    {
        $this->assertTransitionAllowed($leg, ['picked_up'], 'in transit');

        return $this->transition($leg, 'in_transit', [], 'in_transit', 'Shipment leg is in transit.');
    }

    public function markDelivered(ShipmentLeg $leg): ShipmentLeg
    {
        $leg->loadMissing('shipment');
        $this->assertTransitionAllowed($leg, ['in_transit', 'delivery_attempted'], 'delivered');

        if (!$this->proofs->hasRequiredDeliveryProof($leg)) {
            throw ValidationException::withMessages(['proof' => 'Delivery proof is required before marking this leg delivered.']);
        }

        return $this->transition($leg, 'delivered', ['delivered_at' => now()], 'delivered', 'Shipment leg delivered.');
    }

    public function recordFailedAttempt(ShipmentLeg $leg, array $payload): DeliveryAttempt
    {
        $leg->loadMissing('shipment');
        $this->assertTransitionAllowed($leg, ['picked_up', 'in_transit', 'delivery_attempted'], 'delivery attempted');

        if (empty($payload['reason_code'])) {
            throw ValidationException::withMessages(['reason_code' => 'Attempt reason is required.']);
        }

        return DB::transaction(function () use ($leg, $payload) {
            $attempt = $leg->attempts()->create([
                'attempt_type' => $payload['attempt_type'] ?? 'delivery',
                'status' => 'failed',
                'reason_code' => $payload['reason_code'],
                'notes' => $payload['notes'] ?? null,
                'attempted_at' => $payload['attempted_at'] ?? now(),
                'next_attempt_at' => $payload['next_attempt_at'] ?? null,
                'recorded_by_type' => $payload['recorded_by_type'] ?? null,
                'recorded_by_id' => $payload['recorded_by_id'] ?? null,
            ]);

            $leg->update(['status' => 'delivery_attempted', 'failed_at' => now()]);
            $leg->shipment->update(['status' => 'active']);
            $this->events->record($leg->shipment, $leg, [
                'event_type' => 'delivery_attempt_failed',
                'visibility' => 'customer',
                'message' => 'Delivery attempt failed.',
                'metadata' => ['reason_code' => $payload['reason_code']],
            ]);

            return $attempt;
        });
    }

    private function transition(ShipmentLeg $leg, string $status, array $extra, string $eventType, string $message): ShipmentLeg
    {
        $leg->loadMissing('shipment');

        return DB::transaction(function () use ($leg, $status, $extra, $eventType, $message) {
            $leg->update(['status' => $status, ...$extra]);
            $this->syncShipmentStatus($leg);

            $this->events->record($leg->shipment, $leg, [
                'event_type' => $eventType,
                'visibility' => in_array($eventType, ['in_transit', 'delivered'], true) ? 'customer' : 'internal',
                'message' => $message,
            ]);

            return $leg->fresh();
        });
    }

    private function assertTransitionAllowed(ShipmentLeg $leg, array $fromStatuses, string $target): void
    {
        $current = $leg->status->value;

        if (!in_array($current, $fromStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => "Shipment leg cannot be marked {$target} from {$current}.",
            ]);
        }
    }

    private function syncShipmentStatus(ShipmentLeg $leg): void
    {
        $shipment = $leg->shipment;

        if ($shipment->legs()->exists() && !$shipment->legs()->where('status', '!=', 'delivered')->exists()) {
            $shipment->update(['status' => 'completed', 'completed_at' => now()]);
            return;
        }

        if (in_array($leg->status->value, ['assigned', 'picked_up', 'in_transit', 'delivery_attempted'], true)) {
            $shipment->update(['status' => 'active']);
        }
    }
}
