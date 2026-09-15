<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RiderLocationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['logistics_tracking.enabled' => true]);

        ShopOwner::creating(function (ShopOwner $shop): void {
            $shop->forceFill(['registration_type' => 'company']);
        });
        ShopOwner::created(function (ShopOwner $shop): void {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $shop->id,
                'module_key' => 'logistics',
                'enabled' => true,
            ]);
        });
        Permission::findOrCreate('update-logistics-status', 'user');
    }

    public function test_assigned_rider_can_record_a_valid_current_location(): void
    {
        [$leg, $rider] = $this->fixture();
        $recordedAt = now()->subSecond()->toISOString();

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/location", [
                'latitude' => 14.3001,
                'longitude' => 120.9501,
                'accuracy_m' => 12.5,
                'speed_mps' => 8.2,
                'heading_deg' => 90,
                'recorded_at' => $recordedAt,
            ])
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('location.latitude', 14.3001)
            ->assertJsonPath('location.longitude', 120.9501);

        $this->assertDatabaseHas('rider_current_locations', [
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $leg->latestAssignment->rider_profile_id,
            'delivery_assignment_id' => $leg->latestAssignment->id,
        ]);
    }

    public function test_accepts_a_valid_coordinate_when_desktop_gps_reports_accuracy_above_the_default_threshold(): void
    {
        [$leg, $rider] = $this->fixture();
        $payload = $this->payload();
        $payload['accuracy_m'] = 1500;

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/location", $payload)
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('location.accuracy_m', 1500);

        $this->assertDatabaseHas('rider_current_locations', [
            'shipment_leg_id' => $leg->id,
            'accuracy_m' => 1500,
        ]);
    }

    public function test_only_the_exact_assigned_rider_can_record_location(): void
    {
        [$leg, $rider, $shop] = $this->fixture();
        $otherRider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $otherRider->givePermissionTo('update-logistics-status');
        $crossShop = ShopOwner::factory()->create();
        $crossTenantRider = User::factory()->create(['shop_owner_id' => $crossShop->id]);
        $crossTenantRider->givePermissionTo('update-logistics-status');

        foreach ([$otherRider, $crossTenantRider] as $actor) {
            $this->actingAs($actor, 'user')
                ->postJson("/api/logistics/legs/{$leg->id}/location", $this->payload())
                ->assertForbidden();
        }
    }

    public function test_terminal_delivery_cannot_accept_location_updates(): void
    {
        [$leg, $rider] = $this->fixture();
        $leg->update(['status' => 'delivered']);

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/location", $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('rider_current_locations', 0);
    }
    public function test_pickup_status_cannot_accept_location_updates(): void
    {
        [$leg, $rider] = $this->fixture();
        $leg->update(['status' => 'pickup_scheduled']);

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/location", $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('rider_current_locations', 0);
    }

    public function test_repair_pickup_accepts_location_updates_before_and_after_customer_handoff(): void
    {
        [$leg, $rider] = $this->fixture();
        $customer = User::factory()->create();
        $repair = RepairRequest::factory()->create([
            'user_id' => $customer->id,
            'shop_owner_id' => $leg->shipment->shop_owner_id,
        ]);
        $leg->shipment->update([
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_pickup',
        ]);
        $leg->update([
            'status' => 'assigned',
            'leg_type' => 'inbound',
            'origin_snapshot' => [
                'type' => 'customer',
                'name' => 'Customer Home',
                'address' => 'Customer address',
                'latitude' => 14.31,
                'longitude' => 120.96,
            ],
            'destination_snapshot' => [
                'type' => 'shop',
                'name' => 'Shop',
                'latitude' => 14.32,
                'longitude' => 120.97,
            ],
        ]);

        $this->actingAs($rider, 'user')
            ->postJson('/api/logistics/legs/'.$leg->id.'/location', $this->payload())
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('location.latitude', 14.3001)
            ->assertJsonPath('location.longitude', 120.9501);

        $this->assertDatabaseHas('rider_current_locations', [
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $leg->latestAssignment->rider_profile_id,
            'delivery_assignment_id' => $leg->latestAssignment->id,
        ]);

        $leg->update([
            'status' => 'picked_up',
            'picked_up_at' => now(),
        ]);

        $this->actingAs($rider, 'user')
            ->postJson('/api/logistics/legs/'.$leg->id.'/location', $this->payload())
            ->assertForbidden();

        $leg->update(['status' => 'in_transit']);

        $this->actingAs($rider, 'user')
            ->postJson('/api/logistics/legs/'.$leg->id.'/location', [
                ...$this->payload(),
                'latitude' => 14.315,
                'longitude' => 120.965,
            ])
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('location.latitude', 14.315)
            ->assertJsonPath('location.longitude', 120.965);

        $this->assertDatabaseHas('rider_current_locations', [
            'shipment_leg_id' => $leg->id,
            'latitude' => 14.315,
            'longitude' => 120.965,
        ]);
    }

    public function test_retail_refund_return_accepts_location_updates_before_and_after_customer_handoff(): void
    {
        [$leg, $rider] = $this->fixture();
        $leg->shipment->update([
            'source_type' => 'order_refund',
            'source_id' => 999,
            'purpose' => 'refund_return',
        ]);
        $leg->update([
            'status' => 'assigned',
            'leg_type' => 'return_to_shop',
            'origin_snapshot' => [
                'type' => 'customer',
                'name' => 'Customer Home',
                'address' => 'Customer return address',
                'latitude' => 14.31,
                'longitude' => 120.96,
            ],
            'destination_snapshot' => [
                'type' => 'shop',
                'name' => 'Retail Shop',
                'latitude' => 14.32,
                'longitude' => 120.97,
            ],
        ]);

        $this->actingAs($rider, 'user')
            ->postJson('/api/logistics/legs/'.$leg->id.'/location', $this->payload())
            ->assertOk()
            ->assertJsonPath('accepted', true);

        $leg->update([
            'status' => 'picked_up',
            'picked_up_at' => now(),
        ]);

        $this->actingAs($rider, 'user')
            ->postJson('/api/logistics/legs/'.$leg->id.'/location', $this->payload())
            ->assertForbidden();

        $leg->update(['status' => 'in_transit']);

        $this->actingAs($rider, 'user')
            ->postJson('/api/logistics/legs/'.$leg->id.'/location', [
                ...$this->payload(),
                'latitude' => 14.315,
                'longitude' => 120.965,
            ])
            ->assertOk()
            ->assertJsonPath('accepted', true);
    }

    public function test_shop_destination_cannot_accept_customer_delivery_location_updates(): void
    {
        [$leg, $rider] = $this->fixture();
        $leg->update([
            'destination_snapshot' => [
                'type' => 'shop',
                'name' => 'Shop',
                'latitude' => 14.3,
                'longitude' => 120.95,
            ],
        ]);

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/location", $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('rider_current_locations', 0);
    }


    public function test_location_payload_is_validated(): void
    {
        [$leg, $rider] = $this->fixture();

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/location", [
                'latitude' => 91,
                'longitude' => -181,
                'accuracy_m' => -1,
                'speed_mps' => -1,
                'heading_deg' => 361,
                'recorded_at' => 'not-a-date',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'latitude',
                'longitude',
                'accuracy_m',
                'speed_mps',
                'heading_deg',
                'recorded_at',
            ]);
    }

    public function test_repeating_the_same_recorded_timestamp_is_idempotent(): void
    {
        [$leg, $rider] = $this->fixture();
        $payload = $this->payload();

        $first = $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/location", $payload)
            ->assertOk();
        $second = $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/location", $payload)
            ->assertOk();

        $this->assertSame($first->json('location.id'), $second->json('location.id'));
        $this->assertDatabaseCount('rider_current_locations', 1);
    }

    public function test_gps_updates_trim_the_canonical_route_without_requesting_a_route_for_each_sample(): void
    {
        [$leg, $rider] = $this->fixture();
        $leg->update([
            'stop_sequence' => 1,
            'destination_snapshot' => [
                'type' => 'customer',
                'name' => 'Customer',
                'latitude' => 14.305,
                'longitude' => 120.955,
            ],
        ]);
        $this->fakeRoute();
        $firstRecordedAt = now()->subSeconds(10);

        $this->postLocation($leg, $rider, [
            'latitude' => 14.3001,
            'longitude' => 120.9501,
            'recorded_at' => $firstRecordedAt->toISOString(),
        ])->assertJsonPath('route.route_version', 1);

        $trimmed = $this->postLocation($leg, $rider, [
            'latitude' => 14.3025,
            'longitude' => 120.9525,
            'recorded_at' => $firstRecordedAt->copy()->addSeconds(5)->toISOString(),
        ])->assertJsonPath('route.route_version', 1);

        $this->assertNotSame(
            14.3001,
            $trimmed->json('route.geometry.0.0'),
        );
        Http::assertSentCount(1);
        $this->assertSame(1, $leg->fresh()->stop_sequence);
    }

    public function test_three_consecutive_off_route_samples_trigger_one_cooldown_limited_reroute_to_the_same_stop(): void
    {
        config([
            'logistics_tracking.route_progress.off_route_threshold_m' => 50,
            'logistics_tracking.route_progress.off_route_consecutive_samples' => 3,
            'logistics_tracking.route_progress.reroute_cooldown_seconds' => 600,
        ]);
        [$leg, $rider] = $this->fixture();
        $leg->update([
            'stop_sequence' => 1,
            'destination_snapshot' => [
                'type' => 'customer',
                'name' => 'Customer',
                'latitude' => 14.305,
                'longitude' => 120.955,
            ],
        ]);
        $this->fakeRoute();
        $firstRecordedAt = now()->subSeconds(20);

        $this->postLocation($leg, $rider, [
            'latitude' => 14.3001,
            'longitude' => 120.9501,
            'recorded_at' => $firstRecordedAt->toISOString(),
        ])->assertJsonPath('route.route_version', 1);

        foreach ([1, 2, 3] as $offset) {
            $response = $this->postLocation($leg, $rider, [
                'latitude' => 14.302 + ($offset * 0.0001),
                'longitude' => 120.9501,
                'recorded_at' => $firstRecordedAt->copy()->addSeconds(5 + ($offset * 5))->toISOString(),
            ]);

            if ($offset < 3) {
                $response->assertJsonPath('route.route_version', 1);
            } else {
                $response->assertJsonPath('route.route_version', 2);
                $response->assertJsonPath('route.active_stop_id', $leg->id);
            }
        }

        $this->postLocation($leg, $rider, [
            'latitude' => 14.3025,
            'longitude' => 120.9501,
            'recorded_at' => $firstRecordedAt->copy()->addSeconds(25)->toISOString(),
        ])->assertJsonPath('route.route_version', 2);

        Http::assertSentCount(2);
        $this->assertSame(1, $leg->fresh()->stop_sequence);
        $this->assertSame(120.955, (float) data_get($leg->fresh()->destination_snapshot, 'longitude'));
    }

    private function fixture(): array
    {
        $shop = ShopOwner::factory()->create();
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->givePermissionTo('update-logistics-status');
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'active',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'in_transit',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $profile->id,
            'assignment_type' => 'internal_rider',
            'status' => 'accepted',
        ]);

        return [$leg->fresh(['shipment', 'latestAssignment']), $rider, $shop];
    }

    private function payload(): array
    {
        return [
            'latitude' => 14.3001,
            'longitude' => 120.9501,
            'accuracy_m' => 12.5,
            'speed_mps' => 8.2,
            'heading_deg' => 90,
            'recorded_at' => now()->subSecond()->toISOString(),
        ];
    }

    private function postLocation(ShipmentLeg $leg, User $rider, array $overrides = [])
    {
        return $this->actingAs($rider, 'user')
            ->postJson('/api/logistics/legs/'.$leg->id.'/location', [
                ...$this->payload(),
                ...$overrides,
            ])
            ->assertOk();
    }

    private function fakeRoute(): void
    {
        Http::fake([
            'https://router.project-osrm.org/route/v1/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [[
                    'distance' => 1000,
                    'duration' => 120,
                    'geometry' => [
                        'coordinates' => [
                            [120.9501, 14.3001],
                            [120.9525, 14.3025],
                            [120.955, 14.305],
                        ],
                    ],
                ]],
            ]),
        ]);
    }
}
