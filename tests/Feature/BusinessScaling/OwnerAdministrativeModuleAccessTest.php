<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class OwnerAdministrativeModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_administration_rows_are_owner_scoped_and_module_gated(): void
    {
        $checked = 0;
        foreach (config('shop_modules.routes', []) as $routeName => $entry) {
            if (($entry['classification'] ?? null) !== 'module'
                || (! str_starts_with((string) $routeName, 'shop_owner.')
                    && ! str_starts_with((string) $routeName, 'shop-owner.')
                    && ! str_starts_with((string) $routeName, 'shopOwner.'))) {
                continue;
            }

            $this->assertSame(['shop_owner'], $entry['actor_guards'], $routeName);
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, $routeName);
            $middleware = $route->gatherMiddleware();
            $this->assertTrue(
                in_array('shop.module', $route->middleware(), true)
                    || in_array('App\\Http\\Middleware\\EnsureShopModuleEnabled', $middleware, true),
                $routeName,
            );
            $this->assertTrue(
                collect($middleware)->contains(fn (string $value): bool => str_contains($value, 'Authenticate:shop_owner') || $value === 'auth:shop_owner'),
                $routeName,
            );
            $checked++;
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function test_employee_self_service_rows_remain_user_scoped(): void
    {
        foreach (config('shop_modules.routes', []) as $routeName => $entry) {
            if (! str_starts_with((string) $routeName, 'staff.') || ($entry['classification'] ?? null) !== 'module') {
                continue;
            }

            $this->assertSame(['user'], $entry['actor_guards'], $routeName);
        }

        $routes = config('shop_modules.routes', []);
        $this->assertNotEmpty($routes['staff.attendance.checkin']['actor_guards'] ?? null);
    }

    public function test_business_owner_can_open_enabled_employee_module_pages_without_an_employee_session(): void
    {
        config(['shop_modules.enforcement_enabled' => true]);

        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        foreach (['retail_operations', 'repair_operations', 'hr_employees', 'finance', 'inventory', 'procurement', 'crm', 'logistics'] as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }

        $this->actingAs($owner, 'shop_owner');

        foreach ([
            'shopOwner.user-access-control',
            'shop-owner.suspend-accounts',
            'shopOwner.suspend-accounts',
            'shop-owner.expense-approvals',
            'shop-owner.inventory-overview',
            'shop-owner.purchase-request-approval',
            'shop-owner.customers',
            'shop-owner.logistics.shipments',
            'shop-owner.job-orders-retail',
            'shop-owner.product-uploder',
            'shop-owner.job-orders-repair',
            'shop-owner.upload-services',
        ] as $routeName) {
            $this->get(route($routeName))->assertOk();
        }

        // The owner session is not an employee session and cannot open
        // identity-sensitive ERP self-service pages.
        $this->get(route('erp.time-in'))->assertRedirect();
    }

    public function test_disabled_owner_module_is_denied_even_when_the_owner_is_a_company(): void
    {
        config(['shop_modules.enforcement_enabled' => true]);

        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);
        foreach (['finance', 'hr_employees'] as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => $moduleKey,
                'enabled' => false,
            ]);
        }

        $this->actingAs($owner, 'shop_owner');

        foreach (['shop-owner.expense-approvals', 'shop-owner.suspend-accounts'] as $routeName) {
            $this->get(route($routeName))->assertRedirect(route('shop-owner.settings'));
        }
    }
}
