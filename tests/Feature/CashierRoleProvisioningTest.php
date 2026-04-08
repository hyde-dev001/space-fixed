<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashierRoleProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_role_and_unified_pos_permission_are_seeded(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $permission = Permission::where('guard_name', 'user')
            ->where('name', 'access-unified-pos')
            ->first();

        $role = Role::where('guard_name', 'user')
            ->where('name', 'Cashier')
            ->first();

        $this->assertNotNull($permission);
        $this->assertNotNull($role);
        $this->assertTrue($role->hasPermissionTo('access-unified-pos'));
    }
}
