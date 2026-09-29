<?php

namespace Tests\Feature\ShopOwner;

use App\Models\ShopOwner;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserAccessControlEmployeeRoleCreationTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shopOwner;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->shopOwner = ShopOwner::factory()->createOne([
            'registration_type' => 'company',
            'business_type' => 'repair',
            'status' => 'approved',
        ]);
    }

    public function test_shop_owner_can_create_inventory_employee_from_user_access_control(): void
    {
        Role::findOrCreate('Inventory Manager', 'user');

        $response = $this
            ->actingAs($this->shopOwner, 'shop_owner')
            ->from('/shopOwner/user-access-control')
            ->post('/shop-owner/employees', [
                'name' => 'Inventory User',
                'email' => 'inventory.user@example.com',
                'phone' => '09171112223',
                'position' => 'Inventory Controller',
                'department' => 'Inventory',
                'role' => 'Inventory',
                'hire_date' => now()->toDateString(),
                'status' => 'active',
            ]);

        $response->assertRedirect('/shopOwner/user-access-control');
        $response->assertSessionHasNoErrors();

        $createdUser = User::where('email', 'inventory.user@example.com')->firstOrFail();
        $this->assertSame('INVENTORY', $createdUser->role);
        $this->assertTrue($createdUser->hasRole('Inventory Manager'));
    }

    public function test_shop_owner_persists_employee_suffix_and_structured_address(): void
    {
        Role::findOrCreate('Inventory Manager', 'user');

        $response = $this
            ->actingAs($this->shopOwner, 'shop_owner')
            ->from('/shopOwner/user-access-control')
            ->post('/shop-owner/employees', [
                'name' => 'Inventory User Jr.',
                'suffix' => 'Jr.',
                'email' => 'inventory.address@example.com',
                'phone' => '09171112225',
                'address' => '123 Main Street',
                'province' => 'Abra',
                'city_municipality' => 'Bangued',
                'postal_code' => '2800',
                'position' => 'Inventory Controller',
                'department' => 'Inventory',
                'role' => 'Inventory',
                'hire_date' => now()->toDateString(),
                'status' => 'active',
            ]);

        $response->assertRedirect('/shopOwner/user-access-control');
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('employees', [
            'email' => 'inventory.address@example.com',
            'suffix' => 'Jr.',
            'address' => '123 Main Street',
            'state' => 'Abra',
            'city' => 'Bangued',
            'zip_code' => '2800',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'inventory.address@example.com',
            'suffix' => 'Jr.',
            'address' => '123 Main Street',
            'province' => 'Abra',
            'city' => 'Bangued',
            'postal_code' => '2800',
        ]);

        $employee = Employee::where('email', 'inventory.address@example.com')->firstOrFail();
        $this->assertSame('Abra', $employee->state);
        $this->assertSame('Bangued', $employee->city);
        $this->assertSame('2800', $employee->zip_code);
    }

    public function test_shop_owner_can_create_procurement_employee_with_legacy_role_alias(): void
    {
        // Simulate legacy environment where only "Procurement" role exists in Spatie records.
        Role::findOrCreate('Procurement', 'user');

        $response = $this
            ->actingAs($this->shopOwner, 'shop_owner')
            ->from('/shopOwner/user-access-control')
            ->post('/shop-owner/employees', [
                'name' => 'Procurement User',
                'email' => 'procurement.user@example.com',
                'phone' => '09171112224',
                'position' => 'Procurement Officer',
                'department' => 'Procurement',
                'role' => 'Procurement',
                'hire_date' => now()->toDateString(),
                'status' => 'active',
            ]);

        $response->assertRedirect('/shopOwner/user-access-control');
        $response->assertSessionHasNoErrors();

        $createdUser = User::where('email', 'procurement.user@example.com')->firstOrFail();
        // Legacy role remains enum-compatible while permissions come from Spatie role.
        $this->assertSame('STAFF', $createdUser->role);
        $this->assertTrue($createdUser->hasRole('Procurement'));
    }
}
