<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderRefundFactory extends Factory
{
    protected $model = OrderRefund::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'customer_id' => User::factory(),
            'shop_owner_id' => ShopOwner::factory(),
            'flow_type' => 'request_approval',
            'status' => 'approved',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'pending_customer_shipment',
            'amount' => 1000,
            'currency' => 'PHP',
            'requested_refund_method' => 'original_payment',
            'reason_code' => 'defective',
            'idempotency_key' => 'refund-' . $this->faker->unique()->uuid(),
            'requested_at' => now(),
        ];
    }
}
