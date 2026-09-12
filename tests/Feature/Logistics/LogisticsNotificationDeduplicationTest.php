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

class LogisticsNotificationDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_customer_event_creates_one_notification(): void
    {
        $customer = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $order->shop_owner_id, 'source_type' => 'order', 'source_id' => $order->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $events = app(DeliveryEventService::class);

        foreach ([1, 2] as $_) $events->record($shipment, $leg, ['event_type' => 'in_transit', 'visibility' => 'customer', 'message' => 'Out for delivery.']);

        $this->assertSame(1, Notification::where('user_id', $customer->id)->where('type', 'logistics_in_transit')->count());
    }
}
