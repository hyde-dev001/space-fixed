<?php

namespace Database\Factories\Logistics;

use App\Models\Logistics\Shipment;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'shop_owner_id' => ShopOwner::factory(),
            'source_type' => 'manual',
            'source_id' => $this->faker->unique()->numberBetween(1, 1000),
            'purpose' => 'retail_delivery',
            'status' => 'requested',
        ];
    }
}
