<?php

namespace App\Services\Logistics;

use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderRefund;
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

    public function confirmPickup(ShipmentLeg $leg, HandoffProof $proof, RiderProfile $rider): ShipmentLeg
    {
        return DB::transaction(function () use ($leg, $proof, $rider) {
            $leg = ShipmentLeg::query()->lockForUpdate()->findOrFail($leg->id);
            $proof = HandoffProof::query()->lockForUpdate()->findOrFail($proof->id);
            if ($proof->shipment_leg_id !== $leg->id || $proof->handoff_type !== 'pickup'
                || !$leg->assignments()->where('rider_profile_id', $rider->id)->whereIn('status', ['assigned', 'accepted'])->exists()) {
                throw ValidationException::withMessages(['proof' => 'Pickup proof is not assigned to this rider.']);
            }
            if ($leg->status->value === 'picked_up' && $proof->review_status === 'approved') return $leg;
            $proof->update(['review_status' => 'approved', 'reviewed_by_type' => RiderProfile::class, 'reviewed_by_id' => $rider->id, 'reviewed_at' => now()]);
            return $this->markPickedUp($leg);
        });
    }

    public function rejectPickup(ShipmentLeg $leg, HandoffProof $proof, RiderProfile $rider, string $reason): ShipmentLeg
    {
        if (!filled($reason)) throw ValidationException::withMessages(['reason' => 'Rejection reason is required.']);
        return DB::transaction(function () use ($leg, $proof, $rider, $reason) {
            $leg = ShipmentLeg::query()->lockForUpdate()->findOrFail($leg->id);
            $proof = HandoffProof::query()->lockForUpdate()->findOrFail($proof->id);
            if (!$leg->assignments()->where('rider_profile_id', $rider->id)->whereIn('status', ['assigned', 'accepted'])->exists()) abort(403);
            $proof->update(['review_status' => 'rejected', 'rejection_reason' => $reason, 'reviewed_by_type' => RiderProfile::class, 'reviewed_by_id' => $rider->id, 'reviewed_at' => now()]);
            return $leg;
        });
    }

    public function markOutForDelivery(ShipmentLeg $leg, RiderProfile $rider): ShipmentLeg
    {
        return DB::transaction(function () use ($leg, $rider) {
            $leg = ShipmentLeg::query()->with(['shipment', 'deliveryBatch'])->lockForUpdate()->findOrFail($leg->id);
            if ($leg->status->value === 'in_transit' && $leg->out_for_delivery_at) return $leg;
            if ($leg->status->value !== 'picked_up' || $leg->deliveryBatch?->status !== 'in_progress'
                || !$leg->assignments()->where('rider_profile_id', $rider->id)->where('status', 'accepted')->exists()) {
                throw ValidationException::withMessages(['status' => 'This stop cannot start delivery.']);
            }
            return $this->transition($leg, 'in_transit', ['out_for_delivery_at' => now()], 'out_for_delivery', 'Your delivery is out for delivery.');
        });
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

        $delivered = $this->transition($leg, 'delivered', ['delivered_at' => now()], 'delivered', 'Shipment leg delivered.');
        $batch = $delivered->deliveryBatch;
        if ($batch && !$batch->legs()->where('status', '!=', 'delivered')->exists()) {
            $batch->update(['status' => 'completed', 'completed_at' => now()]);
        }
        return $delivered;
    }

    public function cancel(ShipmentLeg $leg, string $customerReason): ShipmentLeg
    {
        $leg->loadMissing('shipment');
        $this->assertTransitionAllowed($leg, ['delivery_attempted'], 'cancelled');

        return DB::transaction(function () use ($leg, $customerReason) {
            $leg->update(['status' => 'cancelled']);
            $this->syncShipmentStatus($leg);

            $this->events->record($leg->shipment, $leg, [
                'event_type' => 'delivery_cancelled',
                'message' => 'Dispatcher cancelled the delivery.',
            ]);
            $this->events->record($leg->shipment, $leg, [
                'event_type' => 'delivery_cancelled',
                'visibility' => 'customer',
                'message' => "Delivery cancelled: {$customerReason}.",
            ]);

            return $leg->fresh();
        });
    }

    public function resolveRetry(ShipmentLeg $leg, string $reason): ShipmentLeg
    {
        if (!filled($reason)) throw ValidationException::withMessages(['reason' => 'Resolution reason is required.']);
        return DB::transaction(function () use ($leg, $reason) {
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            $this->assertTransitionAllowed($leg, ['needs_resolution'], 'scheduled for retry');
            $leg->update(['status' => 'pending', 'resolution_type' => 'retry', 'resolution_reason' => $reason, 'scheduled_delivery_date' => now(config('app.shop_timezone', 'Asia/Manila'))->addDay()->toDateString()]);
            $this->events->record($leg->shipment, $leg, ['event_type' => 'delivery_retry_authorized', 'visibility' => 'customer', 'message' => 'Another delivery attempt has been scheduled.']);
            return $leg->fresh();
        });
    }

    public function requireReturn(ShipmentLeg $leg, string $reason): ShipmentLeg
    {
        if (!filled($reason)) throw ValidationException::withMessages(['reason' => 'Return reason is required.']);
        return DB::transaction(function () use ($leg, $reason) {
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            $this->assertTransitionAllowed($leg, ['needs_resolution'], 'return required');
            $leg->update(['resolution_type' => 'return_required', 'resolution_reason' => $reason]);
            $this->events->record($leg->shipment, $leg, ['event_type' => 'return_required', 'visibility' => 'customer', 'message' => 'The parcel is awaiting return to the shop.']);
            return $leg->fresh();
        });
    }

    public function recordFailedAttempt(ShipmentLeg $leg, array $payload, bool $allowAssigned = false): DeliveryAttempt
    {
        $leg->loadMissing('shipment');
        $this->assertTransitionAllowed($leg, $allowAssigned ? ['assigned', 'picked_up', 'in_transit', 'delivery_attempted'] : ['picked_up', 'in_transit', 'delivery_attempted'], 'delivery attempted');

        if (empty($payload['reason_code'])) {
            throw ValidationException::withMessages(['reason_code' => 'Attempt reason is required.']);
        }

        if ($leg->delivery_batch_id && empty($payload['file_path'])) {
            throw ValidationException::withMessages(['proof' => 'A failed-attempt photo is required.']);
        }

        return DB::transaction(function () use ($leg, $payload) {
            $leg = ShipmentLeg::query()->with('shipment.shopOwner.logisticsSetting')->lockForUpdate()->findOrFail($leg->id);
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

            $max = $leg->shipment->shopOwner->logisticsSetting?->max_delivery_attempts ?? 2;
            $current = max(1, $leg->attempt_number);
            $needsResolution = $current >= $max;
            $settings = $leg->shipment->shopOwner->logisticsSetting;
            $next = now(config('app.shop_timezone', 'Asia/Manila'))->addDay();
            while (!in_array($next->dayOfWeekIso, $settings?->operating_days ?? [1, 2, 3, 4, 5, 6], true)
                || in_array($next->toDateString(), $settings?->blackout_dates ?? [], true)) $next->addDay();
            $nextDate = $next->toDateString();
            if (!empty($payload['file_path'])) $leg->proofs()->create([
                'handoff_type' => 'delivery', 'proof_type' => 'photo', 'file_path' => $payload['file_path'],
                'notes' => $payload['notes'] ?? null, 'recorded_at' => now(),
            ]);
            $leg->update([
                'status' => $needsResolution ? 'needs_resolution' : 'pending',
                'failed_at' => now(),
                'attempt_number' => $current + 1,
                'scheduled_delivery_date' => $needsResolution ? $leg->scheduled_delivery_date : $nextDate,
                'delivery_batch_id' => null,
                'stop_sequence' => null,
            ]);
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
        $statuses = $shipment->legs()->pluck('status')->map(fn ($status) => $status->value ?? $status);

        if ($statuses->isNotEmpty() && $statuses->every(fn ($status) => $status === 'cancelled')) {
            $shipment->update(['status' => 'cancelled', 'completed_at' => null]);
            return;
        }

        if ($statuses->isNotEmpty()
            && $statuses->contains('delivered')
            && $statuses->every(fn ($status) => in_array($status, ['delivered', 'cancelled'], true))) {
            $shipment->update(['status' => 'completed', 'completed_at' => now()]);
            $this->completeShopOwnedRetailOrder($shipment);
            $this->completeShopOwnedReturn($shipment);
            return;
        }

        $shipment->update(['status' => 'active', 'completed_at' => null]);
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
