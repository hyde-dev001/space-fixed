<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShipmentLegServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_leg_cannot_be_marked_picked_up_without_required_pickup_proof(): void
    {
        $leg = ShipmentLeg::factory()->create([
            'status' => 'assigned',
            'requires_pickup_proof' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(ShipmentLegService::class)->markPickedUp($leg);
    }

    public function test_leg_cannot_be_delivered_without_required_delivery_proof(): void
    {
        $leg = ShipmentLeg::factory()->create([
            'status' => 'awaiting_proof_approval',
            'requires_delivery_proof' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(ShipmentLegService::class)->markDelivered($leg);
    }

    public function test_leg_can_be_delivered_after_delivery_proof_is_recorded(): void
    {
        $leg = ShipmentLeg::factory()->create([
            'status' => 'awaiting_proof_approval',
            'requires_delivery_proof' => true,
        ]);

        HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'review_status' => 'approved',
        ]);

        app(ShipmentLegService::class)->markDelivered($leg);

        $this->assertSame('delivered', $leg->fresh()->status->value);
    }

    public function test_leg_cannot_skip_pickup_before_in_transit(): void
    {
        $leg = ShipmentLeg::factory()->create(['status' => 'assigned']);

        $this->expectException(ValidationException::class);

        app(ShipmentLegService::class)->markInTransit($leg);
    }

    public function test_cancelled_leg_cannot_be_delivered(): void
    {
        $leg = ShipmentLeg::factory()->create([
            'status' => 'cancelled',
            'requires_delivery_proof' => false,
        ]);

        $this->expectException(ValidationException::class);

        app(ShipmentLegService::class)->markDelivered($leg);
    }

    public function test_shipment_completes_when_all_legs_are_delivered(): void
    {
        $shipment = Shipment::factory()->create(['status' => 'active']);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'delivered',
            'requires_delivery_proof' => false,
        ]);
        $lastLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'in_transit',
            'requires_delivery_proof' => false,
        ]);

        app(ShipmentLegService::class)->markDelivered($lastLeg);

        $this->assertSame('completed', $shipment->fresh()->status->value);
        $this->assertNotNull($shipment->fresh()->completed_at);
    }
}
