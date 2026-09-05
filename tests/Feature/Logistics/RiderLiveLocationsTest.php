<?php

namespace Tests\Feature\Logistics;

use App\Enums\Logistics\RiderProgressState;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderCurrentLocation;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RiderLiveLocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_only_receives_current_locations_for_active_deliveries_in_their_shop(): void
    {
        config(['logistics_tracking.enabled' => true]);

        $shop = ShopOwner::factory()->create();
        $foreignShop = ShopOwner::factory()->create();
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo(Permission::findOrCreate('view-logistics-shipments', 'user'));

        $visible = $this->trackedLeg($shop, 'Visible customer', ['latitude' => 14.5995, 'longitude' => 120.9842]);
        $stale = $this->trackedLeg($shop, 'Stale customer', [
            'latitude' => 14.6000,
            'longitude' => 120.9850,
            'recorded_at' => now()->subSeconds(120),
        ]);
        $foreign = $this->trackedLeg($foreignShop, 'Foreign customer', ['latitude' => 10.3157, 'longitude' => 123.8854]);
        $delivered = $this->trackedLeg($shop, 'Delivered customer', ['latitude' => 14.6010, 'longitude' => 120.9860], 'delivered');
        $refundReturn = $this->trackedLeg($shop, 'Refund return', ['latitude' => 14.6020, 'longitude' => 120.9870]);
        $refundReturn['leg']->shipment->update([
            'source_type' => 'order_refund',
            'purpose' => 'refund_return',
        ]);
        $refundReturn['leg']->update([
            'leg_type' => 'return_to_shop',
            'picked_up_at' => now(),
            'origin_snapshot' => [
                'type' => 'customer',
                'name' => 'Refund return customer',
                'address' => 'Refund return customer address',
                'latitude' => 14.61,
                'longitude' => 120.99,
            ],
            'destination_snapshot' => [
                'type' => 'shop',
                'name' => 'Shop',
                'address' => 'Shop address',
                'latitude' => 14.62,
                'longitude' => 121.00,
            ],
        ]);

        $response = $this->actingAs($dispatcher, 'user')
            ->getJson('/api/logistics/live-locations')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('poll_after_seconds', 5);

        $locations = collect($response->json('locations'));

        $this->assertCount(3, $locations);
        $this->assertSame([$visible['leg']->id, $stale['leg']->id, $refundReturn['leg']->id], $locations->pluck('leg_id')->sort()->values()->all());
        $this->assertTrue($locations->firstWhere('leg_id', $stale['leg']->id)['stale']);
        $this->assertSame('shop', $locations->firstWhere('leg_id', $refundReturn['leg']->id)['destination']['type']);
        $this->assertFalse($locations->contains('leg_id', $foreign['leg']->id));
        $this->assertFalse($locations->contains('leg_id', $delivered['leg']->id));
    }

    public function test_dispatcher_cannot_read_live_locations_without_view_permission(): void
    {
        config(['logistics_tracking.enabled' => true]);

        $shop = ShopOwner::factory()->create();
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);

        $this->actingAs($dispatcher, 'user')
            ->getJson('/api/logistics/live-locations')
            ->assertForbidden();
    }

    /** @return array{leg: ShipmentLeg, assignment: DeliveryAssignment, rider: RiderProfile} */
    private function trackedLeg(ShopOwner $shop, string $customerName, array $location, string $status = 'in_transit'): array
    {
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'active',
            'source_type' => 'order',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => $status,
            'rider_progress_state' => RiderProgressState::ACTIVE,
            'destination_snapshot' => [
                'type' => 'customer',
                'name' => $customerName,
                'address' => "{$customerName} address",
                'latitude' => 14.6100,
                'longitude' => 120.9900,
            ],
        ]);
        $riderUser = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_type' => 'employee',
            'linked_type' => User::class,
            'linked_id' => $riderUser->id,
        ]);
        $assignment = $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
            'assigned_at' => now()->subMinute(),
        ]);
        RiderCurrentLocation::create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'delivery_assignment_id' => $assignment->id,
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'accuracy_m' => 12,
            'speed_mps' => 4,
            'heading_deg' => 90,
            'recorded_at' => $location['recorded_at'] ?? now()->subSeconds(10),
            'received_at' => now()->subSeconds(5),
        ]);

        return compact('leg', 'assignment', 'rider');
    }
}
