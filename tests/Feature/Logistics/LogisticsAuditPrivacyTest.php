<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Services\Logistics\CustomerTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticsAuditPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_payload_hides_phone_and_internal_resolution(): void
    {
        $order = Order::factory()->create();
        $shipment = Shipment::factory()->create(['shop_owner_id' => $order->shop_owner_id, 'source_type' => 'order', 'source_id' => $order->id]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'destination_snapshot' => ['name' => 'Customer', 'address' => 'Safe address', 'phone' => '09170000000'],
            'resolution_type' => 'return_required', 'resolution_reason' => 'Internal reason',
        ]);

        $payload = app(CustomerTrackingService::class)->payload($shipment);

        $this->assertArrayNotHasKey('phone', $payload['legs'][0]['destination_snapshot']);
        $this->assertArrayNotHasKey('resolution_type', $payload['legs'][0]);
        $this->assertArrayNotHasKey('delivery_batch_id', $payload['legs'][0]);
    }
}
