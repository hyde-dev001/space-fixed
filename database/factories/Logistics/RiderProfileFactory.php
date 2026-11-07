<?php

namespace Database\Factories\Logistics;

use App\Models\Logistics\RiderProfile;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RiderProfileFactory extends Factory
{
    protected $model = RiderProfile::class;

    public function definition(): array
    {
        return [
            'shop_owner_id' => ShopOwner::factory(),
            'rider_type' => 'employee',
            'linked_type' => fn (array $attributes) => ($attributes['rider_type'] ?? 'employee') === 'employee' ? User::class : null,
            'linked_id' => fn (array $attributes) => ($attributes['rider_type'] ?? 'employee') === 'employee'
                ? User::factory()->create(['shop_owner_id' => $attributes['shop_owner_id']])->id
                : null,
            'name' => $this->faker->name(),
            'phone' => $this->faker->numerify('09#########'),
            'availability_status' => 'available',
            'active' => true,
        ];
    }

    public function unlinked(): static
    {
        return $this->state([
            'linked_type' => null,
            'linked_id' => null,
        ]);
    }
}
