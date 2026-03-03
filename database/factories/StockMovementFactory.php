<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $types = ['stock_in', 'stock_out', 'adjustment', 'return', 'repair_usage'];
        $type = fake()->randomElement($types);
        $quantityChange = fake()->numberBetween(1, 50);
        
        if (in_array($type, ['stock_out', 'repair_usage'])) {
            $quantityChange = -$quantityChange;
        }
        
        $quantityBefore = fake()->numberBetween(0, 100);
        
        return [
            'inventory_item_id' => \App\Models\InventoryItem::factory(),
            'movement_type' => $type,
            'quantity_change' => $quantityChange,
            'quantity_before' => $quantityBefore,
            'quantity_after' => max(0, $quantityBefore + $quantityChange),
            'reference_type' => fake()->optional()->randomElement(['manual', 'supplier_order', 'repair_request']),
            'reference_id' => fake()->optional()->numberBetween(1, 100),
            'notes' => fake()->optional()->sentence(),
            'performed_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
