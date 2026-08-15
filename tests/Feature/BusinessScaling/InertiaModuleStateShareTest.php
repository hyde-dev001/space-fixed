<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Support\Erp\ErpActorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class InertiaModuleStateShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_owner_dashboard_shares_module_state_for_approval_navigation(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'finance',
            'enabled' => true,
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'procurement',
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/Dashboard', false)
                ->where('shopModuleEnforcementEnabled', true)
                ->where('moduleStates.finance.accessible', true)
                ->where('moduleStates.procurement.accessible', true)
                ->where('auth.shopModules.finance.accessible', true)
                ->where('auth.shopModules.procurement.accessible', true)
                ->where('ownerShell.presentation', 'existing')
                ->where('erpUrls.workspace', route('shop-owner.erp.workspace'))
            );
    }

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
        $this->assertSame('existing', $ownerShare['ownerShell']['presentation']);

        $employee = User::factory()->create(['shop_owner_id' => $owner->id]);
        $employeeShare = $this->shareFor($employee, 'user');
        $this->assertArrayHasKey('shopModules', $employeeShare['auth']);
        $this->assertNull($employeeShare['ownerShell']);

        $customer = User::factory()->create(['shop_owner_id' => null]);
        $customerShare = $this->shareFor($customer, 'user');
        $this->assertArrayNotHasKey('shopModules', $customerShare['auth']);
        $this->assertNull($customerShare['ownerShell']);

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
        $this->assertNull($adminShare['ownerShell']);
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

    public function test_route_selected_erp_actor_and_owner_capabilities_are_shared_without_wildcard_owner_permissions(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);

        $this->actingAs($owner, 'shop_owner');
        $context = new ErpActorContext(
            actor: $owner,
            guard: 'shop_owner',
            tenantOwner: $owner,
            ownerMode: true,
            routeName: 'shop-owner.erp.workspace',
            method: 'GET',
            action: 'view',
            moduleKeys: [],
            gateMode: null,
        );

        $share = $this->shareWithContext($context);

        $this->assertTrue($share['ownerMode']);
        $this->assertTrue($share['shopModuleEnforcementEnabled']);
        $this->assertSame([], $share['auth']['permissions']);
        $this->assertSame('shop_owner', $share['auth']['erpActor']['type']);
        $this->assertSame($owner->id, $share['auth']['erpActor']['id']);
        $this->assertSame($owner->business_name, $share['auth']['erpActor']['name']);
        $this->assertTrue($share['auth']['erpActor']['ownerMode']);
        $this->assertSame($owner->id, $share['auth']['erpActor']['tenantOwnerId']);
        $this->assertSame('existing', $share['ownerShell']['presentation']);
        $this->assertSame(route('shop-owner.dashboard'), $share['erpUrls']['portal']);
        $this->assertSame(route('shop-owner.settings'), $share['erpUrls']['settings']);
        $this->assertSame(route('shop-owner.erp.workspace'), $share['erpUrls']['workspace']);
        $this->assertTrue($share['erpCapabilities']['GET:shop-owner.erp.workspace']['allowed']);
        $this->assertSame(
            route('shop-owner.erp.workspace'),
            $share['erpCapabilities']['GET:shop-owner.erp.workspace']['url'],
        );
    }

    public function test_owner_capabilities_share_parameterized_read_urls_as_client_resolvable_templates(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'crm',
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/crm/customers')
            ->assertInertia(fn ($page) => $page->where(
                'erpCapabilities',
                fn (Collection $capabilities): bool => $capabilities->get('GET:crm.api.customers.show')['url']
                    === route('shop-owner.erp.api.crm.customers.show', ['id' => '__ERP_PARAM_id__']),
            )
            );
    }

    public function test_dual_sessions_share_the_route_selected_employee_actor_and_preserve_employee_permissions(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);
        $employee = User::factory()->create([
            'shop_owner_id' => $owner->id,
        ]);

        $this->actingAs($owner, 'shop_owner');
        $this->actingAs($employee, 'user');
        $context = new ErpActorContext(
            actor: $employee,
            guard: 'user',
            tenantOwner: $owner,
            ownerMode: false,
            routeName: 'erp.hr',
            method: 'GET',
            action: 'view',
            moduleKeys: [],
            gateMode: null,
        );

        $share = $this->shareWithContext($context);

        $this->assertFalse($share['ownerMode']);
        $this->assertSame('employee', $share['auth']['erpActor']['type']);
        $this->assertSame($employee->id, $share['auth']['erpActor']['id']);
        $this->assertFalse($share['auth']['erpActor']['ownerMode']);
        $this->assertSame($owner->id, $share['auth']['erpActor']['tenantOwnerId']);
        $this->assertNull($share['ownerShell']);
        $this->assertNotContains('*', $share['auth']['permissions']);
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

    /**
     * @return array<string, mixed>
     */
    private function shareWithContext(ErpActorContext $context): array
    {
        $request = Request::create('/'.$context->routeName());
        $route = app('router')->getRoutes()->getByName($context->routeName());

        if ($route instanceof Route) {
            $request->setRouteResolver(static fn (): Route => $route);
        }

        $request->attributes->set('erp.actor_context', $context);

        return app(HandleInertiaRequests::class)->share($request);
    }
}
