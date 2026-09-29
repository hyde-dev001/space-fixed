<?php

namespace Database\Factories;

use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'po_number' => fake()->unique()->numerify('PO-######'),
            'shop_owner_id' => ShopOwner::factory(),
            'supplier_id' => Supplier::factory(),
            'product_name' => fake()->words(3, true),
            'quantity' => 1,
            'unit_cost' => 100,
            'total_cost' => 100,
            'status' => 'draft',
            'ordered_by' => User::factory(),
            'ordered_date' => now(),
        ];
    }
}
