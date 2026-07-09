<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\Logistics\DeliveryEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticsNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_notification_is_created_for_customer_visible_delivery_event(): void
    {
        Notification::query()->delete();
        $user = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $user->id]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $order->shop_owner_id,
            'source_type' => 'order',
            'source_id' => $order->id,
        ]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);

        app(DeliveryEventService::class)->record($shipment, $leg, [
            'event_type' => 'in_transit',
            'visibility' => 'customer',
            'message' => 'Your order is in transit.',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'logistics_in_transit',
            'action_url' => "/tracking/shipments/{$shipment->id}",
        ]);
    }
}
