<?php

namespace App\Services\Logistics;

use App\Enums\Logistics\ShipmentLegStatus;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Models\User;
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
        $this->assertTransitionAllowed(
            $leg,
            $leg->requires_delivery_proof ? ['awaiting_proof_approval'] : ['in_transit', 'delivery_attempted'],
            'delivered'
        );

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

    public function cancel(ShipmentLeg $leg, User|ShopOwner $actor): ShipmentLeg
    {
        $leg->loadMissing('shipment');
        $this->assertTransitionAllowed($leg, ['delivery_attempted'], 'cancelled');
        $attempt = $leg->attempts()->latest('attempted_at')->firstOrFail();

        return DB::transaction(function () use ($leg, $attempt, $actor) {
            $leg->update(['status' => 'cancelled']);
            $this->syncShipmentStatus($leg);

            $metadata = ['reason_code' => $attempt->reason_code];
            $this->events->record($leg->shipment, $leg, [
                'event_type' => 'delivery_cancelled',
                'visibility' => 'internal',
                'message' => 'Delivery cancelled by dispatcher.',
                'metadata' => $metadata,
                'created_by_type' => $actor::class,
                'created_by_id' => $actor->id,
            ]);
            $this->events->record($leg->shipment, $leg, [
                'event_type' => 'delivery_cancelled',
                'visibility' => 'customer',
                'message' => $this->customerCancellationMessage($attempt->reason_code),
                'metadata' => $metadata,
                'created_by_type' => $actor::class,
                'created_by_id' => $actor->id,
            ]);

            return $leg->fresh();
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
        $statuses = $shipment->legs()->pluck('status')
            ->map(fn (ShipmentLegStatus $status) => $status->value);

        if ($statuses->isNotEmpty() && $statuses->every(fn ($status) => $status === 'cancelled')) {
            $shipment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            return;
        }

        if ($statuses->isNotEmpty()
            && $statuses->every(fn ($status) => in_array($status, ['delivered', 'cancelled'], true))
            && $statuses->contains('delivered')) {
            $shipment->update(['status' => 'completed', 'completed_at' => now()]);
            $this->completeShopOwnedRetailOrder($shipment);
            $this->completeShopOwnedReturn($shipment);
            return;
        }

        $shipment->update(['status' => 'active']);
    }

    private function customerCancellationMessage(?string $reasonCode): string
    {
        return match ($reasonCode) {
            'recipient_unavailable' => 'Delivery cancelled: recipient unavailable.',
            'wrong_or_incomplete_address' => 'Delivery cancelled: wrong or incomplete address.',
            'recipient_refused' => 'Delivery cancelled: recipient refused.',
            'vehicle_or_delivery_problem' => 'Delivery cancelled: delivery problem.',
            default => 'Delivery cancelled.',
        };
    }

    private function completeShopOwnedRetailOrder($shipment): void
    {
        if ($shipment->source_type !== 'order') {
            return;
        }

        Order::query()
            ->whereKey($shipment->source_id)
            ->whereRaw('LOWER(carrier_company) = ?', ['shop-owned logistics'])
            ->where('status', 'shipped')
            ->update(['status' => 'completed']);
    }

    private function completeShopOwnedReturn($shipment): void
    {
        if ($shipment->source_type !== 'order_refund' || $shipment->purpose !== 'refund_return') {
            return;
        }

        OrderRefund::query()
            ->whereKey($shipment->source_id)
            ->where('return_source', 'staff')
            ->whereRaw('LOWER(staff_return_carrier) = ?', ['shop-owned logistics'])
            ->where('return_status', 'pending_staff_pickup')
            ->update(['return_status' => 'in_transit', 'staff_return_shipped_at' => now()]);
    }
}
