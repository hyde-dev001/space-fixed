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

        return $leg->proofs()->create([
            ...$validator->validated(),
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
}
