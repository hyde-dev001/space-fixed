<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\HandoffProof;
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

    public function test_customer_can_load_own_tracking_as_json_for_inline_repair_updates(): void
    {
        $customer = User::factory()->create();
        $repair = RepairRequest::factory()->create(['user_id' => $customer->id]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $repair->shop_owner_id,
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_pickup',
        ]);

        $this->actingAs($customer, 'user')
            ->getJson("/tracking/shipments/{$shipment->id}")
            ->assertOk()
            ->assertJsonPath('shipment.id', $shipment->id)
            ->assertJsonPath('shipment.purpose', 'repair_pickup');
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

    public function test_repair_tracking_payload_includes_source_summary(): void
    {
        $customer = User::factory()->create();
        $repair = RepairRequest::factory()->create([
            'user_id' => $customer->id,
            'request_id' => 'REP-2026-0042',
            'customer_name' => 'Mia Santos',
            'brand' => 'Nike',
            'shoe_type' => 'Air Max 90',
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $repair->shop_owner_id,
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_return',
        ]);

        $this->assertSame([
            'request_number' => 'REP-2026-0042',
            'customer_name' => 'Mia Santos',
            'shoe_summary' => 'Nike Air Max 90',
        ], app(CustomerTrackingService::class)->payload($shipment)['source_summary']);
    }

    public function test_customer_payload_exposes_only_safe_latest_failed_attempt_details(): void
    {
        Storage::fake('local');
        $shipment = Shipment::factory()->create();
        $expectedReasons = [
            'recipient_unavailable' => 'Recipient unavailable',
            'wrong_or_incomplete_address' => 'Wrong or incomplete address',
            'recipient_refused' => 'Recipient refused',
            'item_damaged' => 'Item damaged',
            'unsafe_location' => 'Unsafe location',
            'vehicle_or_delivery_problem' => 'Vehicle or delivery problem',
            'other' => 'Other delivery issue',
            'legacy_reason' => 'Delivery could not be completed',
        ];

        foreach ($expectedReasons as $reasonCode => $reasonLabel) {
            $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
            $path = "logistics-attempt/{$leg->id}/attempt.jpg";
            Storage::disk('local')->put($path, 'photo');
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

            $this->assertSame(['id', 'attempt_type', 'reason', 'attempted_at', 'proof_url'], array_keys($attempt));
            $this->assertSame('delivery', $attempt['attempt_type']);
            $this->assertSame($reasonLabel, $attempt['reason']);
            $this->assertStringContainsString("/tracking/shipments/{$shipment->id}/attempts/", $attempt['proof_url']);
        }
    }

    public function test_customer_payload_exposes_only_safe_failed_repair_pickup_details(): void
    {
        Storage::fake('public');
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $repair = RepairRequest::factory()->create(['user_id' => $customer->id]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $repair->shop_owner_id,
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_pickup',
        ]);
        $expectedReasons = [
            'customer_unavailable' => 'Customer unavailable / not home',
            'customer_requested_reschedule' => 'Customer requested reschedule',
            'customer_refused_pickup' => 'Customer refused pickup',
            'item_not_ready' => 'Item not ready or unavailable',
            'wrong_address_or_pin' => 'Wrong address or map pin',
            'unsafe_or_inaccessible_location' => 'Unsafe or inaccessible location',
            'vehicle_or_rider_problem' => 'Vehicle or rider problem',
            'other' => 'Other',
        ];
        $attempts = [];

        foreach ($expectedReasons as $reasonCode => $reasonLabel) {
            $leg = ShipmentLeg::factory()->create([
                'shipment_id' => $shipment->id,
                'status' => 'needs_resolution',
                'resolution_type' => 'pickup_failed',
                'resolution_reason' => 'Dispatcher-only resolution detail',
            ]);
            $path = $reasonCode === 'vehicle_or_rider_problem'
                ? "logistics-attempt/{$leg->id}/missing.jpg"
                : "logistics-attempt/{$leg->id}/attempt.jpg";
            if ($reasonCode !== 'vehicle_or_rider_problem') {
                Storage::disk('local')->put($path, 'pickup-photo');
            }
            $attempts[$reasonCode] = DeliveryAttempt::query()->create([
                'shipment_leg_id' => $leg->id,
                'attempt_type' => 'pickup',
                'status' => 'failed',
                'reason_code' => $reasonCode,
                'notes' => 'Internal rider note',
                'file_path' => $path,
                'attempted_at' => '2026-07-29 10:00:00',
                'recorded_by_type' => User::class,
                'recorded_by_id' => 999,
            ]);
        }

        $legs = collect(app(CustomerTrackingService::class)->payload($shipment)['legs']);
        foreach ($expectedReasons as $reasonCode => $reasonLabel) {
            $attempt = $legs
                ->firstWhere('id', $attempts[$reasonCode]->shipment_leg_id)['latest_failed_attempt'];
            $this->assertSame(['id', 'attempt_type', 'reason', 'attempted_at', 'proof_url'], array_keys($attempt));
            $this->assertSame('pickup', $attempt['attempt_type']);
            $this->assertSame($reasonLabel, $attempt['reason']);
            $this->assertArrayNotHasKey('notes', $attempt);
            $this->assertArrayNotHasKey('reason_code', $attempt);
            $this->assertArrayNotHasKey('recorded_by_id', $attempt);
        }
        $this->assertNull($legs
            ->firstWhere('id', $attempts['vehicle_or_rider_problem']->shipment_leg_id)['latest_failed_attempt']['proof_url']);

        $attempt = $attempts['customer_unavailable'];
        $url = "/tracking/shipments/{$shipment->id}/attempts/{$attempt->id}/proof";
        $this->actingAs($other, 'user')->get($url)->assertForbidden();
        $this->actingAs($customer, 'user')->get($url)->assertOk()->assertStreamedContent('pickup-photo');
    }

    public function test_customer_payload_orders_legs_and_attempts_deterministically(): void
    {
        $shipment = Shipment::factory()->create();
        $lowerIdLeg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'sequence' => 5]);
        $higherIdLeg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'sequence' => 5]);
        $newest = DeliveryAttempt::query()->create([
            'shipment_leg_id' => $higherIdLeg->id, 'attempt_type' => 'pickup', 'status' => 'failed',
            'reason_code' => 'customer_unavailable', 'attempted_at' => '2026-07-17 11:00:00',
        ]);
        $olderHigherId = DeliveryAttempt::query()->create([
            'shipment_leg_id' => $higherIdLeg->id, 'attempt_type' => 'delivery', 'status' => 'failed',
            'reason_code' => 'recipient_unavailable', 'attempted_at' => '2026-07-17 10:00:00',
        ]);

        $payload = app(CustomerTrackingService::class)->payload($shipment);

        $this->assertSame([$lowerIdLeg->id, $higherIdLeg->id], array_column($payload['legs'], 'id'));
        $this->assertNull($payload['legs'][0]['latest_failed_attempt']);
        $this->assertSame($newest->id, $payload['legs'][1]['latest_failed_attempt']['id']);
        $this->assertNotSame($olderHigherId->id, $payload['legs'][1]['latest_failed_attempt']['id']);

        $tiedHigherId = DeliveryAttempt::query()->create([
            'shipment_leg_id' => $higherIdLeg->id, 'attempt_type' => 'delivery', 'status' => 'failed',
            'reason_code' => 'recipient_refused', 'attempted_at' => '2026-07-17 11:00:00',
        ]);
        $payload = app(CustomerTrackingService::class)->payload($shipment);
        $this->assertSame($tiedHigherId->id, $payload['legs'][1]['latest_failed_attempt']['id']);
    }

    public function test_customer_payload_selects_only_the_preferred_approved_delivery_proof(): void
    {
        Storage::fake('local');
        $shipment = Shipment::factory()->create();
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'sequence' => 1,
            'status' => 'delivered',
            'delivered_at' => '2026-07-15 19:13:54',
            'destination_snapshot' => [
                'name' => 'Miguel Dela Rosa',
                'address' => 'Dasmariñas, Cavite',
                'phone' => '09050000000',
            ],
            'tracking_number' => null,
        ]);
        HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'review_status' => 'approved',
            'reviewed_at' => '2026-07-15 18:00:00',
            'file_path' => 'logistics-proof/old.jpg',
        ]);
        HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'review_status' => 'approved',
            'reviewed_at' => '2026-07-15 19:00:00',
            'file_path' => 'logistics-proof/tie-low.jpg',
        ]);
        $selected = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'review_status' => 'approved',
            'reviewed_at' => '2026-07-15 19:00:00',
            'file_path' => 'logistics-proof/selected.jpg',
        ]);
        HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'receive',
            'proof_type' => 'photo',
            'review_status' => 'approved',
            'reviewed_at' => '2026-07-15 20:00:00',
            'file_path' => 'logistics-proof/newer-receive.jpg',
        ]);
        foreach ([
            ['pickup', 'photo', 'approved'],
            ['delivery', 'photo', 'pending'],
            ['delivery', 'photo', 'rejected'],
            ['delivery', 'signature', 'approved'],
        ] as [$handoff, $type, $review]) {
            HandoffProof::factory()->create([
                'shipment_leg_id' => $leg->id,
                'handoff_type' => $handoff,
                'proof_type' => $type,
                'review_status' => $review,
                'reviewed_at' => '2026-07-15 21:00:00',
            ]);
        }
        Storage::disk('local')->put($selected->file_path, 'private-proof');

        $nonDelivered = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'sequence' => 2,
            'status' => 'in_transit',
        ]);
        HandoffProof::factory()->create([
            'shipment_leg_id' => $nonDelivered->id,
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'review_status' => 'approved',
            'file_path' => 'logistics-proof/not-delivered.jpg',
        ]);
        $missingLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'sequence' => 3,
            'status' => 'delivered',
            'destination_snapshot' => null,
        ]);
        $missing = HandoffProof::factory()->create([
            'shipment_leg_id' => $missingLeg->id,
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'review_status' => 'approved',
            'file_path' => 'logistics-proof/missing.jpg',
        ]);

        $legs = collect(app(CustomerTrackingService::class)->payload($shipment)['legs']);
        $proof = $legs->firstWhere('id', $leg->id)['delivery_proof'];

        $this->assertSame(
            ['id', 'available', 'url', 'delivered_at', 'location', 'tracking_number', 'status'],
            array_keys($proof)
        );
        $this->assertSame($selected->id, $proof['id']);
        $this->assertTrue($proof['available']);
        $this->assertSame(
            route('customer.tracking.delivery-proof', [$shipment, $selected]),
            $proof['url']
        );
        $this->assertSame('Miguel Dela Rosa - Dasmariñas, Cavite', $proof['location']);
        $this->assertSame("SHP-{$shipment->id}", $proof['tracking_number']);
        $this->assertSame('Delivered', $proof['status']);
        $this->assertArrayNotHasKey('delivery_proof', $legs->firstWhere('id', $nonDelivered->id));
        $this->assertSame([
            'id' => $missing->id,
            'available' => false,
            'url' => null,
            'delivered_at' => null,
            'location' => 'Location unavailable',
            'tracking_number' => "SHP-{$shipment->id}",
            'status' => 'Delivered',
        ], $legs->firstWhere('id', $missingLeg->id)['delivery_proof']);
    }

    public function test_only_owner_can_receive_sanitized_approved_delivery_proof(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('The GD extension is required to verify proof sanitization.');
        }

        Storage::fake('local');
        Storage::fake('public');
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $order->shop_owner_id,
            'source_type' => 'order',
            'source_id' => $order->id,
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'delivered',
        ]);
        $path = "logistics-proof/{$leg->id}/proof.jpg";
        $original = $this->jpegWithSentinel();
        Storage::disk('local')->put($path, $original);
        Storage::disk('public')->put($path, 'PUBLIC-DUPLICATE-MUST-NOT-BE-READ');
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'review_status' => 'approved',
            'file_path' => $path,
        ]);
        $url = "/tracking/shipments/{$shipment->id}/proofs/{$proof->id}";

        $this->get($url)->assertRedirect();
        $this->actingAs($other, 'user')->get($url)->assertForbidden();

        $otherShipment = Shipment::factory()->create([
            'shop_owner_id' => $order->shop_owner_id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_return',
        ]);
        $this->actingAs($customer, 'user')
            ->get("/tracking/shipments/{$otherShipment->id}/proofs/{$proof->id}")
            ->assertForbidden();

        $response = $this->actingAs($customer, 'user')->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Content-Disposition', "inline; filename=\"delivery-proof-{$proof->id}.jpeg\"")
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringNotContainsString('GPS-SENTINEL', $response->getContent());
        $this->assertStringNotContainsString('PUBLIC-DUPLICATE', $response->getContent());
        $this->assertSame($original, Storage::disk('local')->get($path));

        $this->actingAs($customer, 'user')->get("{$url}?download=1")
            ->assertOk()
            ->assertHeader('Content-Disposition', "attachment; filename=\"delivery-proof-{$proof->id}.jpeg\"");

        foreach ([
            ['handoff_type' => 'pickup', 'proof_type' => 'photo', 'review_status' => 'approved'],
            ['handoff_type' => 'delivery', 'proof_type' => 'photo', 'review_status' => 'pending'],
            ['handoff_type' => 'delivery', 'proof_type' => 'photo', 'review_status' => 'rejected'],
            ['handoff_type' => 'delivery', 'proof_type' => 'signature', 'review_status' => 'approved'],
        ] as $attributes) {
            $ineligible = HandoffProof::factory()->create([
                'shipment_leg_id' => $leg->id,
                'file_path' => $path,
                ...$attributes,
            ]);
            $this->actingAs($customer, 'user')
                ->get("/tracking/shipments/{$shipment->id}/proofs/{$ineligible->id}")
                ->assertNotFound();
        }

        $activeLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'in_transit',
        ]);
        $activeProof = HandoffProof::factory()->create([
            'shipment_leg_id' => $activeLeg->id,
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'review_status' => 'approved',
            'file_path' => $path,
        ]);
        $this->actingAs($customer, 'user')
            ->get("/tracking/shipments/{$shipment->id}/proofs/{$activeProof->id}")
            ->assertNotFound();

        Storage::disk('local')->delete($path);
        $this->actingAs($customer, 'user')->get($url)->assertNotFound();
    }

    public function test_only_the_owning_customer_can_view_failed_attempt_proof(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $order->shop_owner_id, 'source_type' => 'order', 'source_id' => $order->id,
        ]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $path = "logistics-attempt/{$leg->id}/attempt.jpg";
        Storage::disk('local')->put($path, 'attempt-photo');
        $attempt = DeliveryAttempt::query()->create([
            'shipment_leg_id' => $leg->id, 'attempt_type' => 'delivery', 'status' => 'failed',
            'reason_code' => 'recipient_unavailable', 'file_path' => $path, 'attempted_at' => now(),
        ]);
        $otherShipment = Shipment::factory()->create([
            'shop_owner_id' => $order->shop_owner_id, 'source_type' => 'order', 'source_id' => $order->id,
            'purpose' => 'retail_return',
        ]);
        $url = "/tracking/shipments/{$shipment->id}/attempts/{$attempt->id}/proof";

        $this->get($url)->assertRedirect();
        $this->actingAs($other, 'user')->get($url)->assertForbidden();
        $this->actingAs($customer, 'user')->get("/tracking/shipments/{$otherShipment->id}/attempts/{$attempt->id}/proof")->assertForbidden();
        $this->actingAs($customer, 'user')->get($url)->assertOk()->assertStreamedContent('attempt-photo');

        Storage::disk('local')->delete($path);
        $this->actingAs($customer, 'user')->get($url)->assertNotFound();

        Storage::disk('public')->put($path, 'legacy-public-attempt-photo');
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
            'source_id' => $order->id, 'purpose' => 'retail_return',
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

    private function jpegWithSentinel(): string
    {
        $image = imagecreatetruecolor(2, 2);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 200));
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes.'GPS-SENTINEL';
    }
}
