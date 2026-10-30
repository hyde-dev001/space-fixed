<?php

namespace Tests\Feature\Logistics;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LogisticsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_logistics_accounts_use_staff_legacy_role_and_distinct_permissions(): void
    {
        $shop = \App\Models\ShopOwner::factory()->approved()->withLogistics()->create(['business_type' => 'both']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\EmployeeSeeder::class);
        foreach (['Logistics Dispatcher', 'Logistics Rider'] as $roleName) {
            $user = \App\Models\User::where('shop_owner_id', $shop->id)->whereHas('roles', fn ($q) => $q->where('name', $roleName))->firstOrFail();
            $this->assertSame('STAFF', $user->role);
            $this->assertSame($roleName === 'Logistics Dispatcher', $user->can('resolve-logistics-exceptions'));
            $this->assertSame($roleName === 'Logistics Rider', $user->can('operate-logistics-deliveries'));
        }
    }

    public function test_logistics_permissions_and_default_methods_are_seeded(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertDatabaseHas('permissions', ['name' => 'access-logistics-dashboard']);
        $this->assertDatabaseHas('permissions', ['name' => 'assign-logistics-deliveries']);
        $this->assertDatabaseHas('shipping_methods', ['code' => 'shop_owned_delivery']);
        $this->assertDatabaseHas('shipping_methods', ['code' => 'third_party_courier']);
        $this->assertDatabaseHas('shipping_methods', ['code' => 'customer_pickup']);
        $dispatcher = Role::findByName('Logistics Dispatcher', 'user');
        $this->assertTrue($dispatcher->permissions()->where('name', 'configure-logistics-settings')->exists());
    }
}
