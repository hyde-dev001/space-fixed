<?php

namespace App\Services\Logistics;

use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProofService
{
    public function __construct(
        private DeliveryEventService $events,
        private RiderActiveWorkGuard $activeWork,
        private ArrivalService $arrivals,
    ) {}

    public function recordProof(ShipmentLeg $leg, array $payload, ?RiderProfile $rider = null): HandoffProof
    {
        $validator = Validator::make($payload, [
            'handoff_type' => ['required', 'in:pickup,delivery,receive'],
            'proof_type' => ['required', 'in:photo,signature,qr,staff_confirmation,customer_confirmation,courier_receipt,tracking_confirmation'],
            'idempotency_key' => ['nullable', 'uuid'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'confirmed_by_type' => ['nullable', 'string', 'max:255'],
            'confirmed_by_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data = $validator->validated();

        return DB::transaction(function () use ($leg, $data, $rider) {
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            $assignment = null;
            if ($rider) {
                $assignment = $leg->assignments()
                    ->where('rider_profile_id', $rider->id)
                    ->whereIn('status', ['assigned', 'accepted'])
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();
                if (! $assignment) {
                    throw ValidationException::withMessages([
                        'assignment' => 'This delivery is no longer assigned to this rider.',
                    ]);
                }
                $this->activeWork->assertCanAdvanceLeg($rider, $leg);
                if ($data['handoff_type'] === 'delivery'
                    && ! $this->arrivals->eventForAssignment($leg, 'dropoff_arrived', $assignment)) {
                    throw ValidationException::withMessages([
                        'arrival' => 'Record your arrival at the customer location before submitting delivery proof.',
                    ]);
                }
            }
            if (filled($data['idempotency_key'] ?? null)) {
                $existing = $leg->proofs()
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $this->assertCanRecord($leg, $data['handoff_type']);
            $proof = $leg->proofs()->create([
                ...$data,
                'recorded_at' => now(),
            ]);

            if ($data['handoff_type'] === 'delivery') {
                $leg->update(['status' => 'awaiting_proof_approval']);
                $this->events->record($leg->shipment, $leg, [
                    'event_type' => 'proof_required',
                    'message' => 'Delivery proof is awaiting approval.',
                ]);
            }

            return $proof;
        });
    }

    public function hasRequiredPickupProof(ShipmentLeg $leg): bool
    {
        if (!$leg->requires_pickup_proof) {
            return true;
        }

        return $leg->proofs()->where('handoff_type', 'pickup')->exists();
    }

    public function hasRequiredDeliveryProof(ShipmentLeg $leg): bool
    {
        if (!$leg->requires_delivery_proof) {
            return true;
        }

        return $leg->proofs()
            ->whereIn('handoff_type', ['delivery', 'receive'])
            ->where('review_status', 'approved')
            ->exists();
    }

    private function assertCanRecord(ShipmentLeg $leg, string $handoffType): void
    {
        $status = $leg->status->value;

        if (in_array($status, ['delivered', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => 'Proof cannot be changed after delivery or cancellation.']);
        }

        if ($leg->leg_type === 'return_to_shop' && $handoffType !== 'receive') {
            throw ValidationException::withMessages(['handoff_type' => 'Return-to-shop legs require return handoff proof.']);
        }

        if ($handoffType === 'pickup' && !in_array($status, ['pending', 'assigned', 'pickup_scheduled'], true)) {
            throw ValidationException::withMessages(['status' => 'Pickup proof can only be recorded before pickup.']);
        }

        if (in_array($handoffType, ['delivery', 'receive'], true) && !in_array($status, ['picked_up', 'in_transit', 'delivery_attempted'], true)) {
            throw ValidationException::withMessages(['status' => 'Delivery proof can only be recorded after pickup and before delivery.']);
        }
    }
}
