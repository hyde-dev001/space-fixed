<?php

namespace Tests\Feature\Logistics;

use App\Enums\Logistics\RiderProgressState;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderCurrentLocation;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerLiveTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_tracking_includes_only_their_safe_current_rider_location(): void
    {
        config(['logistics_tracking.enabled' => true]);

        $customer = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
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
            'rider_progress_state' => RiderProgressState::ACTIVE,
            'destination_snapshot' => [
                'type' => 'customer',
                'name' => 'Customer Home',
                'address' => 'Manila',
                'phone' => 'must stay private to existing safe snapshot rules',
                'latitude' => 14.61,
                'longitude' => 120.99,
            ],
        ]);
        $riderUser = User::factory()->create(['shop_owner_id' => $order->shop_owner_id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $order->shop_owner_id,
            'name' => 'Internal Rider Name',
            'rider_type' => 'employee',
            'linked_type' => User::class,
            'linked_id' => $riderUser->id,
        ]);
        $assignment = DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'assignment_type' => 'internal_rider',
            'status' => 'accepted',
        ]);
        RiderCurrentLocation::create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'delivery_assignment_id' => $assignment->id,
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'accuracy_m' => 15,
            'speed_mps' => 4,
            'heading_deg' => 90,
            'recorded_at' => now()->subSeconds(10),
            'received_at' => now()->subSeconds(5),
        ]);

        $this->actingAs($customer, 'user')
            ->getJson("/tracking/shipments/{$shipment->id}")
            ->assertOk()
            ->assertJsonPath('shipment.legs.0.live_tracking.location.latitude', 14.5995)
            ->assertJsonPath('shipment.legs.0.live_tracking.location.longitude', 120.9842)
            ->assertJsonPath('shipment.legs.0.live_tracking.stale', false)
            ->assertJsonMissingPath('shipment.legs.0.live_tracking.rider');
    }

    public function test_customer_tracking_hides_location_after_delivery(): void
    {
        config(['logistics_tracking.enabled' => true]);

        $customer = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $order->shop_owner_id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'status' => 'active',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'delivered',
            'rider_progress_state' => RiderProgressState::ACTIVE,
        ]);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $order->shop_owner_id]);
        $assignment = DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);
        RiderCurrentLocation::create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'delivery_assignment_id' => $assignment->id,
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'recorded_at' => now(),
            'received_at' => now(),
        ]);

        $this->actingAs($customer, 'user')
            ->getJson("/tracking/shipments/{$shipment->id}")
            ->assertOk()
            ->assertJsonPath('shipment.legs.0.live_tracking', null);
    }
}
