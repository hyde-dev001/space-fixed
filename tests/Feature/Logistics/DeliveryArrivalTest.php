<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DeliveryArrivalTest extends TestCase
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

    public function test_assigned_rider_records_verified_pickup_without_changing_leg_status(): void
    {
        [$leg, $rider] = $this->fixture('assigned');
        $payload = $this->arrivalPayload();

        $response = $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/arrivals", $payload)
            ->assertCreated()
            ->assertJsonPath('arrival.arrival_type', 'pickup')
            ->assertJsonPath('arrival.result', 'verified')
            ->assertJsonPath('arrival.radius_m', 100)
            ->assertJsonPath('arrival.accuracy_m', 15)
            ->assertJsonMissingPath('arrival.latitude')
            ->assertJsonMissingPath('arrival.longitude')
            ->assertJsonMissingPath('arrival.metadata');

        $event = $leg->events()->sole();
        $this->assertSame($response->json('arrival.id'), $event->id);
        $this->assertSame('pickup_arrived', $event->event_type);
        $this->assertSame('internal', $event->visibility);
        $this->assertSame('verified', $event->metadata['result']);
        $this->assertSame(0, $event->metadata['distance_m']);
        $this->assertSame(100, $event->metadata['radius_m']);
        $this->assertSame(15, $event->metadata['accuracy_m']);
        $this->assertSame($payload['captured_at'], $event->metadata['captured_at']);
        $this->assertSame(14.3, $event->metadata['latitude']);
        $this->assertSame(120.95, $event->metadata['longitude']);
        $this->assertSame(User::class, $event->created_by_type);
        $this->assertSame($rider->id, $event->created_by_id);
        $this->assertSame('assigned', $leg->fresh()->status->value);
    }

    public function test_dropoff_uses_destination_and_is_idempotent(): void
    {
        [$leg, $rider] = $this->fixture('in_transit');
        $payload = $this->arrivalPayload('dropoff');

        $first = $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/arrivals", $payload)
            ->assertCreated()
            ->assertJsonPath('arrival.result', 'verified');

        $second = $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/arrivals", [
                ...$payload,
                'latitude' => 15,
                'longitude' => 121,
                'exception_reason' => 'alternate_meeting_point',
            ])
            ->assertOk();

        $this->assertSame($first->json('arrival.id'), $second->json('arrival.id'));
        $this->assertSame(1, $leg->events()->where('event_type', 'dropoff_arrived')->count());
        $this->assertSame('in_transit', $leg->fresh()->status->value);
    }

    public function test_only_the_canonical_active_batch_can_record_arrival_when_legacy_work_conflicts(): void
    {
        [$currentLeg, $rider, $shop] = $this->fixture('in_transit');
        $currentBatch = $currentLeg->deliveryBatch;
        $currentBatch->update(['started_at' => now()->subMinutes(10)]);
        $profile = $currentBatch->riderProfile;
        $laterBatch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_profile_id' => $profile->id,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
        $laterLeg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'delivery_batch_id' => $laterBatch->id,
            'status' => 'in_transit',
            'destination_snapshot' => ['type' => 'customer', 'latitude' => 14.301, 'longitude' => 120.951],
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $laterLeg->id,
            'rider_profile_id' => $profile->id,
            'status' => 'accepted',
        ]);

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$laterLeg->id}/arrivals", $this->arrivalPayload('dropoff'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('active_work');

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$currentLeg->id}/arrivals", $this->arrivalPayload('dropoff'))
            ->assertCreated();
    }

    public function test_reassigned_pickup_records_a_fresh_arrival_for_the_new_assignment(): void
    {
        [$leg, $rider] = $this->fixture('assigned');
        $firstAssignment = $leg->assignments()->sole();

        $first = $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/arrivals", $this->arrivalPayload())
            ->assertCreated();

        $firstAssignment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $secondAssignment = DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $firstAssignment->rider_profile_id,
            'status' => 'accepted',
        ]);

        $second = $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/arrivals", $this->arrivalPayload())
            ->assertCreated();

        $this->assertNotSame($first->json('arrival.id'), $second->json('arrival.id'));
        $this->assertSame(2, $leg->events()->where('event_type', 'pickup_arrived')->count());
        $this->assertSame(
            $secondAssignment->id,
            $leg->events()->latest('id')->value('metadata')['delivery_assignment_id'],
        );
    }

    public function test_location_exceptions_require_and_record_an_allowed_reason(): void
    {
        $cases = [
            'outside_geofence' => ['latitude' => 15],
            'low_accuracy' => ['accuracy_m' => 150],
            'unusable_accuracy' => ['accuracy_m' => 50000],
            'missing_gps' => ['latitude' => null, 'longitude' => null],
            'stale_gps' => ['captured_at' => now()->subMinutes(6)->toISOString()],
            'future_gps' => ['captured_at' => now()->addMinutes(2)->toISOString()],
            'missing_target' => [],
        ];

        foreach ($cases as $name => $changes) {
            [$leg, $rider] = $this->fixture('assigned');
            if ($name === 'missing_target') {
                $leg->update(['origin_snapshot' => ['type' => 'shop']]);
            }
            $payload = [...$this->arrivalPayload(), ...$changes];

            $expectedResult = match ($name) {
                'outside_geofence' => 'outside_geofence',
                'low_accuracy' => 'low_accuracy',
                default => 'location_unavailable',
            };

            $this->actingAs($rider, 'user')
                ->postJson("/api/logistics/legs/{$leg->id}/arrivals", $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('exception_reason')
                ->assertJsonPath('errors.arrival_result.0', $expectedResult);

            $this->actingAs($rider, 'user')
                ->postJson("/api/logistics/legs/{$leg->id}/arrivals", [
                    ...$payload,
                    'exception_reason' => 'gps_inaccurate',
                ])
                ->assertCreated();

            $this->assertSame(1, $leg->events()->count(), $name);
            $this->assertSame(
                in_array($name, ['unusable_accuracy', 'missing_gps', 'stale_gps', 'future_gps', 'missing_target'], true)
                    ? 'location_unavailable'
                    : $name,
                $leg->events()->sole()->metadata['result'],
                $name
            );
        }
    }

    public function test_other_exception_reason_requires_notes(): void
    {
        [$leg, $rider] = $this->fixture('assigned');

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/arrivals", [
                ...$this->arrivalPayload(),
                'accuracy_m' => 150,
                'exception_reason' => 'other',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('exception_notes');
    }

    public function test_only_the_assigned_rider_can_record_arrival(): void
    {
        [$leg, $rider, $shop] = $this->fixture('assigned');
        $otherRider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $otherRider->givePermissionTo('update-logistics-status');
        $crossTenant = User::factory()->create(['shop_owner_id' => ShopOwner::factory()->create()->id]);
        $crossTenant->givePermissionTo('update-logistics-status');
        $customer = User::factory()->create(['shop_owner_id' => null]);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        Permission::findOrCreate('assign-logistics-deliveries', 'user');
        $dispatcher->givePermissionTo('assign-logistics-deliveries');

        foreach ([$otherRider, $crossTenant, $customer, $dispatcher] as $actor) {
            $this->actingAs($actor, 'user')
                ->postJson("/api/logistics/legs/{$leg->id}/arrivals", $this->arrivalPayload())
                ->assertForbidden();
        }

        $leg->assignments()->update(['status' => 'cancelled']);
        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/arrivals", $this->arrivalPayload())
            ->assertForbidden();

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/legs/{$leg->id}/arrivals", $this->arrivalPayload())
            ->assertForbidden();
    }

    public function test_arrival_requires_the_correct_leg_and_batch_status(): void
    {
        [$pickupLeg, $pickupRider] = $this->fixture('in_transit');
        $this->actingAs($pickupRider, 'user')
            ->postJson("/api/logistics/legs/{$pickupLeg->id}/arrivals", $this->arrivalPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        [$dropoffLeg, $dropoffRider] = $this->fixture('assigned');
        $this->actingAs($dropoffRider, 'user')
            ->postJson("/api/logistics/legs/{$dropoffLeg->id}/arrivals", $this->arrivalPayload('dropoff'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        [$batchedLeg, $batchedRider] = $this->fixture('assigned', 'accepted');
        $this->actingAs($batchedRider, 'user')
            ->postJson("/api/logistics/legs/{$batchedLeg->id}/arrivals", $this->arrivalPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('batch');
    }

    public function test_arrival_payload_is_validated(): void
    {
        [$leg, $rider] = $this->fixture('assigned');

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/arrivals", [
                'arrival_type' => 'warehouse',
                'latitude' => 91,
                'longitude' => -181,
                'accuracy_m' => -1,
                'captured_at' => 'not-a-date',
                'exception_reason' => 'made_up',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'arrival_type',
                'latitude',
                'longitude',
                'accuracy_m',
                'captured_at',
                'exception_reason',
            ]);
    }

    private function fixture(string $status, string $batchStatus = 'in_progress'): array
    {
        Permission::findOrCreate('update-logistics-status', 'user');
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'arrival_radius_m' => 100]);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->givePermissionTo('update-logistics-status');
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);
        $batch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_profile_id' => $profile->id,
            'status' => $batchStatus,
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'delivery_batch_id' => $batch->id,
            'status' => $status,
            'origin_snapshot' => ['type' => 'shop', 'latitude' => 14.3, 'longitude' => 120.95],
            'destination_snapshot' => ['type' => 'customer', 'latitude' => 14.301, 'longitude' => 120.951],
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $profile->id,
            'status' => 'accepted',
        ]);

        return [$leg, $rider, $shop];
    }

    private function arrivalPayload(string $type = 'pickup'): array
    {
        return [
            'arrival_type' => $type,
            'latitude' => $type === 'pickup' ? 14.3 : 14.301,
            'longitude' => $type === 'pickup' ? 120.95 : 120.951,
            'accuracy_m' => 15,
            'captured_at' => now()->toISOString(),
        ];
    }
}
