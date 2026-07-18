<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StockRequestApproval>
 */
class StockRequestApprovalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_number' => fake()->unique()->numerify('SR-####-###'),
            'shop_owner_id' => ShopOwner::factory(),
            'inventory_item_id' => InventoryItem::factory(),
            'product_name' => fake()->words(3, true),
            'sku_code' => fake()->unique()->bothify('SKU-#####'),
            'quantity_needed' => fake()->numberBetween(1, 100),
            'priority' => 'medium',
            'status' => 'pending',
            'requested_by' => User::factory(),
            'requested_date' => now(),
        ];
    }
}
