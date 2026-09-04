<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\RiderLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RiderLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ShopOwner::creating(function (ShopOwner $shop): void {
            $shop->forceFill(['registration_type' => 'company']);
        });
    }

    public function test_current_location_table_stores_one_latest_location_per_leg(): void
    {
        $this->assertTrue(Schema::hasTable('rider_current_locations'));
        $this->assertTrue(Schema::hasColumns('rider_current_locations', [
            'shipment_leg_id',
            'rider_profile_id',
            'delivery_assignment_id',
            'latitude',
            'longitude',
            'accuracy_m',
            'speed_mps',
            'heading_deg',
            'recorded_at',
            'received_at',
        ]));
    }

    public function test_service_resolves_the_authenticated_riders_active_assignment(): void
    {
        [$leg, $rider, $assignment] = $this->fixture();

        $resolved = app(RiderLocationService::class)->activeAssignmentFor($leg, $rider);

        $this->assertNotNull($resolved);
        $this->assertSame($assignment->id, $resolved->id);
    }

    public function test_service_rejects_a_different_rider_or_terminal_leg(): void
    {
        [$leg, $rider, $assignment] = $this->fixture();
        $otherRider = User::factory()->create(['shop_owner_id' => $leg->shipment->shop_owner_id]);

        $service = app(RiderLocationService::class);

        $this->assertNull($service->activeAssignmentFor($leg, $otherRider));

        $leg->update(['status' => 'delivered']);

        $this->assertNull($service->activeAssignmentFor($leg->fresh(), $rider));
        $this->assertModelExists($assignment);
    }

    private function fixture(): array
    {
        $shop = ShopOwner::factory()->create();
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
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
        $assignment = DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $profile->id,
            'assignment_type' => 'internal_rider',
            'status' => 'accepted',
        ]);

        return [$leg->fresh(['shipment']), $rider, $assignment];
    }
}
