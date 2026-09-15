<?php

namespace Database\Factories\Logistics;

use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShipmentLegFactory extends Factory
{
    protected $model = ShipmentLeg::class;

    public function definition(): array
    {
        return [
            'shipment_id' => Shipment::factory(),
            'sequence' => 1,
            'leg_type' => 'outbound',
            'status' => 'pending',
            'origin_snapshot' => ['type' => 'shop', 'name' => 'Shop'],
            'destination_snapshot' => ['type' => 'customer', 'name' => 'Customer'],
            'requires_pickup_proof' => false,
            'requires_delivery_proof' => true,
        ];
    }
}
