<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\RiderProfile;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeliveryBatchSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_dispatch_schema_and_relations_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('delivery_batches', [
            'shop_owner_id', 'rider_profile_id', 'delivery_date', 'delivery_window',
            'status', 'capacity', 'assigned_stop_count', 'offered_at', 'accepted_at',
            'rejected_at', 'started_at', 'completed_at', 'cancelled_at',
            'rejection_reason', 'dispatcher_override_reason',
        ]));
        $this->assertTrue(Schema::hasColumns('shipment_legs', [
            'delivery_batch_id', 'stop_sequence', 'attempt_number', 'out_for_delivery_at', 'urgent_at',
        ]));
        $this->assertTrue(Schema::hasColumns('rider_profiles', ['work_days', 'leave_dates', 'daily_capacity']));
        $this->assertTrue(Schema::hasColumns('delivery_assignments', ['rejection_reason', 'rejected_at']));

        $shop = ShopOwner::factory()->create();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $batch = DeliveryBatch::create([
            'shop_owner_id' => $shop->id, 'rider_profile_id' => $rider->id,
            'delivery_date' => '2026-07-15', 'delivery_window' => 'morning',
        ]);
        $this->assertSame('draft', $batch->status);
        $this->assertSame('2026-07-15', $batch->delivery_date->toDateString());
    }
}
