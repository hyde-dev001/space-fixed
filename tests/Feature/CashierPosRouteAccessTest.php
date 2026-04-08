<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashierPosRouteAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_access_unified_pos_permission_can_open_cashier_pos(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'access-unified-pos', 'guard_name' => 'user']);
        $role = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'user']);
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole('Cashier');

        $response = $this->actingAs($user, 'user')->get('/erp/cashier/point-of-sale');

        $response->assertStatus(200);
    }

    public function test_user_without_permission_is_denied_cashier_pos(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'user')->get('/erp/cashier/point-of-sale');

        $response->assertStatus(403);
    }
}
