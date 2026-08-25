<?php

namespace App\Services\Logistics;

use App\Enums\Logistics\RiderProgressState;
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
            'replaces_proof_id' => ['nullable', 'integer'],
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
        $data['replaces_proof_id'] = isset($data['replaces_proof_id'])
            ? (int) $data['replaces_proof_id']
            : null;

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
            }
            if (filled($data['idempotency_key'] ?? null)) {
                $existing = HandoffProof::query()
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if ((int) $existing->shipment_leg_id !== (int) $leg->id) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => 'This idempotency key has already been used for another proof.',
                        ]);
                    }

                    $this->assertCompatibleReplay($existing, $data);

                    return $existing;
                }
            }

            if ($data['replaces_proof_id'] !== null) {
                $this->assertCanReplace($leg, $data);
            } else {
                if ($data['handoff_type'] === 'delivery'
                    && $leg->rider_progress_state !== RiderProgressState::ACTIVE) {
                    throw ValidationException::withMessages([
                        'rider_progress_state' => 'This delivery is awaiting proof review or correction.',
                    ]);
                }

                if ($rider) {
                    $this->activeWork->assertCanAdvanceLeg($rider, $leg);
                    if ($data['handoff_type'] === 'delivery'
                        && ! $this->arrivals->eventForAssignment($leg, 'dropoff_arrived', $assignment)) {
                        throw ValidationException::withMessages([
                            'arrival' => 'Record your arrival at the customer location before submitting delivery proof.',
                        ]);
                    }
                }

                $this->assertCanRecord($leg, $data['handoff_type']);
            }

            $proof = $leg->proofs()->create([
                ...$data,
                'recorded_at' => now(),
            ]);

            if ($data['handoff_type'] === 'delivery') {
                $leg->update([
                    'status' => 'awaiting_proof_approval',
                    'rider_progress_state' => RiderProgressState::PROOF_SUBMITTED,
                ]);
                $this->events->record($leg->shipment, $leg, [
                    'event_type' => 'proof_required',
                    'message' => 'Delivery proof is awaiting approval.',
                    'metadata' => [
                        'proof_id' => $proof->id,
                        'replaces_proof_id' => $proof->replaces_proof_id,
                        'rider_profile_id' => $rider?->id ?? $assignment?->rider_profile_id,
                    ],
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

    private function assertCanReplace(ShipmentLeg $leg, array $data): void
    {
        if ($data['handoff_type'] !== 'delivery') {
            throw ValidationException::withMessages([
                'handoff_type' => 'Only delivery proof can replace a rejected proof.',
            ]);
        }

        if ($leg->rider_progress_state !== RiderProgressState::PROOF_ACTION_REQUIRED) {
            throw ValidationException::withMessages([
                'rider_progress_state' => 'This delivery is not awaiting a proof correction.',
            ]);
        }

        if (blank($data['idempotency_key'] ?? null)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A replacement proof requires a new idempotency key.',
            ]);
        }

        $replaced = $leg->proofs()
            ->whereKey($data['replaces_proof_id'])
            ->lockForUpdate()
            ->first();
        if (! $replaced
            || $replaced->handoff_type !== 'delivery'
            || $replaced->review_status !== 'rejected') {
            throw ValidationException::withMessages([
                'replaces_proof_id' => 'The replacement must reference the latest rejected delivery proof.',
            ]);
        }

        $latestDeliveryProof = $leg->proofs()
            ->where('handoff_type', 'delivery')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
        if (! $latestDeliveryProof || (int) $latestDeliveryProof->id !== (int) $replaced->id) {
            throw ValidationException::withMessages([
                'replaces_proof_id' => 'The replacement must reference the latest rejected delivery proof.',
            ]);
        }

        $hasPendingProof = $leg->proofs()
            ->where('handoff_type', 'delivery')
            ->where('review_status', 'pending')
            ->lockForUpdate()
            ->exists();
        if ($hasPendingProof) {
            throw ValidationException::withMessages([
                'replaces_proof_id' => 'A delivery proof is already awaiting review.',
            ]);
        }
    }

    private function assertCompatibleReplay(HandoffProof $existing, array $data): void
    {
        foreach ([
            'handoff_type',
            'proof_type',
            'confirmed_by_type',
            'confirmed_by_id',
            'notes',
            'metadata',
            'replaces_proof_id',
        ] as $field) {
            $incoming = $data[$field] ?? null;
            $stored = $existing->getAttribute($field);

            if (in_array($field, ['confirmed_by_id', 'replaces_proof_id'], true)) {
                $incoming = $incoming === null ? null : (int) $incoming;
                $stored = $stored === null ? null : (int) $stored;
            }

            if ($incoming !== $stored) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'This idempotency key was reused with a conflicting proof submission.',
                ]);
            }
        }
    }
}
