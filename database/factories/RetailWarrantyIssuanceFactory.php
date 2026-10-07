<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RetailWarrantyIssuanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->state(['shop_owner_id' => ShopOwner::factory()->approved()->state(['business_type' => 'retail'])]),
            'shop_owner_id' => fn (array $a) => Order::findOrFail($a['order_id'])->shop_owner_id,
            'customer_id' => fn (array $a) => Order::findOrFail($a['order_id'])->customer_id,
            'warranty_number' => 'WRNTY-'.now()->year.'-'.Str::upper(Str::random(16)),
            'shop_snapshot' => ['name' => 'Test Shop'], 'customer_snapshot' => ['name' => 'Buyer', 'email' => 'buyer@example.test'],
            'fulfilled_at' => now(), 'issued_at' => now(), 'business_timezone' => 'Asia/Manila',
        ];
    }
}
