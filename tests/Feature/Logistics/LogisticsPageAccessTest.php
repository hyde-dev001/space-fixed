<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\Product;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LogisticsPageAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_owner_can_access_logistics_pages(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'both']);

        $this->actingAs($shop, 'shop_owner')->get('/shop-owner/logistics')->assertOk();
        $this->actingAs($shop, 'shop_owner')->get('/shop-owner/logistics/shipments')->assertOk();
        $this->actingAs($shop, 'shop_owner')->get('/shop-owner/logistics/riders')->assertOk();
    }

    public function test_erp_user_needs_logistics_permission(): void
    {
        $shop = ShopOwner::factory()->create();
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);

        $this->actingAs($staff, 'user')->get('/erp/logistics')->assertForbidden();

        Permission::findOrCreate('access-logistics-dashboard', 'user');
        $staff->givePermissionTo('access-logistics-dashboard');

        $this->actingAs($staff->fresh(), 'user')->get('/erp/logistics')->assertOk();
        $this->actingAs($staff->fresh(), 'user')->get('/erp/logistics/shipments')->assertForbidden();
        Permission::findOrCreate('assign-logistics-deliveries', 'user');
        $staff->givePermissionTo('assign-logistics-deliveries');
        $this->actingAs($staff->fresh(), 'user')->get('/erp/logistics/shipments')->assertOk();
        $this->actingAs($staff->fresh(), 'user')->get('/erp/logistics/riders')->assertForbidden();

        Permission::findOrCreate('manage-logistics-riders', 'user');
        $staff->givePermissionTo('manage-logistics-riders');

        $this->actingAs($staff->fresh(), 'user')->get('/erp/logistics/riders')->assertOk();
    }

    public function test_settings_page_requires_configuration_permission(): void
    {
        $shop = ShopOwner::factory()->create([
            'shop_latitude' => 14.599512,
            'shop_longitude' => 120.984222,
        ]);
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);

        $this->actingAs($staff, 'user')->get('/erp/logistics/settings')->assertForbidden();
        Permission::findOrCreate('configure-logistics-settings', 'user');
        $staff->givePermissionTo('configure-logistics-settings');

        $this->actingAs($staff->fresh(), 'user')->get('/erp/logistics/settings')
            ->assertOk()->assertInertia(fn ($page) => $page
            ->component('ERP/Logistics/Settings')
            ->where('shopLocation.latitude', (float) $shop->shop_latitude)
            ->where('shopLocation.longitude', (float) $shop->shop_longitude)
            ->where('shopLocation.address', $shop->business_address));
    }

    public function test_batches_page_requires_batch_management_permission(): void
    {
        $shop = ShopOwner::factory()->create();
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        $this->actingAs($staff, 'user')->get('/erp/logistics/batches')->assertForbidden();
        Permission::findOrCreate('manage-logistics-batches', 'user');
        $staff->givePermissionTo('manage-logistics-batches');
        $this->actingAs($staff->fresh(), 'user')->get('/erp/logistics/batches')
            ->assertOk()->assertInertia(fn ($page) => $page->component('ERP/Logistics/Batches'));
    }

    public function test_batches_page_includes_tenant_stop_details_and_capacity(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'retail']);
        $otherShop = ShopOwner::factory()->create();
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'daily_rider_capacity' => 12]);
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        Permission::findOrCreate('manage-logistics-batches', 'user');
        $staff->givePermissionTo('manage-logistics-batches');

        $stopSnapshot = [[
            'id' => 901,
            'stop_sequence' => 1,
            'status' => 'delivered',
            'destination_snapshot' => ['name' => 'Saved Ana', 'address' => 'Saved address'],
        ]];
        $batch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'completed',
            'stop_snapshot' => $stopSnapshot,
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => 55,
        ]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'delivery_batch_id' => $batch->id,
            'destination_snapshot' => ['name' => 'Ana Reyes', 'address' => 'Dasmarinas, Cavite'],
        ]);
        DeliveryBatch::factory()->create(['shop_owner_id' => $otherShop->id]);

        $props = $this->actingAs($staff->fresh(), 'user')->get('/erp/logistics/batches')
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(12, $props['dailyRiderCapacity']);
        $this->assertSame([$batch->id], collect($props['batches'])->pluck('id')->all());
        $this->assertSame('order', $props['batches'][0]['legs'][0]['shipment']['source_type']);
        $this->assertSame(55, $props['batches'][0]['legs'][0]['shipment']['source_id']);
        $this->assertArrayNotHasKey('order_summary', $props['batches'][0]['legs'][0]['shipment']);
        $this->assertSame('Ana Reyes', $props['batches'][0]['legs'][0]['destination_snapshot']['name']);
        $this->assertSame($stopSnapshot, $props['batches'][0]['stop_snapshot']);
    }

    public function test_batches_page_filters_every_collection_by_module_but_keeps_unscheduled_slot_agnostic(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'both']);
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        Permission::findOrCreate('manage-logistics-batches', 'user');
        $staff->givePermissionTo('manage-logistics-batches');
        $date = '2026-07-15';
        $retailShipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order']);
        $repairShipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'repair_request']);
        $retailBatch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'delivery_date' => $date,
            'delivery_window' => 'morning',
        ]);
        $repairBatch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'delivery_date' => $date,
            'delivery_window' => 'morning',
        ]);
        $mixedBatch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'delivery_date' => $date,
            'delivery_window' => 'morning',
        ]);
        ShipmentLeg::factory()->create(['shipment_id' => $retailShipment->id, 'delivery_batch_id' => $retailBatch->id]);
        ShipmentLeg::factory()->create(['shipment_id' => $repairShipment->id, 'delivery_batch_id' => $repairBatch->id]);
        ShipmentLeg::factory()->create(['shipment_id' => $retailShipment->id, 'delivery_batch_id' => $mixedBatch->id]);
        ShipmentLeg::factory()->create(['shipment_id' => $repairShipment->id, 'delivery_batch_id' => $mixedBatch->id]);
        $repairPool = ShipmentLeg::factory()->create([
            'shipment_id' => $repairShipment->id,
            'scheduled_delivery_date' => $date,
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'status' => 'pending',
        ]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $retailShipment->id,
            'scheduled_delivery_date' => $date,
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'status' => 'pending',
        ]);
        $repairUnscheduled = ShipmentLeg::factory()->create([
            'shipment_id' => $repairShipment->id,
            'scheduled_delivery_date' => null,
            'delivery_window' => null,
            'schedule_status' => 'unscheduled',
            'status' => 'pending',
        ]);

        $repairProps = $this->actingAs($staff, 'user')
            ->get("/erp/logistics/batches?module=repair&date={$date}&window=morning")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame([$repairBatch->id], collect($repairProps['batches'])->pluck('id')->all());
        $this->assertSame([$repairPool->id], collect($repairProps['pool'])->pluck('id')->all());
        $this->assertSame([$repairUnscheduled->id], collect($repairProps['unscheduled'])->pluck('id')->all());
        $this->assertSame('repair', $repairProps['filters']['module']);

        $allProps = $this->actingAs($staff, 'user')
            ->get("/erp/logistics/batches?module=all&date={$date}&window=morning")
            ->assertOk()
            ->viewData('page')['props'];
        $mixed = collect($allProps['batches'])->firstWhere('id', $mixedBatch->id);

        $this->assertSame('mixed', $mixed['module']);
        $this->assertEqualsCanonicalizing(
            [$retailBatch->id, $repairBatch->id, $mixedBatch->id],
            collect($allProps['batches'])->pluck('id')->all(),
        );
    }

    public function test_logistics_rider_can_access_my_deliveries_through_legacy_shipments_link(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $shop = ShopOwner::factory()->create();
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->assignRole('Logistics Rider');
        RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);

        $this->assertTrue($rider->fresh()->can('view-logistics-shipments'));
        $this->assertTrue($rider->fresh()->can('access-logistics-dashboard'));

        $this->actingAs($rider->fresh(), 'user')->get('/erp/logistics')->assertOk();
        $this->actingAs($rider->fresh(), 'user')->get('/erp/logistics/shipments')
            ->assertRedirect('/erp/logistics/deliveries');
        $this->actingAs($rider->fresh(), 'user')->get('/erp/logistics/deliveries')->assertOk();
        $this->actingAs($rider->fresh(), 'user')->get('/erp/logistics/riders')->assertForbidden();
    }

    public function test_dispatcher_cannot_access_my_deliveries(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = ShopOwner::factory()->create();
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole('Logistics Dispatcher');

        $this->actingAs($dispatcher, 'user')->get('/erp/logistics/deliveries')->assertForbidden();
    }

    public function test_logistics_rider_only_sees_assigned_shipments(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = ShopOwner::factory()->create();
        $riderUser = User::factory()->create(['shop_owner_id' => $shop->id]);
        $riderUser->assignRole('Logistics Rider');
        $otherUser = User::factory()->create(['shop_owner_id' => $shop->id]);
        $otherUser->assignRole('Logistics Rider');

        $riderProfile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $riderUser->id,
        ]);
        $otherProfile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $otherUser->id,
        ]);

        $assignedOrder = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'order_number' => 'ORD-RIDER-001',
        ]);
        $assignedShipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $assignedOrder->id,
        ]);
        $assignedLeg = ShipmentLeg::factory()->create(['shipment_id' => $assignedShipment->id, 'status' => 'assigned']);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $assignedLeg->id,
            'rider_profile_id' => $riderProfile->id,
            'status' => 'accepted',
        ]);

        $otherShipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $otherLeg = ShipmentLeg::factory()->create(['shipment_id' => $otherShipment->id, 'status' => 'assigned']);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $otherLeg->id,
            'rider_profile_id' => $otherProfile->id,
            'status' => 'accepted',
        ]);

        $response = $this->actingAs($riderUser->fresh(), 'user')
            ->get('/erp/logistics/deliveries')
            ->assertOk();

        $deliveryData = $response->viewData('page')['props']['deliveryData'];
        $this->assertSame("single:{$assignedLeg->id}", $deliveryData['up_next']['key']);
        $this->assertNotSame("single:{$otherLeg->id}", $deliveryData['up_next']['key']);
    }

    public function test_logistics_rider_receives_safe_pickup_and_dropoff_arrival_summaries(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = ShopOwner::factory()->create();
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->assignRole('Logistics Rider');
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);

        $pickupLeg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'assigned',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $pickupLeg->id,
            'rider_profile_id' => $profile->id,
            'status' => 'accepted',
        ]);
        $pickupEvent = $pickupLeg->events()->create([
            'shipment_id' => $pickupLeg->shipment_id,
            'event_type' => 'pickup_arrived',
            'visibility' => 'internal',
            'metadata' => [
                'result' => 'verified',
                'distance_m' => 18.2,
                'radius_m' => 100,
                'accuracy_m' => 12,
                'latitude' => 14.654321,
                'longitude' => 121.123456,
            ],
        ]);

        $dropoffLeg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'in_transit',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $dropoffLeg->id,
            'rider_profile_id' => $profile->id,
        ]);
        $dropoffEvent = $dropoffLeg->events()->create([
            'shipment_id' => $dropoffLeg->shipment_id,
            'event_type' => 'dropoff_arrived',
            'visibility' => 'internal',
            'metadata' => [
                'result' => 'outside_geofence',
                'distance_m' => 154.6,
                'radius_m' => 100,
                'accuracy_m' => 15,
                'latitude' => 14.765432,
                'longitude' => 121.234567,
                'exception_reason' => 'alternate_meeting_point',
            ],
        ]);

        $props = $this->actingAs($rider->fresh(), 'user')
            ->get('/erp/logistics/deliveries')
            ->assertOk()
            ->viewData('page')['props'];

        $pickup = $props['deliveryData']['up_next']['deliveries'][0];
        $dropoff = $props['deliveryData']['current']['deliveries'][0];
        $this->assertSame('verified', $pickup['arrivals']['pickup']['result']);
        $this->assertSame(18.2, $pickup['arrivals']['pickup']['distance_m']);
        $this->assertSame($pickupEvent->created_at->toISOString(), $pickup['arrivals']['pickup']['recorded_at']);
        $this->assertSame('outside_geofence', $dropoff['arrivals']['dropoff']['result']);
        $this->assertSame($dropoffEvent->created_at->toISOString(), $dropoff['arrivals']['dropoff']['recorded_at']);
        $this->assertArrayNotHasKey('events', $pickup);
        $this->assertArrayNotHasKey('events', $dropoff);
        $serialized = json_encode($props);
        $this->assertStringNotContainsString('14.654321', $serialized);
        $this->assertStringNotContainsString('121.234567', $serialized);
    }

    public function test_reassigned_pickup_hides_the_previous_assignments_arrival(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = ShopOwner::factory()->create();
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->assignRole('Logistics Rider');
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'assigned',
        ]);
        $previousAssignment = DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $profile->id,
            'status' => 'cancelled',
        ]);
        $previousArrival = $leg->events()->create([
            'shipment_id' => $leg->shipment_id,
            'event_type' => 'pickup_arrived',
            'visibility' => 'internal',
            'metadata' => [
                'delivery_assignment_id' => $previousAssignment->id,
                'result' => 'verified',
            ],
        ]);
        $currentAssignment = DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $profile->id,
            'status' => 'accepted',
        ]);

        $props = $this->actingAs($rider->fresh(), 'user')
            ->get('/erp/logistics/deliveries')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame([], $props['deliveryData']['up_next']['deliveries'][0]['arrivals']);

        $currentArrival = $leg->events()->create([
            'shipment_id' => $leg->shipment_id,
            'event_type' => 'pickup_arrived',
            'visibility' => 'internal',
            'metadata' => [
                'delivery_assignment_id' => $currentAssignment->id,
                'result' => 'verified',
            ],
        ]);

        $refreshed = $this->actingAs($rider->fresh(), 'user')
            ->get('/erp/logistics/deliveries')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(
            $currentArrival->id,
            $refreshed['deliveryData']['up_next']['deliveries'][0]['arrivals']['pickup']['id'],
        );
        $this->assertNotSame($previousArrival->id, $currentArrival->id);
    }

    public function test_dispatcher_pages_receive_the_same_safe_arrival_summaries_without_coordinates(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'both']);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        Permission::findOrCreate('assign-logistics-deliveries', 'user');
        Permission::findOrCreate('manage-logistics-batches', 'user');
        $dispatcher->givePermissionTo(['assign-logistics-deliveries', 'manage-logistics-batches']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $batch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'delivery_batch_id' => $batch->id,
        ]);
        $pickup = $leg->events()->create([
            'shipment_id' => $shipment->id,
            'event_type' => 'pickup_arrived',
            'visibility' => 'internal',
            'metadata' => [
                'result' => 'verified',
                'distance_m' => 18.2,
                'radius_m' => 100,
                'accuracy_m' => 12,
                'latitude' => 14.654321,
                'longitude' => 121.123456,
            ],
        ]);
        $leg->events()->create([
            'shipment_id' => $shipment->id,
            'event_type' => 'dropoff_arrived',
            'visibility' => 'internal',
            'metadata' => [
                'result' => 'outside_geofence',
                'distance_m' => 154.6,
                'radius_m' => 100,
                'accuracy_m' => 15,
                'latitude' => 14.765432,
                'longitude' => 121.234567,
                'exception_reason' => 'pin_incorrect',
                'exception_notes' => 'Customer met rider at the gate.',
            ],
        ]);
        $otherShop = ShopOwner::factory()->create();
        $otherBatch = DeliveryBatch::factory()->create(['shop_owner_id' => $otherShop->id]);
        $otherShipment = Shipment::factory()->create(['shop_owner_id' => $otherShop->id]);
        $otherLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $otherShipment->id,
            'delivery_batch_id' => $otherBatch->id,
        ]);
        $otherLeg->events()->create([
            'shipment_id' => $otherShipment->id,
            'event_type' => 'dropoff_arrived',
            'visibility' => 'internal',
            'metadata' => [
                'result' => 'verified',
                'distance_m' => 9,
                'radius_m' => 100,
                'accuracy_m' => 8,
                'latitude' => 15.111111,
                'longitude' => 122.222222,
            ],
        ]);
        $this->assertTrue($dispatcher->fresh()->can('assign-logistics-deliveries'));

        $shipmentProps = $this->actingAs($dispatcher->fresh(), 'user')
            ->get('/erp/logistics/shipments')
            ->assertOk()
            ->viewData('page')['props'];
        $shipmentLeg = collect($shipmentProps['shipments']['data'])
            ->firstWhere('id', $shipment->id)['legs'][0];

        $batchProps = $this->actingAs($dispatcher->fresh(), 'user')
            ->get('/erp/logistics/batches')
            ->assertOk()
            ->viewData('page')['props'];
        $batchLeg = collect($batchProps['batches'])
            ->firstWhere('id', $batch->id)['legs'][0];

        foreach ([$shipmentLeg, $batchLeg] as $payload) {
            $this->assertSame('verified', $payload['arrivals']['pickup']['result']);
            $this->assertSame(18.2, $payload['arrivals']['pickup']['distance_m']);
            $this->assertSame($pickup->created_at->toISOString(), $payload['arrivals']['pickup']['recorded_at']);
            $this->assertSame('outside_geofence', $payload['arrivals']['dropoff']['result']);
            $this->assertSame('pin_incorrect', $payload['arrivals']['dropoff']['exception_reason']);
            $this->assertSame('Customer met rider at the gate.', $payload['arrivals']['dropoff']['exception_notes']);
            $this->assertArrayNotHasKey('events', $payload);
        }

        $this->assertNotContains($otherShipment->id, collect($shipmentProps['shipments']['data'])->pluck('id'));
        $this->assertNotContains($otherBatch->id, collect($batchProps['batches'])->pluck('id'));
        $serialized = json_encode([$shipmentProps, $batchProps]);
        $this->assertStringNotContainsString('14.654321', $serialized);
        $this->assertStringNotContainsString('121.234567', $serialized);
        $this->assertStringNotContainsString('15.111111', $serialized);
    }

    public function test_offered_batch_stays_out_of_delivery_table_until_rider_accepts(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = ShopOwner::factory()->create();
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->assignRole('Logistics Rider');
        $profile = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'linked_type' => User::class, 'linked_id' => $rider->id]);
        $batch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id, 'rider_profile_id' => $profile->id, 'status' => 'offered']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'delivery_batch_id' => $batch->id]);
        $assignment = DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $profile->id, 'status' => 'assigned']);

        $response = $this->actingAs($rider->fresh(), 'user')->get('/erp/logistics/deliveries')->assertOk();
        $deliveryData = $response->viewData('page')['props']['deliveryData'];
        $this->assertSame(["batch:{$batch->id}"], collect($deliveryData['offers'])->pluck('key')->all());
        $this->assertNull($deliveryData['up_next']);

        $batch->update(['status' => 'accepted']);
        $assignment->update(['status' => 'accepted']);
        $response = $this->actingAs($rider->fresh(), 'user')->get('/erp/logistics/deliveries')->assertOk();
        $deliveryData = $response->viewData('page')['props']['deliveryData'];
        $this->assertEmpty($deliveryData['offers']);
        $this->assertSame("batch:{$batch->id}", $deliveryData['up_next']['key']);
    }

    public function test_offered_standalone_delivery_waits_for_rider_acceptance(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = ShopOwner::factory()->create();
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->assignRole('Logistics Rider');
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'assigned']);
        $assignment = DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $profile->id,
            'status' => 'assigned',
        ]);

        $response = $this->actingAs($rider->fresh(), 'user')->get('/erp/logistics/deliveries')->assertOk();
        $deliveryData = $response->viewData('page')['props']['deliveryData'];
        $this->assertSame(["single:{$leg->id}"], collect($deliveryData['offers'])->pluck('key')->all());
        $this->assertNull($deliveryData['up_next']);

        $assignment->update(['status' => 'accepted', 'accepted_at' => now()]);
        $response = $this->actingAs($rider->fresh(), 'user')->get('/erp/logistics/deliveries')->assertOk();
        $deliveryData = $response->viewData('page')['props']['deliveryData'];
        $this->assertEmpty($deliveryData['offers']);
        $this->assertSame("single:{$leg->id}", $deliveryData['up_next']['key']);
    }

    public function test_logistics_rider_today_filter_applies_to_the_lower_list(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-07-15 01:00:00 UTC');
        config(['app.shop_timezone' => 'Asia/Manila', 'app.timezone' => 'UTC']);

        try {
            $shop = ShopOwner::factory()->create();
            $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
            $rider->assignRole('Logistics Rider');
            $otherRider = User::factory()->create(['shop_owner_id' => $shop->id]);
            $riderProfile = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'linked_type' => User::class, 'linked_id' => $rider->id]);
            $otherProfile = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'linked_type' => User::class, 'linked_id' => $otherRider->id]);

            $firstToday = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
            $firstTodayLeg = ShipmentLeg::factory()->create([
                'shipment_id' => $firstToday->id,
                'status' => 'assigned',
                'scheduled_delivery_date' => '2026-07-15',
                'delivery_window' => 'morning',
            ]);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $firstTodayLeg->id, 'rider_profile_id' => $riderProfile->id, 'status' => 'accepted', 'assigned_at' => '2026-07-15 00:30:00 UTC']);
            $secondToday = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
            $secondTodayLeg = ShipmentLeg::factory()->create([
                'shipment_id' => $secondToday->id,
                'status' => 'assigned',
                'scheduled_delivery_date' => '2026-07-15',
                'delivery_window' => 'afternoon',
            ]);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $secondTodayLeg->id, 'rider_profile_id' => $riderProfile->id, 'status' => 'accepted', 'assigned_at' => '2026-07-15 00:30:00 UTC']);

            $outside = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
            $outsideLeg = ShipmentLeg::factory()->create([
                'shipment_id' => $outside->id,
                'status' => 'assigned',
                'scheduled_delivery_date' => '2026-07-16',
            ]);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $outsideLeg->id, 'rider_profile_id' => $riderProfile->id, 'status' => 'accepted', 'assigned_at' => '2026-07-15 00:30:00 UTC']);

            $otherRiderShipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
            $otherRiderLeg = ShipmentLeg::factory()->create([
                'shipment_id' => $otherRiderShipment->id,
                'status' => 'assigned',
                'scheduled_delivery_date' => '2026-07-15',
            ]);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $otherRiderLeg->id, 'rider_profile_id' => $otherProfile->id, 'status' => 'accepted', 'assigned_at' => '2026-07-15 00:30:00 UTC']);

            $excludedStatus = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
            $excludedLeg = ShipmentLeg::factory()->create(['shipment_id' => $excludedStatus->id, 'status' => 'pending']);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $excludedLeg->id, 'rider_profile_id' => $riderProfile->id, 'assigned_at' => '2026-07-15 00:30:00 UTC']);

            $response = $this->actingAs($rider->fresh(), 'user')
                ->get('/erp/logistics/deliveries?window=today')
                ->assertOk();

            $deliveryData = $response->viewData('page')['props']['deliveryData'];
            $this->assertSame("single:{$firstTodayLeg->id}", $deliveryData['up_next']['key']);
            $this->assertSame(["single:{$secondTodayLeg->id}"], collect($deliveryData['list']['data'])->pluck('key')->all());
            $this->assertSame('today', $deliveryData['filters']['window']);
            $this->assertNotContains("single:{$outsideLeg->id}", collect($deliveryData['list']['data'])->pluck('key')->all());
            $this->assertNotContains("single:{$otherRiderLeg->id}", collect($deliveryData['list']['data'])->pluck('key')->all());
            $this->assertNotContains("single:{$excludedLeg->id}", collect($deliveryData['list']['data'])->pluck('key')->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_logistics_rider_week_filter_uses_monday_to_sunday_in_shop_timezone(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-07-15 01:00:00 UTC');
        config(['app.shop_timezone' => 'Asia/Manila', 'app.timezone' => 'UTC']);

        try {
            $shop = ShopOwner::factory()->create();
            $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
            $rider->assignRole('Logistics Rider');
            $profile = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'linked_type' => User::class, 'linked_id' => $rider->id]);

            $monday = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
            $mondayLeg = ShipmentLeg::factory()->create(['shipment_id' => $monday->id, 'status' => 'assigned', 'scheduled_delivery_date' => '2026-07-13']);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $mondayLeg->id, 'rider_profile_id' => $profile->id, 'assigned_at' => '2026-07-12 16:00:00 UTC']);

            $sunday = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
            $sundayLeg = ShipmentLeg::factory()->create(['shipment_id' => $sunday->id, 'status' => 'assigned', 'scheduled_delivery_date' => '2026-07-19']);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $sundayLeg->id, 'rider_profile_id' => $profile->id, 'assigned_at' => '2026-07-19 15:59:59 UTC']);

            $outside = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
            $outsideLeg = ShipmentLeg::factory()->create(['shipment_id' => $outside->id, 'status' => 'assigned', 'scheduled_delivery_date' => '2026-07-20']);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $outsideLeg->id, 'rider_profile_id' => $profile->id, 'assigned_at' => '2026-07-19 16:00:00 UTC']);

            $response = $this->actingAs($rider->fresh(), 'user')
                ->get('/erp/logistics/deliveries?tab=all&window=week')
                ->assertOk();

            $this->assertEqualsCanonicalizing(
                ["single:{$mondayLeg->id}", "single:{$sundayLeg->id}"],
                collect($response->viewData('page')['props']['deliveryData']['list']['data'])->pluck('key')->all(),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dispatcher_shipments_include_only_the_latest_failed_delivery_attempt_per_leg(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');

        $shop = ShopOwner::factory()->create(['business_type' => 'both']);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'max_delivery_attempts' => 3]);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole('Logistics Dispatcher');
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'old', 'attempted_at' => '2026-07-14 10:00:00']);
        $latest = $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'latest', 'attempted_at' => '2026-07-14 11:00:00']);
        $pickupShipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'repair_request',
            'purpose' => 'repair_pickup',
        ]);
        $pickupLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $pickupShipment->id,
            'status' => 'needs_resolution',
            'resolution_type' => 'pickup_failed',
        ]);
        $pickupAttempt = $pickupLeg->attempts()->create([
            'attempt_type' => 'pickup',
            'status' => 'failed',
            'attempt_number' => 1,
            'reason_code' => 'customer_unavailable',
            'file_path' => 'logistics-attempt/pickup.jpg',
            'attempted_at' => '2026-07-29 10:00:00',
        ]);
        Storage::disk('local')->put('logistics-attempt/pickup.jpg', 'pickup-evidence');
        $clean = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        ShipmentLeg::factory()->create(['shipment_id' => $clean->id]);

        $response = $this->actingAs($dispatcher->fresh(), 'user')
            ->get('/erp/logistics/shipments')
            ->assertOk();

        $shipmentPayload = collect($response->viewData('page')['props']['shipments']['data'])->firstWhere('id', $shipment->id);
        $attempts = collect($shipmentPayload['legs'][0]['attempts']);
        $this->assertSame([$latest->id], $attempts->pluck('id')->all());
        $this->assertSame(2, $shipmentPayload['legs'][0]['failed_attempt_count']);
        $this->assertSame(3, $response->viewData('page')['props']['maxDeliveryAttempts']);
        $this->assertTrue($response->viewData('page')['props']['canAssign']);
        $pickupPayload = collect($response->viewData('page')['props']['shipments']['data'])
            ->firstWhere('id', $pickupShipment->id);
        $this->assertSame($pickupAttempt->id, $pickupPayload['legs'][0]['attempts'][0]['id']);
        $this->assertArrayNotHasKey('file_path', $pickupPayload['legs'][0]['attempts'][0]);
        $this->assertSame(0, $pickupPayload['legs'][0]['failed_attempt_count']);
        $this->assertSame(1, $pickupPayload['legs'][0]['failed_pickup_count']);

        $filtered = $this->actingAs($dispatcher->fresh(), 'user')
            ->get('/erp/logistics/shipments?status=failed_attempts')->assertOk();
        $this->assertSame([$shipment->id], collect($filtered->viewData('page')['props']['shipments']['data'])->pluck('id')->all());

        $failedPickups = $this->actingAs($dispatcher->fresh(), 'user')
            ->get('/erp/logistics/shipments?status=failed_pickups')->assertOk();
        $this->assertSame([$pickupShipment->id], collect($failedPickups->viewData('page')['props']['shipments']['data'])->pluck('id')->all());
    }

    public function test_dispatcher_can_filter_shipments_by_status_and_purpose(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = ShopOwner::factory()->create(['business_type' => 'both']);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole('Logistics Dispatcher');

        $wanted = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'active',
            'purpose' => 'retail_delivery',
        ]);
        Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'completed',
            'purpose' => 'retail_delivery',
        ]);
        Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'active',
            'purpose' => 'repair_pickup',
        ]);

        $response = $this->actingAs($dispatcher, 'user')
            ->get('/erp/logistics/shipments?status=active&purpose=retail_delivery')
            ->assertOk();

        $shipmentIds = collect($response->viewData('page')['props']['shipments']['data'])
            ->pluck('id')
            ->all();

        $this->assertSame([$wanted->id], $shipmentIds);
    }

    public function test_dispatcher_searches_shipments_by_order_contact_and_product(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = ShopOwner::factory()->create(['business_type' => 'retail']);
        $otherShop = ShopOwner::factory()->create(['business_type' => 'retail']);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole('Logistics Dispatcher');

        $product = Product::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Orbit Runner',
            'slug' => 'orbit-runner-search',
            'price' => 3200,
            'brand' => 'SearchBrand',
        ]);
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'order_number' => 'ORD-SEARCH-1001',
            'customer_name' => 'Order Customer',
            'customer_phone' => '09171112222',
            'customer_address' => 'Order Address, Cavite',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => 'Orbit Runner',
            'price' => 3200,
            'quantity' => 1,
            'subtotal' => 3200,
        ]);
        $wanted = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
        ]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $wanted->id,
            'leg_type' => 'outbound',
            'destination_snapshot' => [
                'name' => 'Receiver Snapshot',
                'phone' => '09998887777',
                'address' => 'Cavite Search Address',
            ],
        ]);
        $unmatched = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'manual',
            'source_id' => 987654,
        ]);
        ShipmentLeg::factory()->create(['shipment_id' => $unmatched->id]);

        foreach ([
            (string) $wanted->id,
            'ORD-SEARCH-1001',
            'Receiver Snapshot',
            '09998887777',
            'Cavite Search Address',
            'SearchBrand',
            'Orbit Runner',
        ] as $search) {
            $props = $this->actingAs($dispatcher, 'user')
                ->get('/erp/logistics/shipments?'.http_build_query(['search' => $search]))
                ->assertOk()
                ->viewData('page')['props'];

            $this->assertSame($search, $props['filters']['search']);
            $this->assertSame([$wanted->id], collect($props['shipments']['data'])->pluck('id')->all());
        }

        $otherProduct = Product::create([
            'shop_owner_id' => $otherShop->id,
            'name' => 'Foreign Model',
            'slug' => 'foreign-model-search',
            'price' => 5000,
            'brand' => 'ForeignBrand',
        ]);
        $otherOrder = Order::factory()->create(['shop_owner_id' => $otherShop->id]);
        $otherOrder->items()->create([
            'product_id' => $otherProduct->id,
            'product_name' => 'Foreign Model',
            'price' => 5000,
            'quantity' => 1,
            'subtotal' => 5000,
        ]);
        $manipulated = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $otherOrder->id,
        ]);
        ShipmentLeg::factory()->create(['shipment_id' => $manipulated->id]);

        $localOrder = Order::factory()->create(['shop_owner_id' => $shop->id]);
        $localOrder->items()->create([
            'product_id' => $otherProduct->id,
            'product_name' => 'Neutral Saved Model',
            'price' => 2500,
            'quantity' => 1,
            'subtotal' => 2500,
        ]);
        $crossProduct = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $localOrder->id,
        ]);
        ShipmentLeg::factory()->create(['shipment_id' => $crossProduct->id]);

        $ids = collect($this->actingAs($dispatcher, 'user')
            ->get('/erp/logistics/shipments?search=ForeignBrand')
            ->assertOk()
            ->viewData('page')['props']['shipments']['data'])
            ->pluck('id')
            ->all();
        $this->assertSame([], $ids);
    }

    public function test_rider_search_remains_assignment_scoped(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = ShopOwner::factory()->create(['business_type' => 'retail']);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->assignRole('Logistics Rider');
        $otherRider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $otherRider->assignRole('Logistics Rider');
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);
        $otherProfile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $otherRider->id,
        ]);

        $assignedOrder = Order::factory()->create(['shop_owner_id' => $shop->id]);
        $assignedOrder->items()->create([
            'product_name' => 'Assigned Runner',
            'price' => 2000,
            'quantity' => 1,
            'subtotal' => 2000,
        ]);
        $assignedShipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $assignedOrder->id,
        ]);
        $assignedLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $assignedShipment->id,
            'status' => 'assigned',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $assignedLeg->id,
            'rider_profile_id' => $profile->id,
        ]);

        $hiddenOrder = Order::factory()->create(['shop_owner_id' => $shop->id]);
        $hiddenOrder->items()->create([
            'product_name' => 'Hidden Runner',
            'price' => 2500,
            'quantity' => 1,
            'subtotal' => 2500,
        ]);
        $hiddenShipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $hiddenOrder->id,
        ]);
        $hiddenLeg = ShipmentLeg::factory()->create([
            'shipment_id' => $hiddenShipment->id,
            'status' => 'assigned',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $hiddenLeg->id,
            'rider_profile_id' => $otherProfile->id,
        ]);

        $assigned = $this->actingAs($rider, 'user')
            ->get('/erp/logistics/deliveries?tab=all&search=Assigned%20Runner')
            ->assertOk()
            ->viewData('page')['props']['deliveryData'];
        $this->assertSame('Assigned Runner', $assigned['filters']['search']);
        $this->assertSame(["single:{$assignedLeg->id}"], collect($assigned['list']['data'])->pluck('key')->all());

        $hidden = $this->actingAs($rider, 'user')
            ->get('/erp/logistics/deliveries?tab=all&search=Hidden%20Runner')
            ->assertOk()
            ->viewData('page')['props']['deliveryData'];
        $this->assertSame([], collect($hidden['list']['data'])->pluck('key')->all());
    }

    public function test_dispatcher_module_filter_scopes_shipments_and_single_module_shops(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = ShopOwner::factory()->create(['business_type' => 'both (retail & repair)']);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole('Logistics Dispatcher');
        $retail = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'purpose' => 'retail_delivery',
        ]);
        $repair = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'repair_request',
            'purpose' => 'repair_return',
        ]);
        ShipmentLeg::factory()->create(['shipment_id' => $retail->id, 'delivery_window' => 'morning']);
        ShipmentLeg::factory()->create(['shipment_id' => $repair->id, 'delivery_window' => 'afternoon']);

        $response = $this->actingAs($dispatcher, 'user')
            ->get('/erp/logistics/shipments?module=repair&window=afternoon')
            ->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame('repair', $props['filters']['module']);
        $this->assertSame('afternoon', $props['filters']['window']);
        $this->assertSame(['retail', 'repair'], $props['availableModules']);
        $this->assertTrue($props['showModuleFilter']);
        $this->assertSame([$repair->id], collect($props['shipments']['data'])->pluck('id')->all());

        $repairShop = ShopOwner::factory()->create(['business_type' => 'repair']);
        $repairDispatcher = User::factory()->create(['shop_owner_id' => $repairShop->id]);
        $repairDispatcher->assignRole('Logistics Dispatcher');
        $repairOnly = Shipment::factory()->create([
            'shop_owner_id' => $repairShop->id,
            'source_type' => 'repair_request',
        ]);
        Shipment::factory()->create(['shop_owner_id' => $repairShop->id, 'source_type' => 'order']);

        $single = $this->actingAs($repairDispatcher, 'user')
            ->get('/erp/logistics/shipments?module=retail')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('repair', $single['filters']['module']);
        $this->assertFalse($single['showModuleFilter']);
        $this->assertSame([$repairOnly->id], collect($single['shipments']['data'])->pluck('id')->all());
    }

    public function test_dispatcher_pages_include_repair_source_summary(): void
    {
        $shop = ShopOwner::factory()->create(['business_type' => 'both']);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        Permission::findOrCreate('assign-logistics-deliveries', 'user');
        Permission::findOrCreate('manage-logistics-batches', 'user');
        $dispatcher->givePermissionTo(['assign-logistics-deliveries', 'manage-logistics-batches']);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'request_id' => 'REP-2026-0042',
            'customer_name' => 'Mia Santos',
            'brand' => 'Nike',
            'shoe_type' => 'Air Max 90',
        ]);
        $pickup = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_pickup',
        ]);
        ShipmentLeg::factory()->create(['shipment_id' => $pickup->id]);
        $return = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_return',
        ]);
        $batch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $return->id,
            'delivery_batch_id' => $batch->id,
        ]);
        $expected = [
            'request_number' => 'REP-2026-0042',
            'customer_name' => 'Mia Santos',
            'shoe_summary' => 'Nike Air Max 90',
        ];

        $shipments = $this->actingAs($dispatcher, 'user')
            ->get('/erp/logistics/shipments?module=repair')
            ->assertOk()
            ->viewData('page')['props'];
        $this->assertSame($expected, collect($shipments['shipments']['data'])
            ->firstWhere('id', $pickup->id)['source_summary']);

        $batches = $this->actingAs($dispatcher, 'user')
            ->get('/erp/logistics/batches?module=repair')
            ->assertOk()
            ->viewData('page')['props'];
        $this->assertSame($expected, collect($batches['batches'])
            ->firstWhere('id', $batch->id)['legs'][0]['shipment']['source_summary']);
    }

    public function test_logistics_pages_include_retail_order_variants_and_totals(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = ShopOwner::factory()->create(['business_type' => 'retail']);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole('Logistics Dispatcher');
        Permission::findOrCreate('manage-logistics-batches', 'user');
        $dispatcher->givePermissionTo('manage-logistics-batches');

        $product = Product::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Air Max 90',
            'slug' => 'air-max-90-logistics-test',
            'price' => 5000,
            'brand' => 'Nike',
        ]);
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'order_number' => 'ORD-LOG-1001',
        ]);
        $order->items()->createMany([
            [
                'product_id' => $product->id,
                'product_name' => 'Air Max 90',
                'price' => 5000,
                'quantity' => 2,
                'subtotal' => 10000,
                'size' => '9',
                'color' => 'Black',
                'product_image' => 'products/air-max-black.jpg',
            ],
            [
                'product_name' => 'Classic Runner',
                'price' => 3000,
                'quantity' => 3,
                'subtotal' => 9000,
                'size' => '8',
                'color' => 'White',
                'product_image' => 'products/classic-runner.jpg',
            ],
        ]);

        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
        ]);
        $batch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'delivery_batch_id' => $batch->id,
        ]);

        $shipmentProps = $this->actingAs($dispatcher, 'user')
            ->get('/erp/logistics/shipments')
            ->assertOk()
            ->viewData('page')['props'];
        $payload = collect($shipmentProps['shipments']['data'])->firstWhere('id', $shipment->id);
        $summary = $payload['order_summary'];

        $this->assertTrue($summary['available']);
        $this->assertSame('ORD-LOG-1001', $summary['order_number']);
        $this->assertSame(5, $summary['total_quantity']);
        $this->assertSame(2, $summary['variant_count']);
        $this->assertSame(2, $summary['model_count']);
        $this->assertCount(1, $payload['legs']);
        $this->assertSame([
            ['brand' => 'Nike', 'model' => 'Air Max 90', 'color' => 'Black', 'size' => '9', 'quantity' => 2],
            ['brand' => null, 'model' => 'Classic Runner', 'color' => 'White', 'size' => '8', 'quantity' => 3],
        ], collect($summary['items'])->map(fn ($item) => collect($item)
            ->only(['brand', 'model', 'color', 'size', 'quantity'])
            ->all())->all());

        $batchProps = $this->actingAs($dispatcher, 'user')
            ->get('/erp/logistics/batches')
            ->assertOk()
            ->viewData('page')['props'];
        $this->assertSame(
            $summary,
            collect($batchProps['batches'])->firstWhere('id', $batch->id)['legs'][0]['shipment']['order_summary'],
        );
    }

    public function test_missing_and_cross_shop_orders_use_safe_logistics_fallbacks(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = ShopOwner::factory()->create(['business_type' => 'both (retail & repair)']);
        $otherShop = ShopOwner::factory()->create(['business_type' => 'retail']);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole('Logistics Dispatcher');

        $otherProduct = Product::create([
            'shop_owner_id' => $otherShop->id,
            'name' => 'Secret Runner',
            'slug' => 'secret-runner-cross-shop',
            'price' => 4000,
            'brand' => 'Hidden Brand',
        ]);
        $localOrder = Order::factory()->create(['shop_owner_id' => $shop->id]);
        $localOrder->items()->create([
            'product_id' => $otherProduct->id,
            'product_name' => 'Saved Runner',
            'price' => 2500,
            'quantity' => 2,
            'subtotal' => 5000,
            'size' => '10',
            'color' => 'Gray',
        ]);
        $otherOrder = Order::factory()->create(['shop_owner_id' => $otherShop->id]);

        $local = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $localOrder->id,
        ]);
        $missing = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => 999999,
        ]);
        $crossShop = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $otherOrder->id,
        ]);
        $refund = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order_refund',
        ]);
        $repair = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'repair_request',
        ]);
        foreach ([$local, $missing, $crossShop, $refund, $repair] as $shipment) {
            ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        }

        $shipments = collect($this->actingAs($dispatcher, 'user')
            ->get('/erp/logistics/shipments')
            ->assertOk()
            ->viewData('page')['props']['shipments']['data'])
            ->keyBy('id');

        $fallback = fn (Shipment $shipment) => [
            'available' => false,
            'order_id' => $shipment->source_id,
            'order_number' => null,
            'items' => [],
            'total_quantity' => 0,
            'variant_count' => 0,
            'model_count' => 0,
        ];

        $this->assertSame($fallback($missing), $shipments[$missing->id]['order_summary']);
        $this->assertSame($fallback($crossShop), $shipments[$crossShop->id]['order_summary']);
        $this->assertSame([
            'brand' => null,
            'model' => 'Saved Runner',
            'color' => 'Gray',
            'size' => '10',
            'quantity' => 2,
        ], collect($shipments[$local->id]['order_summary']['items'][0])
            ->only(['brand', 'model', 'color', 'size', 'quantity'])
            ->all());
        $this->assertArrayNotHasKey('order_summary', $shipments[$refund->id]);
        $this->assertArrayNotHasKey('order_summary', $shipments[$repair->id]);
    }

    public function test_dispatcher_can_filter_shipments_awaiting_proof_approval(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = ShopOwner::factory()->create(['business_type' => 'both']);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole('Logistics Dispatcher');
        $awaiting = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'active']);
        ShipmentLeg::factory()->create(['shipment_id' => $awaiting->id, 'status' => 'awaiting_proof_approval']);
        ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'active'])->id, 'status' => 'in_transit']);

        $response = $this->actingAs($dispatcher, 'user')->get('/erp/logistics/shipments?status=awaiting_proof_approval')->assertOk();

        $this->assertSame([$awaiting->id], collect($response->viewData('page')['props']['shipments']['data'])->pluck('id')->all());
        $this->assertSame('awaiting_proof_approval', $response->viewData('page')['props']['filters']['status']);
    }

    public function test_dispatcher_shipments_include_available_riders_for_assignment(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = ShopOwner::factory()->create();
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole('Logistics Dispatcher');

        $available = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'availability_status' => 'available',
            'active' => true,
        ]);
        RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'availability_status' => 'busy',
            'active' => true,
        ]);

        $response = $this->actingAs($dispatcher, 'user')
            ->get('/erp/logistics/shipments')
            ->assertOk();

        $riderIds = collect($response->viewData('page')['props']['assignableRiders'])
            ->pluck('id')
            ->all();

        $this->assertSame([$available->id], $riderIds);
    }

    public function test_dispatcher_can_filter_riders_by_availability_and_type(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = ShopOwner::factory()->create();
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole('Logistics Dispatcher');

        $wanted = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_type' => 'employee',
            'availability_status' => 'available',
        ]);
        RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_type' => 'employee',
            'availability_status' => 'busy',
        ]);
        RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_type' => 'contractor',
            'availability_status' => 'available',
        ]);

        $response = $this->actingAs($dispatcher, 'user')
            ->get('/erp/logistics/riders?availability=available&type=employee')
            ->assertOk();

        $riderIds = collect($response->viewData('page')['props']['riders']['data'])
            ->pluck('id')
            ->all();

        $this->assertSame([$wanted->id], $riderIds);
    }
}
