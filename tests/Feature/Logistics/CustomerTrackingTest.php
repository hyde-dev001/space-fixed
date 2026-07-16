<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\RepairRequest;
use App\Models\User;
use App\Services\Logistics\CustomerTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
                ->where('orders.0.delivery_rider_phone', '09053338826')
                ->where('orders.0.delivery_has_failed_attempt', false)
                ->where('orders.0.delivery_scheduled_date', null)
                ->where('orders.0.delivery_window', null));
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

    public function test_customer_payload_exposes_only_safe_latest_failed_attempt_details(): void
    {
        Storage::fake('public');
        $shipment = Shipment::factory()->create();
        $expectedReasons = [
            'recipient_unavailable' => 'Recipient unavailable',
            'wrong_or_incomplete_address' => 'Wrong or incomplete address',
            'recipient_refused' => 'Recipient refused',
            'vehicle_or_delivery_problem' => 'Vehicle or delivery problem',
            'other' => 'Other delivery issue',
            'legacy_reason' => 'Delivery could not be completed',
        ];

        foreach ($expectedReasons as $reasonCode => $reasonLabel) {
            $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
            $path = "logistics-attempt/{$leg->id}/attempt.jpg";
            Storage::disk('public')->put($path, 'photo');
            DeliveryAttempt::query()->create([
                'shipment_leg_id' => $leg->id,
                'attempt_type' => 'delivery',
                'status' => 'failed',
                'reason_code' => $reasonCode,
                'notes' => 'Internal rider note',
                'file_path' => $path,
                'attempted_at' => '2026-07-17 10:00:00',
                'next_attempt_at' => '2026-07-18 10:00:00',
                'recorded_by_type' => User::class,
                'recorded_by_id' => 999,
            ]);

            $attempt = collect(app(CustomerTrackingService::class)->payload($shipment)['legs'])
                ->firstWhere('id', $leg->id)['latest_failed_attempt'];

            $this->assertSame(['id', 'reason', 'attempted_at', 'proof_url'], array_keys($attempt));
            $this->assertSame($reasonLabel, $attempt['reason']);
            $this->assertStringContainsString("/tracking/shipments/{$shipment->id}/attempts/", $attempt['proof_url']);
        }
    }

    public function test_customer_payload_orders_legs_and_attempts_deterministically(): void
    {
        $shipment = Shipment::factory()->create();
        $lowerIdLeg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'sequence' => 5]);
        $higherIdLeg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'sequence' => 5]);
        $older = DeliveryAttempt::query()->create([
            'shipment_leg_id' => $higherIdLeg->id, 'attempt_type' => 'delivery', 'status' => 'failed',
            'reason_code' => 'other', 'attempted_at' => '2026-07-17 10:00:00',
        ]);
        $latest = DeliveryAttempt::query()->create([
            'shipment_leg_id' => $higherIdLeg->id, 'attempt_type' => 'delivery', 'status' => 'failed',
            'reason_code' => 'recipient_unavailable', 'attempted_at' => '2026-07-17 10:00:00',
        ]);

        $payload = app(CustomerTrackingService::class)->payload($shipment);

        $this->assertSame([$lowerIdLeg->id, $higherIdLeg->id], array_column($payload['legs'], 'id'));
        $this->assertNull($payload['legs'][0]['latest_failed_attempt']);
        $this->assertSame($latest->id, $payload['legs'][1]['latest_failed_attempt']['id']);
        $this->assertNotSame($older->id, $payload['legs'][1]['latest_failed_attempt']['id']);
    }

    public function test_only_the_owning_customer_can_view_failed_attempt_proof(): void
    {
        Storage::fake('public');
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $order->shop_owner_id, 'source_type' => 'order', 'source_id' => $order->id,
        ]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $path = "logistics-attempt/{$leg->id}/attempt.jpg";
        Storage::disk('public')->put($path, 'attempt-photo');
        $attempt = DeliveryAttempt::query()->create([
            'shipment_leg_id' => $leg->id, 'attempt_type' => 'delivery', 'status' => 'failed',
            'reason_code' => 'recipient_unavailable', 'file_path' => $path, 'attempted_at' => now(),
        ]);
        $otherShipment = Shipment::factory()->create([
            'shop_owner_id' => $order->shop_owner_id, 'source_type' => 'order', 'source_id' => $order->id,
        ]);
        $url = "/tracking/shipments/{$shipment->id}/attempts/{$attempt->id}/proof";

        $this->get($url)->assertRedirect();
        $this->actingAs($other, 'user')->get($url)->assertForbidden();
        $this->actingAs($customer, 'user')->get("/tracking/shipments/{$otherShipment->id}/attempts/{$attempt->id}/proof")->assertForbidden();
        $this->actingAs($customer, 'user')->get($url)->assertOk()->assertStreamedContent('attempt-photo');

        Storage::disk('public')->delete($path);
        $this->actingAs($customer, 'user')->get($url)->assertNotFound();
    }

    public function test_my_orders_uses_newest_shipment_current_leg_failure_and_schedule(): void
    {
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'carrier_company' => 'Shop-owned logistics',
        ]);
        $olderShipment = Shipment::factory()->create([
            'shop_owner_id' => $order->shop_owner_id, 'source_type' => 'order',
            'source_id' => $order->id, 'purpose' => 'retail_delivery',
        ]);
        $olderLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $olderShipment->id, 'sequence' => 99, 'status' => 'pending',
        ]);
        DeliveryAttempt::query()->create([
            'shipment_leg_id' => $olderLeg->id, 'attempt_type' => 'delivery', 'status' => 'failed',
            'reason_code' => 'recipient_refused', 'attempted_at' => now(),
        ]);

        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $order->shop_owner_id, 'source_type' => 'order',
            'source_id' => $order->id, 'purpose' => 'retail_delivery',
        ]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'sequence' => 5, 'status' => 'pending',
            'scheduled_delivery_date' => '2026-07-17', 'delivery_window' => 'afternoon',
        ]);
        $currentLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'sequence' => 5, 'status' => 'pending',
            'scheduled_delivery_date' => '2026-07-18', 'delivery_window' => 'morning',
        ]);
        DeliveryAttempt::query()->create([
            'shipment_leg_id' => $currentLeg->id, 'attempt_type' => 'delivery', 'status' => 'failed',
            'reason_code' => 'recipient_unavailable', 'attempted_at' => now(),
        ]);

        $assertPayload = fn ($response, bool $failed, ?string $date, ?string $window) => $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.0.logistics_shipment_id', $shipment->id)
                ->where('orders.0.delivery_has_failed_attempt', $failed)
                ->where('orders.0.delivery_scheduled_date', $date)
                ->where('orders.0.delivery_window', $window));

        $assertPayload($this->actingAs($customer, 'user')->get('/my-orders'), true, '2026-07-18', 'morning');

        $currentLeg->update([
            'status' => 'delivered', 'scheduled_delivery_date' => null, 'delivery_window' => 'afternoon',
        ]);
        $assertPayload($this->actingAs($customer, 'user')->get('/my-orders'), false, null, 'afternoon');
    }
}
