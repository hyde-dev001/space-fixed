<?php

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\RetailWarrantyIssuance;
use Illuminate\Database\Eloquent\Factories\Factory;

class RetailWarrantyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'retail_warranty_issuance_id' => RetailWarrantyIssuance::factory(),
            'shop_owner_id' => fn (array $a) => RetailWarrantyIssuance::findOrFail($a['retail_warranty_issuance_id'])->shop_owner_id,
            'order_id' => fn (array $a) => RetailWarrantyIssuance::findOrFail($a['retail_warranty_issuance_id'])->order_id,
            'customer_id' => fn (array $a) => RetailWarrantyIssuance::findOrFail($a['retail_warranty_issuance_id'])->customer_id,
            'order_item_id' => fn (array $a) => OrderItem::create(['order_id' => $a['order_id'], 'product_name' => 'Sneaker', 'product_slug' => 'sneaker', 'price' => 500, 'quantity' => 2, 'subtotal' => 1000])->id,
            'policy_snapshot' => ['title' => 'Product Warranty', 'duration_value' => 1, 'duration_unit' => 'years', 'terms' => 'Manufacturing defects assessment.'],
            'item_snapshot' => ['name' => 'Sneaker', 'size' => '9', 'color' => 'Black', 'quantity' => 2],
            'original_covered_quantity' => 2, 'warranty_start_date' => now(), 'warranty_expiration_date' => now()->addYearNoOverflow(),
        ];
    }
}
