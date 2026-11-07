<?php

namespace Database\Factories\Logistics;

use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeliveryEventFactory extends Factory
{
    protected $model = DeliveryEvent::class;

    public function definition(): array
    {
        return [
            'shipment_id' => Shipment::factory(),
            'event_type' => 'shipment_requested',
            'visibility' => 'internal',
            'message' => $this->faker->sentence(),
            'metadata' => [],
        ];
    }
}
