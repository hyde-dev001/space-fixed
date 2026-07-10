<?php

namespace App\Services\Logistics;

use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProofService
{
    public function recordProof(ShipmentLeg $leg, array $payload): HandoffProof
    {
        $leg->refresh();

        $validator = Validator::make($payload, [
            'handoff_type' => ['required', 'in:pickup,delivery,receive'],
            'proof_type' => ['required', 'in:photo,signature,qr,staff_confirmation,customer_confirmation,courier_receipt,tracking_confirmation'],
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
        $this->assertCanRecord($leg, $data['handoff_type']);

        return $leg->proofs()->create([
            ...$data,
            'recorded_at' => now(),
        ]);
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
            ->exists();
    }

    private function assertCanRecord(ShipmentLeg $leg, string $handoffType): void
    {
        $status = $leg->status->value;

        if (in_array($status, ['delivered', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => 'Proof cannot be changed after delivery or cancellation.']);
        }

        if ($handoffType === 'pickup' && !in_array($status, ['pending', 'assigned', 'pickup_scheduled'], true)) {
            throw ValidationException::withMessages(['status' => 'Pickup proof can only be recorded before pickup.']);
        }

        if (in_array($handoffType, ['delivery', 'receive'], true) && !in_array($status, ['picked_up', 'in_transit', 'delivery_attempted'], true)) {
            throw ValidationException::withMessages(['status' => 'Delivery proof can only be recorded after pickup and before delivery.']);
        }
    }
}
