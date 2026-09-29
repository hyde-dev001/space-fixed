<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipment_has_legs_and_events(): void
    {
        $shop = ShopOwner::factory()->create();
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        DeliveryEvent::factory()->create([
            'shipment_id' => $shipment->id,
            'shipment_leg_id' => $leg->id,
            'event_type' => 'shipment_requested',
        ]);

        $this->assertCount(1, $shipment->legs);
        $this->assertCount(1, $shipment->events);
        $this->assertTrue($leg->shipment->is($shipment));
    }
}
