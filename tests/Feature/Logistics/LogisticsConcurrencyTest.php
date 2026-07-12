<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticsConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_return_creation_converges_on_one_leg(): void
    {
        $shipment = Shipment::factory()->create();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shipment->shop_owner_id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'needs_resolution', 'resolution_type' => 'return_required']);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        $service = app(ShipmentLegService::class);

        $first = $service->createReturnToShop($leg);
        $second = $service->createReturnToShop($leg->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ShipmentLeg::where('return_for_leg_id', $leg->id)->count());
    }
}
