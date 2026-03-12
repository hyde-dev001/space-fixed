<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SupplierOrder>
 */
class SupplierOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $orderDate = fake()->dateTimeBetween('-30 days', 'now');
        $expectedDate = fake()->dateTimeBetween($orderDate, '+30 days');
        
        return [
            'shop_owner_id' => \App\Models\ShopOwner::factory(),
            'supplier_id' => \App\Models\Supplier::factory(),
            'po_number' => 'PO-' . date('Y') . '-' . fake()->unique()->numberBetween(1000, 9999),
            'status' => fake()->randomElement(['sent', 'confirmed', 'in_transit', 'delivered']),
            'order_date' => $orderDate,
            'expected_delivery_date' => $expectedDate,
            'actual_delivery_date' => null,
            'total_amount' => fake()->randomFloat(2, 5000, 50000),
            'currency' => 'PHP',
            'payment_status' => fake()->randomElement(['unpaid', 'partial', 'paid']),
            'remarks' => fake()->optional()->sentence(),
        ];
    }
}
