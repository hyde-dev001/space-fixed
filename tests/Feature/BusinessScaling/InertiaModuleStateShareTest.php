<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InertiaModuleStateShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_employee_receive_authoritative_module_state_but_customers_and_super_admins_do_not(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'repair_operations',
            'enabled' => false,
        ]);

        $ownerShare = $this->shareFor($owner, 'shop_owner');
        $this->assertArrayHasKey('shopModules', $ownerShare['auth']);
        $this->assertSame(
            array_keys(config('shop_modules.modules', [])),
            array_keys($ownerShare['auth']['shopModules']()),
        );
        $this->assertFalse($ownerShare['auth']['shopModules']()['repair_operations']['accessible']);

        $employee = User::factory()->create(['shop_owner_id' => $owner->id]);
        $employeeShare = $this->shareFor($employee, 'user');
        $this->assertArrayHasKey('shopModules', $employeeShare['auth']);

        $customer = User::factory()->create(['shop_owner_id' => null]);
        $customerShare = $this->shareFor($customer, 'user');
        $this->assertArrayNotHasKey('shopModules', $customerShare['auth']);

        $admin = SuperAdmin::create([
            'first_name' => 'Share',
            'last_name' => 'Admin',
            'email' => 'share-admin@example.test',
            'phone' => '09170000004',
            'password' => 'password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $adminShare = $this->shareFor($admin, 'super_admin');
        $this->assertArrayNotHasKey('shopModules', $adminShare['auth']);
    }

    public function test_module_rows_are_loaded_once_when_the_lazy_state_is_evaluated(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);

        $share = $this->shareFor($owner, 'shop_owner');
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'shop_owner_modules')) {
                $queries++;
            }
        });

        ($share['auth']['shopModules'])();
        $this->assertSame(1, $queries);
    }

    /**
     * @return array<string, mixed>
     */
    private function shareFor(object $actor, string $guard): array
    {
        Auth::guard('shop_owner')->logout();
        Auth::guard('user')->logout();
        Auth::guard('super_admin')->logout();
        $this->actingAs($actor, $guard);
        $shared = app(HandleInertiaRequests::class)->share(Request::create('/'));
        $this->assertIsArray($shared['auth']);

        return $shared;
    }
}
