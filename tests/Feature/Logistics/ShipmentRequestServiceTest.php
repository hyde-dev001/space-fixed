<?php

namespace Tests\Feature\Logistics;

use App\Models\Order;
use App\Models\ShopOwner;
use App\Services\Logistics\ShipmentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_shipment_with_one_outbound_leg_for_order(): void
    {
        $shop = ShopOwner::factory()->create();
        $order = Order::factory()->create(['shop_owner_id' => $shop->id]);

        $shipment = app(ShipmentRequestService::class)->requestShipment([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
            'legs' => [[
                'leg_type' => 'outbound',
                'origin_snapshot' => ['name' => $shop->shop_name ?? $shop->business_name, 'type' => 'shop'],
                'destination_snapshot' => ['name' => $order->customer_name, 'type' => 'customer'],
            ]],
        ]);

        $this->assertSame('requested', $shipment->status->value);
        $this->assertCount(1, $shipment->legs);
        $this->assertDatabaseHas('delivery_events', [
            'shipment_id' => $shipment->id,
            'event_type' => 'shipment_requested',
        ]);
    }
}
