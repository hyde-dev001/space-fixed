<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ErpAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-employee-directory', 'user');
    }

    public function test_super_admin_cannot_access_hr_module_routes(): void
    {
        // Create a SUPER_ADMIN user
        $superAdmin = User::factory()->createOne([
            'role' => 'SUPER_ADMIN',
        ]);

        $response = $this->actingAs($superAdmin, 'user')
            ->getJson('/api/hr/employees');

        $response->assertStatus(403);
    }

    public function test_hr_handler_can_access_hr_module_routes(): void
    {
        // Create an approved shop owner
        $shopOwner = ShopOwner::factory()->createOne([
            'status' => 'approved',
        ]);

        // Create an HR user assigned to the shop
        $hrUser = User::factory()->createOne([
            'role' => 'HR',
            'shop_owner_id' => $shopOwner->id,
        ]);
        $hrUser->givePermissionTo('access-employee-directory');

        $response = $this->actingAs($hrUser, 'user')
            ->getJson('/api/hr/employees');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }
}
