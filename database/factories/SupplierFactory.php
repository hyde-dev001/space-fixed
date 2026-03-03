<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_owner_id' => \App\Models\ShopOwner::factory(),
            'name' => fake()->company(),
            'contact_person' => fake()->name(),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'country' => 'Philippines',
            'payment_terms' => fake()->randomElement(['Net 30', 'Net 60', 'COD', 'Advance Payment']),
            'lead_time_days' => fake()->numberBetween(1, 30),
            'is_active' => true,
            'notes' => fake()->optional()->paragraph(),
        ];
    }
}
