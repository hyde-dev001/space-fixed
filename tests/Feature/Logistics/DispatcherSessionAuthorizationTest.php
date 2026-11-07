<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DispatcherSessionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_dispatcher_role_can_schedule_batch_and_single_deliveries_without_direct_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = $this->shop();
        $dispatcher = $this->dispatcher($shop);
        $dispatcher->syncPermissions([]);
        $dispatcher->assignRole('Logistics Dispatcher');
        [$riderUser, $rider] = $this->rider($shop);
        $riderUser->assignRole('Logistics Rider');
        $legs = [$this->leg($shop), $this->leg($shop), $this->leg($shop)];
        $payload = ['delivery_date' => today()->addDay()->toDateString(), 'delivery_window' => 'morning', 'leg_ids' => array_map(fn (ShipmentLeg $leg) => $leg->id, $legs)];
        $this->assertCount(0, $dispatcher->getDirectPermissions());
        $this->assertTrue($dispatcher->can('assign-logistics-deliveries'));
        $this->assertTrue($dispatcher->can('manage-logistics-batches'));
        $this->actingAs($dispatcher, 'user')->postJson('/api/logistics/legs/schedule', $payload)->assertOk();
        $payload['leg_ids'] = array_slice($payload['leg_ids'], 0, 2);
        $batchId = $this->postJson('/api/logistics/batches', $payload)->assertCreated()->json('batch.id');
        $this->postJson("/api/logistics/batches/{$batchId}/offer", ['rider_profile_id' => $rider->id])->assertOk();
        $this->postJson("/api/logistics/legs/{$legs[2]->id}/assign", ['assignment_type' => 'internal_rider', 'rider_profile_id' => $rider->id])->assertOk();
        $this->actingAs($riderUser, 'user')->postJson("/api/logistics/batches/{$batchId}/accept")->assertOk();
        $this->postJson("/api/logistics/legs/{$legs[2]->id}/accept")->assertOk();
    }

    public function test_route_authenticated_dispatcher_can_schedule_create_and_offer_batch_with_an_owner_session(): void
    {
        foreach ([false, true] as $foreignOwnerSession) {
            $shop = $this->shop();
            $dispatcher = $this->dispatcher($shop);
            [$riderUser, $rider] = $this->rider($shop);
            $legs = [$this->leg($shop), $this->leg($shop)];
            $date = today()->addDay()->toDateString();
            $payload = ['delivery_date' => $date, 'delivery_window' => 'morning', 'leg_ids' => array_map(fn (ShipmentLeg $leg) => $leg->id, $legs)];
            $ownerSession = $foreignOwnerSession ? $this->shop() : $shop;
            $this->actingAs($ownerSession, 'shop_owner')->actingAs($dispatcher, 'user')
                ->postJson('/api/logistics/legs/schedule', $payload)->assertOk();
            $batchId = $this->postJson('/api/logistics/batches', $payload)->assertCreated()->json('batch.id');
            $this->postJson("/api/logistics/batches/{$batchId}/offer", ['rider_profile_id' => $rider->id])->assertOk();
            $this->actingAs($riderUser, 'user')->postJson("/api/logistics/batches/{$batchId}/accept")
                ->assertOk()->assertJsonPath('batch.status', 'accepted');
        }
    }

    public function test_route_authenticated_dispatcher_can_assign_a_standalone_delivery_with_an_owner_session(): void
    {
        $shop = $this->shop();
        $dispatcher = $this->dispatcher($shop);
        [$riderUser, $rider] = $this->rider($shop);
        $leg = $this->leg($shop);
        $leg->update(['schedule_status' => 'scheduled', 'scheduled_delivery_date' => today()->addDay(), 'delivery_window' => 'morning']);
        $this->actingAs($this->shop(), 'shop_owner')->actingAs($dispatcher, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/assign", ['assignment_type' => 'internal_rider', 'rider_profile_id' => $rider->id])
            ->assertOk()->assertJsonPath('assignment.status', 'assigned');
        $this->actingAs($riderUser, 'user')->postJson("/api/logistics/legs/{$leg->id}/accept")
            ->assertOk()->assertJsonPath('assignment.status', 'accepted');
    }

    public function test_assigned_rider_can_accept_a_standalone_offer_with_an_owner_session(): void
    {
        $shop = $this->shop();
        [$riderUser, $rider] = $this->rider($shop);
        $leg = $this->leg($shop);
        $leg->update(['status' => 'assigned']);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'assigned']);
        $this->actingAs($this->shop(), 'shop_owner')->actingAs($riderUser, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/accept")->assertOk()->assertJsonPath('assignment.status', 'accepted');
    }

    public function test_owner_session_cannot_supply_missing_dispatcher_permission_or_cross_tenant_access(): void
    {
        $shop = $this->shop();
        $leg = $this->leg($shop);
        $user = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        $this->clockInEmployee($user);
        $payload = ['delivery_date' => today()->addDay()->toDateString(), 'delivery_window' => 'morning', 'leg_ids' => [$leg->id]];
        $this->actingAs($shop, 'shop_owner')->actingAs($user, 'user')
            ->postJson('/api/logistics/legs/schedule', $payload)->assertForbidden()
            ->assertJsonPath('code', 'LOGISTICS_ACTION_DENIED')
            ->assertJsonPath('reason', 'action_not_allowed');
        $foreignDispatcher = $this->dispatcher($this->shop());
        $this->actingAs($foreignDispatcher, 'user')->postJson('/api/logistics/legs/schedule', $payload)->assertForbidden()
            ->assertJsonPath('reason', 'cross_shop')
            ->assertJsonPath('message', 'This logistics action is not available for your account.');
        $this->assertNull($leg->fresh()->scheduled_delivery_date);
        $this->assertSame(0, $leg->assignments()->count());
    }

    public function test_dispatcher_denials_identify_module_and_delivery_state_without_mutating_the_delivery(): void
    {
        $shop = $this->shop();
        $dispatcher = $this->dispatcher($shop);
        $leg = $this->leg($shop);
        $payload = ['delivery_date' => today()->addDay()->toDateString(), 'delivery_window' => 'morning', 'leg_ids' => [$leg->id]];
        $shop->modules()->where('module_key', 'logistics')->update(['enabled' => false]);
        $this->actingAs($dispatcher, 'user')->postJson('/api/logistics/legs/schedule', $payload)
            ->assertForbidden()->assertJsonPath('reason', 'module_unavailable');
        $shop->modules()->where('module_key', 'logistics')->update(['enabled' => true]);
        $leg->update(['status' => 'cancelled']);
        $this->postJson('/api/logistics/legs/schedule', $payload)
            ->assertForbidden()->assertJsonPath('reason', 'source_state_invalid');
        [$riderUser, $rider] = $this->rider($shop);
        $this->postJson("/api/logistics/legs/{$leg->id}/assign", ['assignment_type' => 'internal_rider', 'rider_profile_id' => $rider->id])
            ->assertForbidden()->assertJsonPath('reason', 'source_state_invalid');
        $this->assertNull($leg->fresh()->scheduled_delivery_date);
        $this->assertSame(0, $leg->assignments()->count());
    }

    public function test_third_party_delivery_is_hidden_from_dispatcher_shipments_and_internal_dispatch_pools(): void
    {
        $shop = $this->shop();
        $dispatcher = $this->dispatcher($shop);
        $internal = $this->leg($shop);
        $external = $this->leg($shop);
        $externalOrder = Order::findOrFail($external->shipment->source_id);
        $externalOrder->forceFill(['delivery_method' => 'third_party', 'carrier_company' => 'Lalamove'])->save();
        $legacyExternal = $this->leg($shop);
        $legacyExternalOrder = Order::findOrFail($legacyExternal->shipment->source_id);
        $legacyExternalOrder->forceFill(['delivery_method' => null, 'carrier_company' => 'Lalamove'])->save();
        $this->assertSame('third_party', $legacyExternalOrder->resolvedDeliveryMethod());
        $this->actingAs($dispatcher, 'user')->get('/erp/logistics/batches')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('unscheduled', 1)->where('unscheduled.0.id', $internal->id));
        foreach ([$external, $legacyExternal] as $externalLeg) {
            $externalLeg->update(['scheduled_delivery_date' => today()->addDay(), 'delivery_window' => 'morning', 'schedule_status' => 'scheduled']);
        }
        $this->get('/erp/logistics/batches')->assertOk()->assertInertia(fn (Assert $page) => $page->has('pool', 0));
        $this->get('/erp/logistics/shipments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('shipments.data', 1)
            ->where('shipments.total', 1)
            ->where('shipments.data.0.id', $internal->shipment_id));
        $this->assertDatabaseHas('shipments', ['id' => $external->shipment_id]);
        $this->assertDatabaseHas('shipments', ['id' => $legacyExternal->shipment_id]);
        foreach ([$external, $legacyExternal] as $externalLeg) {
            $this->postJson('/api/logistics/legs/schedule', [
                'delivery_date' => today()->addDay()->toDateString(),
                'delivery_window' => 'morning',
                'leg_ids' => [$externalLeg->id],
            ])->assertForbidden()
                ->assertJsonPath('reason', 'third_party_tracking')
                ->assertJsonPath('message', 'This delivery uses a third-party courier. Shop riders cannot schedule or accept it.');
            $this->assertSame(0, $externalLeg->assignments()->count());
        }
        $this->assertSame('third_party', $externalOrder->fresh()->delivery_method);
    }

    private function shop(): ShopOwner
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'company', 'business_type' => 'retail']);
        ShopOwnerModule::factory()->create(['shop_owner_id' => $shop->id, 'module_key' => 'logistics', 'enabled' => true]);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'operating_days' => range(1, 7)]);

        return $shop;
    }

    private function dispatcher(ShopOwner $shop): User
    {
        $user = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        $this->clockInEmployee($user);
        foreach (['manage-logistics-batches', 'assign-logistics-deliveries'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'user'));
        }

        return $user;
    }

    private function rider(ShopOwner $shop): array
    {
        $user = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        $this->clockInEmployee($user);
        $profile = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'linked_type' => User::class, 'linked_id' => $user->id]);

        return [$user, $profile];
    }

    private function leg(ShopOwner $shop): ShipmentLeg
    {
        $order = Order::factory()->create(['shop_owner_id' => $shop->id, 'delivery_method' => 'shop_owned', 'carrier_company' => 'Shop-owned logistics']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order', 'source_id' => $order->id, 'purpose' => 'retail_delivery']);

        return ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'pending', 'schedule_status' => null, 'scheduled_delivery_date' => null, 'delivery_window' => null]);
    }
}
