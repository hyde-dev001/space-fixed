<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnToShopTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_is_singleton_and_receipt_ends_custody(): void
    {
        $shop = ShopOwner::factory()->create();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'needs_resolution', 'resolution_type' => 'return_required']);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        $service = app(ShipmentLegService::class);

        $return = $service->createReturnToShop($leg);
        $this->assertSame($return->id, $service->createReturnToShop($leg)->id);
        $proof = HandoffProof::factory()->create(['shipment_leg_id' => $return->id, 'handoff_type' => 'receive', 'proof_type' => 'photo']);
        $service->confirmReturnHandoff($return, $proof, $rider);
        $service->confirmReturnReceipt($return->fresh(), $proof->fresh(), $shop);

        $this->assertSame('delivered', $return->fresh()->status->value);
        $this->assertSame('cancelled', $leg->fresh()->status->value);
        $this->assertSame('returned', $leg->fresh()->resolution_type);
    }
}
