<?php

namespace Tests\Feature\Logistics;

use App\Models\ShopOwner;
use App\Models\User;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        Permission::findOrCreate('view-logistics-shipments', 'user');
        $staff->givePermissionTo('view-logistics-shipments');
        $this->actingAs($staff->fresh(), 'user')->get('/erp/logistics/shipments')->assertOk();
        $this->actingAs($staff->fresh(), 'user')->get('/erp/logistics/riders')->assertForbidden();

        Permission::findOrCreate('manage-logistics-riders', 'user');
        $staff->givePermissionTo('manage-logistics-riders');

        $this->actingAs($staff->fresh(), 'user')->get('/erp/logistics/riders')->assertOk();
    }

    public function test_logistics_rider_can_access_shipments_page_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $shop = ShopOwner::factory()->create();
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->assignRole('Logistics Rider');

        $this->assertTrue($rider->fresh()->can('view-logistics-shipments'));
        $this->assertTrue($rider->fresh()->can('access-logistics-dashboard'));

        $this->actingAs($rider->fresh(), 'user')->get('/erp/logistics')->assertOk();
        $this->actingAs($rider->fresh(), 'user')->get('/erp/logistics/shipments')->assertOk();
        $this->actingAs($rider->fresh(), 'user')->get('/erp/logistics/riders')->assertForbidden();
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
            ->get('/erp/logistics/shipments')
            ->assertOk();

        $shipmentIds = collect($response->viewData('page')['props']['shipments']['data'])
            ->pluck('id')
            ->all();

        $this->assertSame([$assignedShipment->id], $shipmentIds);
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
