<?php

namespace Tests\Feature\Notifications;

use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationCriticalFlowsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function order_status_transition_dispatches_via_notification_service_not_direct_model_write(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create();
        $customer = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $order = Order::query()->create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-TEST-1001',
            'total_amount' => 1999.00,
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);

        $order->update(['status' => 'shipped']);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $customer->id,
            'type' => 'order_status_update',
            'title' => 'Order Status Updated',
        ]);
    }
}
