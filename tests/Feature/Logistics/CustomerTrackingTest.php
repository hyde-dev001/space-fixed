<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\RepairRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_only_view_own_order_tracking(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $order->shop_owner_id,
            'source_type' => 'order',
            'source_id' => $order->id,
        ]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        DeliveryEvent::factory()->create([
            'shipment_id' => $shipment->id,
            'shipment_leg_id' => $leg->id,
            'event_type' => 'in_transit',
            'visibility' => 'customer',
        ]);
        DeliveryEvent::factory()->create([
            'shipment_id' => $shipment->id,
            'shipment_leg_id' => $leg->id,
            'event_type' => 'leg_assigned',
            'visibility' => 'internal',
        ]);

        $this->actingAs($other, 'user')
            ->get("/tracking/shipments/{$shipment->id}")
            ->assertForbidden();

        $this->actingAs($customer, 'user')
            ->get("/tracking/shipments/{$shipment->id}")
            ->assertOk();
    }

    public function test_customer_repair_listing_includes_logistics_tracking_shipments(): void
    {
        $customer = User::factory()->create();
        $repair = RepairRequest::factory()->create(['user_id' => $customer->id]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $repair->shop_owner_id,
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_return',
        ]);

        $this->actingAs($customer, 'user')
            ->getJson('/api/customer/repairs')
            ->assertOk()
            ->assertJsonPath('data.0.logistics_shipments.0.id', $shipment->id)
            ->assertJsonPath('data.0.logistics_shipments.0.purpose', 'repair_return');
    }
}
