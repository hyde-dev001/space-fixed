<?php

namespace Tests\Feature;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashierPosRouteAccessTest extends TestCase
{
    use RefreshDatabase;

    private function createCashierUser(): User
    {
        $permission = Permission::firstOrCreate(['name' => 'access-unified-pos', 'guard_name' => 'user']);
        $role = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'user']);
        $role->givePermissionTo($permission);

        /** @var ShopOwner $shopOwner */
        $shopOwner = ShopOwner::factory()->createOne([
            'status' => 'approved',
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);

        /** @var User $user */
        $user = User::factory()->createOne([
            'shop_owner_id' => $shopOwner->id,
            // Legacy enum role stays STAFF for cashier accounts.
            'role' => 'STAFF',
        ]);
        $user->assignRole('Cashier');

        return $user;
    }

    public function test_user_with_access_unified_pos_permission_can_open_cashier_pos(): void
    {
        $user = $this->createCashierUser();

        $response = $this->actingAs($user, 'user')->get('/erp/cashier/point-of-sale');

        $response->assertStatus(200);
    }

    public function test_cashier_can_open_time_in_page_without_authorization_error(): void
    {
        $user = $this->createCashierUser();

        $response = $this->actingAs($user, 'user')->get('/erp/time-in');

        $response->assertStatus(200);
    }

    public function test_cashier_can_open_my_payslips_page_without_authorization_error(): void
    {
        $user = $this->createCashierUser();

        $response = $this->actingAs($user, 'user')->get('/erp/my-payslips');

        $response->assertStatus(200);
    }

    public function test_cashier_is_denied_staff_inventory_overview_page(): void
    {
        $user = $this->createCashierUser();

        $response = $this->actingAs($user, 'user')->get('/erp/staff/inventory-overview');

        $response->assertStatus(403);
    }

    public function test_cashier_is_denied_staff_inventory_overview_api(): void
    {
        $user = $this->createCashierUser();

        $response = $this->actingAs($user, 'user')->getJson('/api/staff/inventory-overview');

        $response->assertStatus(403);
    }

    public function test_user_without_permission_is_denied_cashier_pos(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $response = $this->actingAs($user, 'user')->get('/erp/cashier/point-of-sale');

        $response->assertStatus(403);
    }
}
