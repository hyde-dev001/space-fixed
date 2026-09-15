<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderCurrentLocation;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderLocationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_trackable_leg_statuses_remove_the_current_location(): void
    {
        foreach (['assigned', 'pickup_scheduled', 'picked_up', 'delivery_attempted', 'delivered', 'cancelled', 'needs_resolution'] as $status) {
            $leg = ShipmentLeg::factory()->create(['status' => 'in_transit']);
            $rider = RiderProfile::factory()->create(['shop_owner_id' => $leg->shipment->shop_owner_id]);
            $assignment = DeliveryAssignment::factory()->create([
                'shipment_leg_id' => $leg->id,
                'rider_profile_id' => $rider->id,
                'status' => 'accepted',
            ]);
            RiderCurrentLocation::create([
                'shipment_leg_id' => $leg->id,
                'rider_profile_id' => $rider->id,
                'delivery_assignment_id' => $assignment->id,
                'latitude' => 14.3,
                'longitude' => 120.95,
                'recorded_at' => now(),
                'received_at' => now(),
            ]);

            $leg->update(['status' => $status]);

            $this->assertDatabaseMissing('rider_current_locations', ['shipment_leg_id' => $leg->id]);
        }
    }
}
