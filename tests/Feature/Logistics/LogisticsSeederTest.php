<?php

namespace Tests\Feature\Logistics;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LogisticsSeederTest extends TestCase
{
    use RefreshDatabase;

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
