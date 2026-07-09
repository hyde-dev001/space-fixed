<?php

namespace Tests\Feature\Logistics;

use App\Models\User;
use App\Models\ShopOwner;
use App\Services\BusinessAccessControlService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LogisticsEmployeeRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_shop_can_create_logistics_employee_roles(): void
    {
        $shop = ShopOwner::factory()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $roles = app(BusinessAccessControlService::class)->getAllowedRoles($shop);

        $this->assertContains('Logistics Dispatcher', $roles);
        $this->assertContains('Logistics Rider', $roles);
    }

    public function test_individual_shop_still_cannot_create_employee_roles(): void
    {
        $shop = ShopOwner::factory()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);

        $roles = app(BusinessAccessControlService::class)->getAllowedRoles($shop);

        $this->assertSame([], $roles);
    }

    public function test_company_shop_owner_can_create_logistics_rider_employee_account(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = ShopOwner::factory()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $response = $this->actingAs($shop, 'shop_owner')
            ->post(route('shop-owner.employees.store'), [
                'name' => 'Logistics Rider One',
                'email' => 'logistics-rider@example.com',
                'phone' => '09171234567',
                'role' => 'Logistics Rider',
            ]);

        $response->assertSessionHasNoErrors();

        $user = User::where('email', 'logistics-rider@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole('Logistics Rider'));
        $this->assertSame('STAFF', $user->role);
    }

    public function test_hr_employee_can_create_logistics_dispatcher_employee_account(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = ShopOwner::factory()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $hrUser = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'HR',
        ]);
        $hrUser->givePermissionTo(Permission::findOrCreate('access-employee-directory', 'user'));

        $response = $this->actingAs($hrUser, 'user')
            ->postJson('/api/hr/employees', [
                'firstName' => 'Logistics',
                'lastName' => 'Dispatcher',
                'email' => 'logistics-dispatcher@example.com',
                'phone' => '09171234568',
                'position' => 'Dispatcher',
                'department' => 'Logistics Dispatcher',
                'role' => 'Logistics Dispatcher',
                'hireDate' => now()->toDateString(),
            ]);

        $response->assertCreated();

        $user = User::where('email', 'logistics-dispatcher@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole('Logistics Dispatcher'));
        $this->assertSame('STAFF', $user->role);
    }
}
