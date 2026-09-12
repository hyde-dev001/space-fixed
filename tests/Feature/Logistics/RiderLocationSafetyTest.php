<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RiderLocationSafetyTest extends TestCase
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

    public function test_stale_and_future_location_timestamps_are_rejected(): void
    {
        [$leg, $rider] = $this->fixture();

        foreach ([now()->subSeconds(121), now()->addSeconds(61)] as $recordedAt) {
            $this->actingAs($rider, 'user')
                ->postJson("/api/logistics/legs/{$leg->id}/location", [
                    ...$this->payload(),
                    'recorded_at' => $recordedAt->toISOString(),
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('recorded_at');
        }
    }

    public function test_an_impossible_coordinate_jump_is_rejected(): void
    {
        [$leg, $rider] = $this->fixture();
        $first = now()->subSeconds(10);

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/location", [
                ...$this->payload(),
                'recorded_at' => $first->toISOString(),
            ])
            ->assertOk();

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/location", [
                ...$this->payload(),
                'latitude' => 15.3,
                'longitude' => 121.95,
                'recorded_at' => now()->toISOString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('coordinates');
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

        return [$leg->fresh(['shipment', 'latestAssignment']), $rider];
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
}
