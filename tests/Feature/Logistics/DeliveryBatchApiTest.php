<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Logistics\LogisticsSetting;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryBatchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_schedules_unscheduled_legs(): void
    {
        $shop = ShopOwner::factory()->create();
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'operating_days' => range(1, 7)]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'scheduled_delivery_date' => null, 'delivery_window' => null, 'schedule_status' => null, 'status' => 'pending',
        ]);
        $date = today()->addDay()->toDateString();

        $this->actingAs($shop, 'shop_owner')->postJson('/api/logistics/legs/schedule', [
            'delivery_date' => $date, 'delivery_window' => 'morning', 'leg_ids' => [$leg->id],
        ])->assertOk();

        $this->assertDatabaseHas('shipment_legs', [
            'id' => $leg->id, 'scheduled_delivery_date' => $date.' 00:00:00',
            'delivery_window' => 'morning', 'schedule_status' => 'scheduled',
        ]);
    }

    public function test_dispatcher_creates_and_offers_batch_then_assigned_rider_accepts(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id, 'linked_type' => User::class, 'linked_id' => $user->id,
            'active' => true, 'availability_status' => 'available',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'scheduled_delivery_date' => '2026-07-15', 'delivery_window' => 'morning',
            'schedule_status' => 'scheduled', 'status' => 'pending',
        ]);

        $batchId = $this->actingAs($shop, 'shop_owner')->postJson('/api/logistics/batches', [
            'delivery_date' => '2026-07-15', 'delivery_window' => 'morning', 'leg_ids' => [$leg->id],
        ])->assertCreated()->json('batch.id');
        $this->postJson("/api/logistics/batches/{$batchId}/offer", ['rider_profile_id' => $rider->id])->assertOk();
        $this->actingAs($user, 'user')->postJson("/api/logistics/batches/{$batchId}/accept")
            ->assertOk()->assertJsonPath('batch.status', 'accepted');
    }

    public function test_other_rider_cannot_accept_batch(): void
    {
        $shop = ShopOwner::factory()->create();
        $other = User::factory()->create(['shop_owner_id' => $shop->id]);
        $batch = \App\Models\Logistics\DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'offered']);

        $this->actingAs($other, 'user')->postJson("/api/logistics/batches/{$batch->id}/accept")->assertForbidden();
    }

    public function test_offer_capacity_override_is_validated_forwarded_and_enforced(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'daily_rider_capacity' => 1]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available',
            'daily_capacity' => null,
        ]);
        DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id, 'rider_profile_id' => $rider->id,
            'delivery_date' => '2026-07-15', 'status' => 'offered', 'assigned_stop_count' => 1,
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'scheduled_delivery_date' => '2026-07-15', 'delivery_window' => 'morning',
            'schedule_status' => 'scheduled', 'status' => 'pending',
        ]);
        $batchId = $this->actingAs($shop, 'shop_owner')->postJson('/api/logistics/batches', [
            'delivery_date' => '2026-07-15', 'delivery_window' => 'morning', 'leg_ids' => [$leg->id],
        ])->assertCreated()->json('batch.id');
        $url = "/api/logistics/batches/{$batchId}/offer";

        $this->postJson($url, ['rider_profile_id' => $rider->id, 'capacity_override_reason' => null])
            ->assertUnprocessable()
            ->assertJsonPath('errors.capacity_override_reason.0', 'Capacity override reason is required.');
        $this->postJson($url, ['rider_profile_id' => $rider->id, 'capacity_override_reason' => str_repeat('x', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors('capacity_override_reason');
        $this->postJson($url, ['rider_profile_id' => $rider->id, 'capacity_override_reason' => 'Operational priority'])
            ->assertOk()->assertJsonPath('batch.status', 'offered');

        $this->assertSame('Operational priority', DeliveryEvent::where('event_type', 'batch_offered')->latest('id')->firstOrFail()->metadata['capacity_override_reason']);
    }
}
