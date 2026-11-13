<?php

namespace Database\Factories\Logistics;

use App\Models\Logistics\CourierProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

class CourierProviderFactory extends Factory
{
    protected $model = CourierProvider::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'provider_type' => 'manual',
            'supports_api' => false,
            'active' => true,
        ];
    }
}
