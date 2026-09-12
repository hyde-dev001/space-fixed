<?php

namespace Tests\Feature\Logistics;

use App\Models\ShopOwner;
use App\Models\User;
use App\Services\BusinessAccessControlService;
use Database\Seeders\EmployeeSeeder;
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
        $this->assertDatabaseHas('rider_profiles', [
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $user->id,
            'rider_type' => 'employee',
            'name' => 'Logistics Rider One',
            'availability_status' => 'available',
            'active' => true,
        ]);
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

    public function test_employee_seeder_creates_both_logistics_roles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = ShopOwner::factory()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $this->seed(EmployeeSeeder::class);

        foreach (['Logistics Dispatcher', 'Logistics Rider'] as $role) {
            $email = str($role)->lower()->replace(' ', '.').".{$shop->id}@solespace.com";

            $this->assertDatabaseHas('employees', [
                'shop_owner_id' => $shop->id,
                'email' => $email,
                'department' => $role,
            ]);

            $user = User::where('email', $email)->firstOrFail();

            $this->assertTrue($user->hasRole($role));
            $this->assertContains($user->role, [
                'STAFF',
                strtoupper(str_replace(' ', '_', $role)),
            ]);
        }

        $secondRiderEmail = "logistics.rider2.{$shop->id}@solespace.com";
        $this->assertDatabaseHas('employees', [
            'shop_owner_id' => $shop->id,
            'email' => $secondRiderEmail,
            'department' => 'Logistics Rider',
        ]);
        $this->assertTrue(User::where('email', $secondRiderEmail)->firstOrFail()->hasRole('Logistics Rider'));
    }

    public function test_employee_seeder_assigns_unique_user_phone_numbers_across_shops(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        ShopOwner::factory()->count(2)->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $this->seed(EmployeeSeeder::class);

        $phones = User::query()->whereNotNull('phone')->pluck('phone');

        $this->assertSame($phones->count(), $phones->unique()->count());
    }

    public function test_dispatcher_riders_page_backfills_existing_logistics_rider_profiles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = ShopOwner::factory()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole('Logistics Dispatcher');

        $rider = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'name' => 'Existing Rider',
            'phone' => '09171234569',
        ]);
        $rider->assignRole('Logistics Rider');

        $this->assertDatabaseMissing('rider_profiles', [
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);

        $this->actingAs($dispatcher, 'user')
            ->get('/erp/logistics/riders')
            ->assertOk();

        $this->assertDatabaseHas('rider_profiles', [
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
            'rider_type' => 'employee',
            'name' => 'Existing Rider',
            'phone' => '09171234569',
            'availability_status' => 'available',
            'active' => true,
        ]);
    }
}
