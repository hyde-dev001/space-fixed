<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverdueDeliveryMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_offer_is_flagged_once_and_never_cancelled(): void
    {
        $shop = ShopOwner::factory()->create();
        $batch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'offered', 'offered_at' => now()->subHour()]);
        ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id, 'delivery_batch_id' => $batch->id]);

        $this->artisan('logistics:monitor-overdue')->assertSuccessful();
        $this->artisan('logistics:monitor-overdue')->assertSuccessful();

        $this->assertDatabaseCount('delivery_events', 1);
        $this->assertDatabaseHas('delivery_events', ['event_type' => 'overdue_batch_offer']);
        $this->assertSame('offered', $batch->fresh()->status);
    }
}
