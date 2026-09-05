<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'product_name' => fake()->words(3, true),
            'ordered_quantity' => 1,
            'unit_cost' => 100,
            'line_total' => 100,
            'quantity_multiplier' => 1,
            'eligible_size_ids' => [],
            'source' => 'manual',
        ];
    }
}
