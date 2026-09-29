<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'shop_owner_id' => ShopOwner::factory(),
            'customer_id' => User::factory(),
            'order_number' => 'ORD-' . $this->faker->unique()->numerify('######'),
            'total_amount' => 1000,
            'shipping_fee' => 0,
            'status' => 'pending',
            'customer_name' => $this->faker->name(),
            'customer_email' => $this->faker->safeEmail(),
            'customer_phone' => '09123456789',
            'customer_address' => $this->faker->address(),
            'payment_method' => 'paymongo',
            'payment_status' => 'paid',
        ];
    }
}
