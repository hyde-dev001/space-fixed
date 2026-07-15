<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LogisticsPageAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_owner_can_access_logistics_pages(): void
    {
        $shop = ShopOwner::factory()->create();

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
        $shop = ShopOwner::factory()->create();
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);

        $this->actingAs($staff, 'user')->get('/erp/logistics/settings')->assertForbidden();
        Permission::findOrCreate('configure-logistics-settings', 'user');
        $staff->givePermissionTo('configure-logistics-settings');

        $this->actingAs($staff->fresh(), 'user')->get('/erp/logistics/settings')
            ->assertOk()->assertInertia(fn ($page) => $page->component('ERP/Logistics/Settings'));
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
        $shop = ShopOwner::factory()->create();
        $otherShop = ShopOwner::factory()->create();
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'daily_rider_capacity' => 12]);
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        Permission::findOrCreate('manage-logistics-batches', 'user');
        $staff->givePermissionTo('manage-logistics-batches');

        $batch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id]);
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
        $this->assertSame('Ana Reyes', $props['batches'][0]['legs'][0]['destination_snapshot']['name']);
    }

    public function test_logistics_rider_can_access_my_deliveries_but_not_dispatcher_shipments(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $shop = ShopOwner::factory()->create();
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->assignRole('Logistics Rider');

        $this->assertTrue($rider->fresh()->can('view-logistics-shipments'));
        $this->assertTrue($rider->fresh()->can('access-logistics-dashboard'));

        $this->actingAs($rider->fresh(), 'user')->get('/erp/logistics')->assertOk();
        $this->actingAs($rider->fresh(), 'user')->get('/erp/logistics/shipments')->assertForbidden();
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

        $assignedShipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $assignedLeg = ShipmentLeg::factory()->create(['shipment_id' => $assignedShipment->id]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $assignedLeg->id,
            'rider_profile_id' => $riderProfile->id,
        ]);

        $otherShipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $otherLeg = ShipmentLeg::factory()->create(['shipment_id' => $otherShipment->id]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $otherLeg->id,
            'rider_profile_id' => $otherProfile->id,
        ]);

        $response = $this->actingAs($riderUser->fresh(), 'user')
            ->get('/erp/logistics/deliveries')
            ->assertOk();

        $shipmentIds = collect($response->viewData('page')['props']['shipments']['data'])
            ->pluck('id')
            ->all();

        $this->assertSame([$assignedShipment->id], $shipmentIds);
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
        $this->assertEmpty($response->viewData('page')['props']['shipments']['data']);
        $this->assertSame([$batch->id], collect($response->viewData('page')['props']['batches'])->pluck('id')->all());

        $batch->update(['status' => 'accepted']);
        $assignment->update(['status' => 'accepted']);
        $response = $this->actingAs($rider->fresh(), 'user')->get('/erp/logistics/deliveries')->assertOk();
        $this->assertSame([$shipment->id], collect($response->viewData('page')['props']['shipments']['data'])->pluck('id')->all());
    }

    public function test_logistics_rider_filters_to_their_matching_leg_status_and_today_assignment(): void
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

            $wanted = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
            $wantedLeg = ShipmentLeg::factory()->create(['shipment_id' => $wanted->id, 'status' => 'in_transit']);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $wantedLeg->id, 'rider_profile_id' => $riderProfile->id, 'assigned_at' => '2026-07-15 00:30:00 UTC']);
            $siblingLeg = ShipmentLeg::factory()->create(['shipment_id' => $wanted->id, 'status' => 'picked_up']);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $siblingLeg->id, 'rider_profile_id' => $riderProfile->id, 'assigned_at' => '2026-07-15 00:30:00 UTC']);

            $wrongStatus = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
            $wrongStatusLeg = ShipmentLeg::factory()->create(['shipment_id' => $wrongStatus->id, 'status' => 'picked_up']);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $wrongStatusLeg->id, 'rider_profile_id' => $riderProfile->id, 'assigned_at' => '2026-07-15 00:30:00 UTC']);

            $otherRiderShipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
            $otherRiderLeg = ShipmentLeg::factory()->create(['shipment_id' => $otherRiderShipment->id, 'status' => 'in_transit']);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $otherRiderLeg->id, 'rider_profile_id' => $otherProfile->id, 'assigned_at' => '2026-07-15 00:30:00 UTC']);

            $excludedStatus = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
            $excludedLeg = ShipmentLeg::factory()->create(['shipment_id' => $excludedStatus->id, 'status' => 'pending']);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $excludedLeg->id, 'rider_profile_id' => $riderProfile->id, 'assigned_at' => '2026-07-15 00:30:00 UTC']);

            $response = $this->actingAs($rider->fresh(), 'user')
                ->get('/erp/logistics/deliveries?status=in_transit&window=today')
                ->assertOk();

            $shipments = $response->viewData('page')['props']['shipments']['data'];
            $this->assertSame([$wanted->id], collect($shipments)->pluck('id')->all());
            $this->assertSame([$wantedLeg->id], collect($shipments[0]['legs'])->pluck('id')->all());
            $this->assertSame(['status' => 'in_transit', 'window' => 'today'], $response->viewData('page')['props']['filters']);

            foreach (['pending', 'not-a-status'] as $status) {
                $response = $this->actingAs($rider->fresh(), 'user')
                    ->get("/erp/logistics/deliveries?status={$status}&window=today")
                    ->assertOk();

                $this->assertEqualsCanonicalizing(
                    [$wanted->id, $wrongStatus->id, $excludedStatus->id],
                    collect($response->viewData('page')['props']['shipments']['data'])->pluck('id')->all(),
                );
                $this->assertSame('all', $response->viewData('page')['props']['filters']['status']);
            }
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
            $mondayLeg = ShipmentLeg::factory()->create(['shipment_id' => $monday->id, 'status' => 'in_transit']);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $mondayLeg->id, 'rider_profile_id' => $profile->id, 'assigned_at' => '2026-07-12 16:00:00 UTC']);

            $sunday = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
            $sundayLeg = ShipmentLeg::factory()->create(['shipment_id' => $sunday->id, 'status' => 'in_transit']);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $sundayLeg->id, 'rider_profile_id' => $profile->id, 'assigned_at' => '2026-07-19 15:59:59 UTC']);

            $outside = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
            $outsideLeg = ShipmentLeg::factory()->create(['shipment_id' => $outside->id, 'status' => 'in_transit']);
            DeliveryAssignment::factory()->create(['shipment_leg_id' => $outsideLeg->id, 'rider_profile_id' => $profile->id, 'assigned_at' => '2026-07-19 16:00:00 UTC']);

            $response = $this->actingAs($rider->fresh(), 'user')
                ->get('/erp/logistics/deliveries?window=week')
                ->assertOk();

            $this->assertEqualsCanonicalizing([$monday->id, $sunday->id], collect($response->viewData('page')['props']['shipments']['data'])->pluck('id')->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dispatcher_shipments_include_only_the_latest_failed_delivery_attempt_per_leg(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = ShopOwner::factory()->create();
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole('Logistics Dispatcher');
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'old', 'attempted_at' => '2026-07-14 10:00:00']);
        $latest = $leg->attempts()->create(['attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'latest', 'attempted_at' => '2026-07-14 11:00:00']);
        $leg->attempts()->create(['attempt_type' => 'pickup', 'status' => 'failed', 'reason_code' => 'pickup', 'attempted_at' => '2026-07-14 12:00:00']);

        $response = $this->actingAs($dispatcher->fresh(), 'user')
            ->get('/erp/logistics/shipments')
            ->assertOk();

        $attempts = collect($response->viewData('page')['props']['shipments']['data'][0]['legs'][0]['attempts']);
        $this->assertSame([$latest->id], $attempts->pluck('id')->all());
    }

    public function test_dispatcher_can_filter_shipments_by_status_and_purpose(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = ShopOwner::factory()->create();
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

    public function test_dispatcher_can_filter_shipments_awaiting_proof_approval(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = ShopOwner::factory()->create();
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
