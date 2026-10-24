<?php

namespace Database\Factories\Logistics;

use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Database\Eloquent\Factories\Factory;

class HandoffProofFactory extends Factory
{
    protected $model = HandoffProof::class;

    public function definition(): array
    {
        return [
            'shipment_leg_id' => ShipmentLeg::factory(),
            'delivery_assignment_id' => fn (array $attributes) => \App\Models\Logistics\DeliveryAssignment::where('shipment_leg_id', $attributes['shipment_leg_id'])->whereIn('status', ['assigned', 'accepted'])->latest('id')->value('id'),
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'notes' => $this->faker->sentence(),
            'metadata' => [],
            'recorded_at' => now(),
        ];
    }
}
