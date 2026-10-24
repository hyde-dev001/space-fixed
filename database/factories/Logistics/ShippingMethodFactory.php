<?php

namespace Database\Factories\Logistics;

use App\Models\Logistics\ShippingMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShippingMethodFactory extends Factory
{
    protected $model = ShippingMethod::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(2, true),
            'carrier_type' => 'internal',
            'requires_assignment' => true,
            'requires_tracking' => false,
            'requires_pickup_proof' => false,
            'requires_delivery_proof' => true,
            'active' => true,
        ];
    }
}
