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
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'notes' => $this->faker->sentence(),
            'metadata' => [],
            'recorded_at' => now(),
        ];
    }
}
