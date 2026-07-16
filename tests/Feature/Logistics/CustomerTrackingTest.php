<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\RepairRequest;
use App\Models\User;
use App\Services\Logistics\CustomerTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_my_orders_includes_shop_owned_tracking_status_and_rider(): void
    {
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'processing',
            'carrier_company' => 'Shop-owned logistics',
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $order->shop_owner_id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
            'status' => 'active',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'in_transit',
            'tracking_number' => 'SHP-TRACK-1001',
        ]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $order->shop_owner_id,
            'name' => 'Marco Santos',
            'phone' => '09053338826',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);

        $this->actingAs($customer, 'user')
            ->get('/my-orders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('UserSide/Orders/MyOrders')
                ->where('orders.0.logistics_shipment_id', $shipment->id)
                ->where('orders.0.delivery_status', 'in_transit')
                ->where('orders.0.delivery_tracking_number', 'SHP-TRACK-1001')
                ->where('orders.0.delivery_rider_name', 'Marco Santos')
                ->where('orders.0.delivery_rider_phone', '09053338826'));
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

    public function test_customer_payload_includes_estimate_but_hides_override_reason(): void
    {
        $shipment = Shipment::factory()->create();
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning', 'schedule_status' => 'scheduled',
            'schedule_override_reason' => 'Internal capacity override',
        ]);

        $payload = app(CustomerTrackingService::class)->payload($shipment);

        $this->assertSame('2026-07-15', $payload['legs'][0]['scheduled_delivery_date']);
        $this->assertSame('morning', $payload['legs'][0]['delivery_window']);
        $this->assertArrayNotHasKey('schedule_override_reason', $payload['legs'][0]);
    }
}
