<?php

namespace Database\Factories;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PurchaseRequest>
 */
class PurchaseRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pr_number' => fake()->unique()->numerify('PR-######'),
            'shop_owner_id' => ShopOwner::factory(),
            'product_name' => fake()->words(3, true),
            'quantity' => 1,
            'unit_cost' => 100,
            'total_cost' => fn (array $attributes) => $attributes['quantity'] * $attributes['unit_cost'],
            'priority' => 'medium',
            'justification' => fake()->sentence(),
            'status' => 'draft',
            'requested_by' => User::factory(),
            'requested_date' => now(),
        ];
    }
}
