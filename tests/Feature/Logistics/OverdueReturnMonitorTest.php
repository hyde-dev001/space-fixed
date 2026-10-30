<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverdueReturnMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_return_receipt_is_flagged_once(): void
    {
        ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory(), 'leg_type' => 'return_to_shop', 'status' => 'picked_up', 'created_at' => now()->subDay()]);
        $this->artisan('logistics:monitor-overdue')->assertSuccessful();
        $this->artisan('logistics:monitor-overdue')->assertSuccessful();
        $this->assertDatabaseHas('delivery_events', ['event_type' => 'overdue_return_receipt']);
        $this->assertSame(1, \App\Models\Logistics\DeliveryEvent::where('event_type', 'overdue_return_receipt')->count());
    }
}
