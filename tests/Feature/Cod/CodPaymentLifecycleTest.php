<?php

namespace Tests\Feature\Cod;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OrderReceiptService;
use App\Services\Orders\OrderFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CodPaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customer_receipt_confirmation_does_not_settle_cod_payment(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::SHIPPED,
            'carrier_company' => 'Third-party Logistics',
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);

        $result = app(OrderReceiptService::class)->confirm($order);

        $this->assertSame('confirmed', $result['result']);
        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->paid_at);
    }

    #[Test]
    public function delivery_confirmation_does_not_settle_cod_payment(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::SHIPPED,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);

        $updatedOrder = app(OrderFulfillmentService::class)->confirmDelivered($order, $customer);

        $this->assertSame(OrderStatus::DELIVERED, $updatedOrder->status);
        $this->assertSame('pending', $updatedOrder->payment_status);
        $this->assertNull($updatedOrder->paid_at);
    }
}
