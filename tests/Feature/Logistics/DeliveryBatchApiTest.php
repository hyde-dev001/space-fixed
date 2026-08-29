<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Logistics\LogisticsSetting;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DeliveryBatchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ShopOwner::creating(function (ShopOwner $shop): void {
            $shop->forceFill(['registration_type' => 'company']);
        });
        ShopOwner::created(function (ShopOwner $shop): void {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $shop->id,
                'module_key' => 'logistics',
                'enabled' => true,
            ]);
        });
    }

    public function test_dispatcher_schedules_unscheduled_legs(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'retail']);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'operating_days' => range(1, 7)]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order'])->id,
            'scheduled_delivery_date' => null, 'delivery_window' => null, 'schedule_status' => null, 'status' => 'pending',
        ]);
        $date = today()->addDay()->toDateString();
        $dispatcher = $this->dispatcher($shop);

        $this->actingAs($dispatcher, 'user')->postJson('/api/logistics/legs/schedule', [
            'delivery_date' => $date, 'delivery_window' => 'morning', 'leg_ids' => [$leg->id],
        ])->assertOk();

        $this->assertDatabaseHas('shipment_legs', [
            'id' => $leg->id, 'scheduled_delivery_date' => $date.' 00:00:00',
            'delivery_window' => 'morning', 'schedule_status' => 'scheduled',
        ]);
    }

    public function test_dispatcher_creates_and_offers_batch_then_assigned_rider_accepts(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company', 'business_type' => 'retail']);
        $dispatcher = $this->dispatcher($shop);
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id, 'linked_type' => User::class, 'linked_id' => $user->id,
            'active' => true, 'availability_status' => 'available',
        ]);
        $legs = ShipmentLeg::factory()->count(2)->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order'])->id,
            'scheduled_delivery_date' => '2026-07-15', 'delivery_window' => 'morning',
            'schedule_status' => 'scheduled', 'status' => 'pending',
        ]);

        $batchId = $this->actingAs($dispatcher, 'user')->postJson('/api/logistics/batches', [
            'delivery_date' => '2026-07-15', 'delivery_window' => 'morning', 'leg_ids' => $legs->pluck('id')->all(),
        ])->assertCreated()->json('batch.id');
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/batches/{$batchId}/offer", ['rider_profile_id' => $rider->id])->assertOk();
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
        $shop = ShopOwner::factory()->create(['registration_type' => 'company', 'business_type' => 'retail']);
        $dispatcher = $this->dispatcher($shop);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'daily_rider_capacity' => 2]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available',
            'daily_capacity' => null,
        ]);
        DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id, 'rider_profile_id' => $rider->id,
            'delivery_date' => '2026-07-15', 'status' => 'offered', 'assigned_stop_count' => 1,
        ]);
        $legs = ShipmentLeg::factory()->count(2)->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order'])->id,
            'scheduled_delivery_date' => '2026-07-15', 'delivery_window' => 'morning',
            'schedule_status' => 'scheduled', 'status' => 'pending',
        ]);
        $batchId = $this->actingAs($dispatcher, 'user')->postJson('/api/logistics/batches', [
            'delivery_date' => '2026-07-15', 'delivery_window' => 'morning', 'leg_ids' => $legs->pluck('id')->all(),
        ])->assertCreated()->json('batch.id');
        $url = "/api/logistics/batches/{$batchId}/offer";

        $this->actingAs($dispatcher, 'user')->postJson($url, ['rider_profile_id' => $rider->id, 'capacity_override_reason' => null])
            ->assertUnprocessable()
            ->assertJsonPath('errors.capacity_override_reason.0', 'Capacity override reason is required.');
        $this->actingAs($dispatcher, 'user')->postJson($url, ['rider_profile_id' => $rider->id, 'capacity_override_reason' => str_repeat('x', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors('capacity_override_reason');
        $this->actingAs($dispatcher, 'user')->postJson($url, ['rider_profile_id' => $rider->id, 'capacity_override_reason' => 'Operational priority'])
            ->assertOk()->assertJsonPath('batch.status', 'offered');

        $this->assertSame('Operational priority', DeliveryEvent::where('event_type', 'batch_offered')->latest('id')->firstOrFail()->metadata['capacity_override_reason']);
    }

    public function test_batch_api_rejects_single_and_mixed_module_stops(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'both']);
        $dispatcher = $this->dispatcher($shop);
        $retail = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create([
                'shop_owner_id' => $shop->id,
                'source_type' => 'order',
            ])->id,
            'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'status' => 'pending',
        ]);
        $repair = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create([
                'shop_owner_id' => $shop->id,
                'source_type' => 'repair_request',
            ])->id,
            'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'status' => 'pending',
        ]);
        $payload = ['delivery_date' => '2026-07-15', 'delivery_window' => 'morning'];

        $this->actingAs($dispatcher, 'user')
            ->postJson('/api/logistics/batches', [...$payload, 'leg_ids' => [$retail->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leg_ids');

        $this->actingAs($dispatcher, 'user')
            ->postJson('/api/logistics/batches', [...$payload, 'leg_ids' => [$retail->id, $repair->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('legs');

        $this->assertDatabaseCount('delivery_batches', 0);
        $this->assertNull($retail->fresh()->delivery_batch_id);
        $this->assertNull($repair->fresh()->delivery_batch_id);
    }

    public function test_batch_index_and_suggestions_apply_the_authorized_module(): void
    {
        $shop = ShopOwner::factory()->create([
            'business_type' => 'both',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        $dispatcher = $this->dispatcher($shop);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'daily_rider_capacity' => 3]);
        RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'active' => true,
            'availability_status' => 'available',
            'work_days' => [3],
            'leave_dates' => [],
        ]);
        $retailShipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order']);
        $repairShipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'repair_request']);
        $retailBatch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id]);
        $repairBatch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id]);
        ShipmentLeg::factory()->count(2)->create(['shipment_id' => $retailShipment->id, 'delivery_batch_id' => $retailBatch->id]);
        ShipmentLeg::factory()->count(2)->create(['shipment_id' => $repairShipment->id, 'delivery_batch_id' => $repairBatch->id]);
        $repairSuggestionLegs = ShipmentLeg::factory()->count(2)->create([
            'shipment_id' => $repairShipment->id,
            'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'status' => 'pending',
            'destination_snapshot' => ['latitude' => 14.60, 'longitude' => 120.98],
        ]);

        $this->actingAs($dispatcher, 'user')
            ->getJson('/api/logistics/batches?module=repair')
            ->assertOk()
            ->assertJsonCount(1, 'batches')
            ->assertJsonPath('batches.0.id', $repairBatch->id);

        $suggestions = $this->actingAs($dispatcher, 'user')
            ->getJson('/api/logistics/batch-suggestions?delivery_date=2026-07-15&delivery_window=morning&module=repair')
            ->assertOk()
            ->json('suggestions');

        $this->assertCount(1, $suggestions);
        $this->assertSame('repair', $suggestions[0]['module']);
        $this->assertEqualsCanonicalizing($repairSuggestionLegs->pluck('id')->all(), $suggestions[0]['leg_ids']);
    }

    private function dispatcher(ShopOwner $shop): User
    {
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo(Permission::findOrCreate('manage-logistics-batches', 'user'));

        return $dispatcher;
    }
}
