<?php

namespace Tests\Feature\Logistics;

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
}
