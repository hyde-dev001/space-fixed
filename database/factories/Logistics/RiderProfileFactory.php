<?php

namespace Database\Factories\Logistics;

use App\Models\Logistics\RiderProfile;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

class RiderProfileFactory extends Factory
{
    protected $model = RiderProfile::class;

    public function definition(): array
    {
        return [
            'shop_owner_id' => ShopOwner::factory(),
            'rider_type' => 'employee',
            'name' => $this->faker->name(),
            'phone' => $this->faker->numerify('09#########'),
            'availability_status' => 'available',
            'active' => true,
        ];
    }
}
