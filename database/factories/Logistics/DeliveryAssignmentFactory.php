<?php

namespace Database\Factories\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeliveryAssignmentFactory extends Factory
{
    protected $model = DeliveryAssignment::class;

    public function definition(): array
    {
        return [
            'shipment_leg_id' => ShipmentLeg::factory(),
            'assignment_type' => 'internal_rider',
            'status' => 'assigned',
            'assigned_at' => now(),
        ];
    }
}
