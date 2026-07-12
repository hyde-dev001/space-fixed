<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Services\Logistics\DeliveryIncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryIncidentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_rider_reports_and_dispatcher_confirms_loss(): void
    {
        $shop = ShopOwner::factory()->create();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id, 'status' => 'picked_up']);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        $service = app(DeliveryIncidentService::class);

        $incident = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Missing during route', 'photo_paths' => ['evidence.jpg']]);
        $resolved = $service->resolve($incident, $shop, 'loss_confirmed', 'Search and investigation completed', ['investigation.jpg']);

        $this->assertSame('resolved', $resolved->status);
        $this->assertSame('loss_confirmed', $leg->fresh()->resolution_type);
        $this->assertSame('picked_up', $leg->fresh()->status->value);
    }
}
