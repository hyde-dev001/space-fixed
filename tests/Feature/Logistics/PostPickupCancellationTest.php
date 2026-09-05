<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PostPickupCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_pickup_delivery_cannot_cancel_before_custody_resolution(): void
    {
        $leg = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory(), 'status' => 'delivery_attempted', 'picked_up_at' => now()]);
        $this->expectException(ValidationException::class);
        app(ShipmentLegService::class)->cancel($leg, 'Cancelled');
    }

    public function test_cancellation_closes_the_active_rider_assignment(): void
    {
        $shipment = Shipment::factory()->create();
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'needs_resolution',
            'picked_up_at' => now(),
            'resolution_type' => 'returned',
        ]);
        $assignment = DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => RiderProfile::factory()->create([
                'shop_owner_id' => $shipment->shop_owner_id,
            ])->id,
            'status' => 'accepted',
        ]);

        app(ShipmentLegService::class)->cancel($leg, 'Customer cancelled');

        $this->assertSame('cancelled', $leg->fresh()->status->value);
        $this->assertSame('cancelled', $assignment->fresh()->status);
        $this->assertNotNull($assignment->fresh()->cancelled_at);
    }
}
