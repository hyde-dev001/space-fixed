<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $categories = ['shoes', 'accessories', 'care_products', 'cleaning_materials', 'packaging', 'repair_materials'];
        $brands = ['Nike', 'Adidas', 'Puma', 'New Balance', 'Solespace', 'Angelus'];
        $quantity = fake()->numberBetween(0, 100);
        
        return [
            'shop_owner_id' => \App\Models\ShopOwner::factory(),
            'name' => fake()->words(3, true),
            'sku' => 'SKU-' . strtoupper(fake()->bothify('???-####')),
            'category' => fake()->randomElement($categories),
            'brand' => fake()->randomElement($brands),
            'description' => fake()->optional()->paragraph(),
            'notes' => fake()->optional()->sentence(),
            'unit' => fake()->randomElement(['pcs', 'pairs', 'bottles', 'boxes']),
            'available_quantity' => $quantity,
            'reserved_quantity' => fake()->numberBetween(0, 10),
            'reorder_level' => 10,
            'reorder_quantity' => 50,
            'price' => fake()->randomFloat(2, 500, 10000),
            'cost_price' => fake()->randomFloat(2, 300, 8000),
            'weight' => fake()->randomFloat(2, 0.1, 5),
            'is_active' => true,
            'main_image' => fake()->optional()->imageUrl(640, 480, 'shoes'),
        ];
    }
}
