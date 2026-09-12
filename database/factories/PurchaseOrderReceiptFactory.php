<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'shop_owner_id' => fn (array $attributes) => PurchaseOrder::find($attributes['purchase_order_id'])?->shop_owner_id,
            'source' => 'manual',
            'status' => 'posted',
            'idempotency_key' => fake()->uuid(),
            'received_at' => now(),
        ];
    }
}
