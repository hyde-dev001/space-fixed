<?php

namespace Tests\Feature\HR;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EmployeeCashierCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-employee-directory', 'user');
        Role::findOrCreate('Cashier', 'user');
    }

    private function createHrUserForShop(ShopOwner $shopOwner): User
    {
        /** @var User $hrUser */
        $hrUser = User::factory()->createOne([
            'role' => 'HR',
            'shop_owner_id' => $shopOwner->id,
        ]);
        $hrUser->givePermissionTo('access-employee-directory');

        return $hrUser;
    }

    public function test_hr_can_create_cashier_via_employee_directory_endpoint(): void
    {
        $shopOwner = ShopOwner::factory()->createOne([
            'status' => 'approved',
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);

        $hrUser = $this->createHrUserForShop($shopOwner);

        $payload = [
            'firstName' => 'Casey',
            'lastName' => 'Cashier',
            'email' => 'casey.cashier@example.com',
            'phone' => '09171234567',
            'position' => 'Cashier',
            'department' => 'Cashier',
            'role' => 'Cashier',
            'salary' => 500,
            'hireDate' => now()->toDateString(),
            'location' => 'Main Branch',
        ];

        $response = $this->actingAs($hrUser, 'user')->postJson('/api/hr/employees', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'casey.cashier@example.com',
            'role' => 'Cashier',
            'shop_owner_id' => $shopOwner->id,
        ]);

        $createdUser = User::where('email', 'casey.cashier@example.com')->firstOrFail();
        $this->assertTrue($createdUser->hasRole('Cashier'));
    }

    public function test_hr_can_create_inventory_role_on_repair_only_business_type(): void
    {
        Role::findOrCreate('Inventory Manager', 'user');

        $shopOwner = ShopOwner::factory()->createOne([
            'status' => 'approved',
            'registration_type' => 'company',
            'business_type' => 'repair',
        ]);
        $hrUser = $this->createHrUserForShop($shopOwner);

        $payload = [
            'firstName' => 'Ivy',
            'lastName' => 'Inventory',
            'email' => 'ivy.inventory@example.com',
            'phone' => '09179990001',
            'position' => 'Inventory Controller',
            'department' => 'Inventory',
            'role' => 'Inventory',
            'salary' => 600,
            'hireDate' => now()->toDateString(),
            'location' => 'Repair Hub',
        ];

        $response = $this->actingAs($hrUser, 'user')->postJson('/api/hr/employees', $payload);

        $response->assertStatus(201);
        $createdUser = User::where('email', 'ivy.inventory@example.com')->firstOrFail();
        $this->assertTrue($createdUser->hasRole('Inventory Manager'));
    }

    public function test_hr_creation_uses_procurement_alias_role_without_server_error(): void
    {
        // Simulate legacy production setup where Procurement role exists but Procurement Manager does not.
        Role::findOrCreate('Procurement', 'user');

        $shopOwner = ShopOwner::factory()->createOne([
            'status' => 'approved',
            'registration_type' => 'company',
            'business_type' => 'repair',
        ]);
        $hrUser = $this->createHrUserForShop($shopOwner);

        $payload = [
            'firstName' => 'Paolo',
            'lastName' => 'Procurement',
            'email' => 'paolo.procurement@example.com',
            'phone' => '09179990002',
            'position' => 'Procurement Officer',
            'department' => 'Procurement',
            'role' => 'Procurement',
            'salary' => 650,
            'hireDate' => now()->toDateString(),
            'location' => 'Repair Hub',
        ];

        $response = $this->actingAs($hrUser, 'user')->postJson('/api/hr/employees', $payload);

        $response->assertStatus(201);
        $createdUser = User::where('email', 'paolo.procurement@example.com')->firstOrFail();
        $this->assertTrue($createdUser->hasRole('Procurement'));
    }
}
