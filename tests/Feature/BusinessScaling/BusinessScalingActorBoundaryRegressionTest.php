<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\ShopOwnerUpgradeRequest;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class BusinessScalingActorBoundaryRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop_modules.enforcement_enabled' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_customer_storefront_and_super_admin_support_are_not_module_gated(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);

        $this->get(route('shop-profile', $owner))
            ->assertOk();

        $admin = SuperAdmin::create([
            'first_name' => 'Support',
            'last_name' => 'Admin',
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09170000001',
            'password' => 'password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'super_admin')
            ->getJson(route('admin.business-upgrade-requests.index'))
            ->assertOk();

        $this->assertSame('core', $this->routeEntry('shop-owner.settings')['classification']);
        $this->assertArrayNotHasKey('admin.business-upgrade-requests.index', config('shop_modules.routes'));
    }

    public function test_route_contract_preserves_actor_scope_and_operation_ownership(): void
    {
        $expected = [
            'shop_owner.products.index' => ['retail_operations', 'shop_owner'],
            'shop_owner.repair-services.index' => ['repair_operations', 'shop_owner'],
            'hr.employees.index' => ['hr_employees', 'user'],
            'hr.payroll.index' => ['hr_employees', 'user'],
            'hr.documents.index' => ['hr_employees', 'user'],
            'inventory.products.index' => ['inventory', 'user'],
            'inventory.items.update' => ['inventory', 'user'],
            'procurement.purchase-orders.store' => ['procurement', 'user'],
            'erp.logistics.batches' => ['logistics', 'user'],
        ];

        foreach ($expected as $routeName => [$moduleKey, $actorGuard]) {
            $entry = $this->routeEntry($routeName);

            $this->assertIsArray($entry, $routeName);
            $this->assertSame('module', $entry['classification'], $routeName);
            $this->assertSame([$moduleKey], $entry['module_keys'], $routeName);
            $this->assertSame($actorGuard, $entry['actor_guard'], $routeName);
        }

        $this->assertSame('core', $this->routeEntry('shop-owner.settings')['classification']);
        $this->assertSame('user', $this->routeEntry('staff.attendance.checkin')['actor_guard']);
        $this->assertSame(['inventory'], $this->routeEntry('inventory.products.index')['module_keys']);
        $this->assertNotContains('retail_operations', $this->routeEntry('inventory.products.index')['module_keys']);
        $this->assertNotContains('repair_operations', $this->routeEntry('inventory.products.index')['module_keys']);
    }

    public function test_conflicting_cross_shop_sessions_and_owner_access_to_employee_self_service_are_denied(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $otherOwner->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);

        $employee = User::factory()->create(['shop_owner_id' => $otherOwner->id]);
        $this->defineRoute('cross-shop-context', [
            'classification' => 'module',
            'mode' => 'single',
            'module_keys' => ['retail_operations'],
            'actor_guards' => ['shop_owner', 'user'],
            'customer_capable' => false,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->actingAs($employee, 'user')
            ->getJson('/testing/business-scaling/regression/cross-shop-context')
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_INELIGIBLE');

        Auth::guard('shop_owner')->logout();
        Auth::guard('user')->logout();

        $this->defineRoute('employee-self-service', [
            'classification' => 'module',
            'mode' => 'single',
            'module_keys' => ['hr_employees'],
            'actor_guards' => ['user'],
            'customer_capable' => false,
        ], 'user');

        $this->actingAs($owner, 'shop_owner')
            ->get('/testing/business-scaling/regression/employee-self-service')
            ->assertRedirect();
    }

    public function test_pending_rejected_and_superseded_requests_do_not_entitle_new_modules(): void
    {
        $this->defineRoute('upgrade-state-gate', [
            'classification' => 'module',
            'mode' => 'single',
            'module_keys' => ['hr_employees'],
            'actor_guards' => ['shop_owner'],
            'customer_capable' => false,
        ]);

        foreach ([
            ShopOwnerUpgradeRequest::STATUS_PENDING,
            ShopOwnerUpgradeRequest::STATUS_REJECTED,
            ShopOwnerUpgradeRequest::STATUS_SUPERSEDED,
        ] as $status) {
            $owner = ShopOwner::factory()->approved()->create([
                'registration_type' => 'individual',
                'business_type' => 'retail',
            ]);
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => 'hr_employees',
                'enabled' => true,
            ]);
            ShopOwnerUpgradeRequest::factory()->create([
                'shop_owner_id' => $owner->id,
                'status' => $status,
            ]);

            $this->actingAs($owner, 'shop_owner')
                ->getJson('/testing/business-scaling/regression/upgrade-state-gate')
                ->assertForbidden()
                ->assertJsonPath('code', 'MODULE_INELIGIBLE');

            Auth::guard('shop_owner')->logout();
        }
    }

    public function test_dormant_employee_permissions_and_shop_data_survive_module_disable_and_approval(): void
    {
        $permission = Permission::findOrCreate('access-employee-directory', 'user');
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        $employee = User::factory()->create(['shop_owner_id' => $owner->id]);
        $employee->givePermissionTo($permission);
        $module = ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'hr_employees',
            'enabled' => false,
        ]);
        $upgradeRequest = ShopOwnerUpgradeRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'status' => ShopOwnerUpgradeRequest::STATUS_PENDING,
        ]);

        $this->defineRoute('dormant-employee', [
            'classification' => 'module',
            'mode' => 'single',
            'module_keys' => ['hr_employees'],
            'actor_guards' => ['user'],
            'customer_capable' => false,
        ], 'user');

        $permissionIdsBefore = $employee->permissions()->pluck('permissions.id')->all();
        $this->actingAs($employee, 'user')
            ->getJson('/testing/business-scaling/regression/dormant-employee')
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_INELIGIBLE');

        $owner->update([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $module->update(['enabled' => true]);

        Auth::guard('user')->logout();
        $this->actingAs($employee->fresh(), 'user')
            ->getJson('/testing/business-scaling/regression/dormant-employee')
            ->assertOk();

        $this->assertSame($permissionIdsBefore, $employee->fresh()->permissions()->pluck('permissions.id')->all());
        $this->assertDatabaseHas('shop_owner_upgrade_requests', ['id' => $upgradeRequest->id]);
        $this->assertDatabaseHas('shop_owner_modules', [
            'id' => $module->id,
            'shop_owner_id' => $owner->id,
            'module_key' => 'hr_employees',
            'enabled' => 1,
        ]);
    }

    public function test_system_processing_routes_and_core_settings_are_excluded_from_module_enforcement(): void
    {
        $coreNames = [
            'shop-owner.settings',
            'shop-owner.settings.update',
            'shop-owner.upgrade-requests.store',
            'shop_owner.premium.downgrade.schedule',
        ];

        foreach ($coreNames as $routeName) {
            $this->assertSame('core', $this->routeEntry($routeName)['classification'], $routeName);
        }

        foreach (Route::getRoutes() as $route) {
            $routeName = (string) $route->getName();
            $uri = (string) $route->uri();
            $isSystemProcessingRoute = $routeName === ''
                && preg_match('/(?:webhook|schedule|queue)/i', $uri) === 1;

            if (! $isSystemProcessingRoute) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $this->assertFalse(
                in_array('shop.module', $middleware, true)
                    || in_array('App\\Http\\Middleware\\EnsureShopModuleEnabled', $middleware, true),
                $uri,
            );
        }
    }

    private function defineRoute(string $name, array $entry, string $authGuard = 'shop_owner'): void
    {
        $routeName = 'testing.business-scaling.regression.'.$name;
        config(["shop_modules.routes.{$routeName}" => $entry]);

        Route::middleware(['auth:'.$authGuard, 'shop.module'])
            ->get('/testing/business-scaling/regression/'.$name, fn () => response()->json(['ok' => true]))
            ->name($routeName);
    }

    /**
     * Route names contain dots, so they must be read from the catalog directly
     * instead of through Laravel's dotted config lookup syntax.
     *
     * @return array<string, mixed>
     */
    private function routeEntry(string $routeName): array
    {
        $entry = config('shop_modules.routes')[$routeName] ?? null;

        $this->assertIsArray($entry, $routeName);

        return $entry;
    }
}
