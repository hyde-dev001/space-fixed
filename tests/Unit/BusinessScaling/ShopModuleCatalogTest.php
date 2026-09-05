<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessScaling;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ShopModuleCatalogTest extends TestCase
{
    public function test_catalog_has_the_exact_supported_keys_in_order(): void
    {
        $this->assertSame([
            'retail_operations',
            'repair_operations',
            'hr_employees',
            'finance',
            'crm',
            'inventory',
            'procurement',
            'logistics',
        ], array_keys(config('shop_modules.modules')));
    }

    public function test_catalog_entries_have_explicit_eligibility_and_initialization_policy(): void
    {
        $modules = config('shop_modules.modules');

        foreach ($modules as $module) {
            $this->assertIsString($module['label']);
            $this->assertNotEmpty($module['registration_types']);
            $this->assertNotEmpty($module['business_types']);
            $this->assertIsBool($module['default_enabled']);
            $this->assertIsBool($module['backfill_enabled']);
            $this->assertSame(['shop_owner', 'user'], $module['actor_scope']);
            $this->assertArrayNotHasKey('dependencies', $module);
            $this->assertArrayNotHasKey('depends_on', $module);
        }

        $this->assertSame(['single', 'all_of', 'any_of'], config('shop_modules.supported_gate_modes'));
        $this->assertFalse(config('shop_modules.enforcement_enabled'));
    }

    public function test_route_entries_use_the_approved_capability_schema(): void
    {
        $routes = config('shop_modules.routes');
        $moduleKeys = array_keys(config('shop_modules.modules'));
        $expectedKeys = [
            'methods',
            'classification',
            'audience',
            'actor_guard',
            'module_keys',
            'mode',
            'registration_types',
            'business_types',
            'action',
            'owner_access',
            'owner_denial_reason',
            'domain_rule',
            'risk_tier',
            'paired_route',
            'navigation_group',
            'navigation_label',
            'navigation_order',
            'navigation_page_group',
            'navigation_page_group_label',
            'navigation_page_group_order',
            'navigation_visible',
            'owner_navigation_visible',
            'self_service',
            'supporting_routes',
            'actor_persistence',
        ];

        $this->assertNotEmpty($routes);

        foreach ($routes as $routeName => $route) {
            $this->assertNotSame('', $routeName);
            $this->assertSame($expectedKeys, array_keys($route), $routeName);
            $this->assertNotEmpty($route['methods'], $routeName);
            $this->assertContains($route['audience'], ['user', 'shop_owner', 'public', 'super_admin', 'system'], $routeName);
            $this->assertContains($route['actor_guard'], ['user', 'shop_owner', null], $routeName);
            $this->assertContains($route['classification'], ['core', 'excluded', 'module']);
            $this->assertTrue($route['mode'] === null || in_array($route['mode'], config('shop_modules.supported_gate_modes'), true), $routeName);
            $this->assertSame([], array_diff($route['module_keys'], $moduleKeys), $routeName);
            $this->assertContains($route['action'], ['view', 'create', 'update', 'approve', 'reject', 'checkout', 'assign', 'upload', 'delete', 'system']);
            $this->assertContains($route['owner_access'], ['allowed', 'denied']);
            $this->assertContains($route['risk_tier'], ['normal', 'sensitive', 'financial']);
            $this->assertIsBool($route['self_service'], $routeName);
            $this->assertIsArray($route['supporting_routes'], $routeName);
            $this->assertContains($route['actor_persistence'], ['not_applicable', 'existing_owner_ref', 'paired_owner_ref', 'polymorphic_actor', 'server_resolved_shop_owner']);

            if ($route['classification'] === 'module') {
                $this->assertNotEmpty($route['module_keys']);
            } else {
                $this->assertSame([], $route['module_keys']);
            }

            if ($route['owner_access'] === 'denied') {
                $this->assertNotSame('', $route['owner_denial_reason'], $routeName);
                $this->assertSame('not_applicable', $route['actor_persistence'], $routeName);
            }

            if ($route['self_service']) {
                $this->assertSame('user', $route['audience'], $routeName);
                $this->assertNull($route['paired_route'], $routeName);
            }
        }
    }

    public function test_phase_five_owner_denied_creation_surfaces_are_cataloged_fail_closed(): void
    {
        $routes = config('shop_modules.routes');
        $deniedPages = [
            'shop-owner.erp.finance.create-invoice' => 'owner_invoice_creation_not_allowed',
            'shop-owner.erp.hr.payroll-generate' => 'owner_payroll_generation_not_allowed',
            'shop-owner.erp.inventory.upload-stocks' => 'owner_inventory_upload_not_allowed',
        ];
        $deniedApis = [
            'shop_owner.finance.invoices.store' => 'owner_invoice_creation_not_allowed',
            'shop_owner.finance.invoices.from_job' => 'owner_invoice_creation_not_allowed',
            'shop_owner.hr.payroll.store' => 'owner_payroll_generation_not_allowed',
            'shop_owner.hr.payroll.calculate_preview' => 'owner_payroll_generation_not_allowed',
            'shop_owner.hr.payroll.batch.preview' => 'owner_payroll_generation_not_allowed',
            'shop_owner.hr.payroll.batch.generate' => 'owner_payroll_generation_not_allowed',
            'shop_owner.hr.payroll.batch.retry' => 'owner_payroll_generation_not_allowed',
            'shop_owner.hr.payroll.batch.export' => 'owner_payroll_generation_not_allowed',
        ];

        foreach ($deniedPages as $routeName => $reason) {
            $this->assertArrayHasKey($routeName, $routes);
            $this->assertSame('denied', $routes[$routeName]['owner_access'], $routeName);
            $this->assertSame($reason, $routes[$routeName]['owner_denial_reason'], $routeName);
            $this->assertFalse($routes[$routeName]['navigation_visible'], $routeName);
            $this->assertSame('not_applicable', $routes[$routeName]['actor_persistence'], $routeName);
        }

        foreach ($deniedApis as $routeName => $reason) {
            $this->assertArrayHasKey($routeName, $routes);
            $this->assertSame('denied', $routes[$routeName]['owner_access'], $routeName);
            $this->assertSame($reason, $routes[$routeName]['owner_denial_reason'], $routeName);
            $this->assertSame('not_applicable', $routes[$routeName]['actor_persistence'], $routeName);
        }
    }

    public function test_named_internal_routes_are_present_in_the_authoritative_map(): void
    {
        $prefixes = [
            'shop_owner.',
            'shop-owner.',
            'erp.',
            'hr.',
            'inventory.',
            'finance.',
            'procurement.',
            'crm.',
            'staff.',
            'permission-audit-logs.',
            'api.manager.',
            'api.leave.',
            'api.logistics.',
        ];

        $routes = config('shop_modules.routes');

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if ($name === '' || ! collect($prefixes)->contains(fn (string $prefix) => str_starts_with($name, $prefix))) {
                continue;
            }

            $this->assertArrayHasKey($name, $routes, "Missing route catalog entry for {$name}");
        }
    }

    public function test_shop_owner_auth_entry_points_remain_public(): void
    {
        $routes = config('shop_modules.routes');

        foreach ([
            'shop-owner.email-verification.send-code',
            'shop-owner.email-verification.verify-code',
            'shop-owner.login',
            'shop-owner.login.form',
            'shop-owner.password.setup',
            'shop-owner.password.setup.store',
            'shop-owner.pending-approval.public',
            'shop-owner.register',
            'shop-owner.resubmission.form',
            'shop-owner.resubmission.submit',
            'shop-owner.two-factor.challenge',
            'shop-owner.two-factor.resend',
            'shop-owner.two-factor.verify',
        ] as $routeName) {
            $this->assertSame('public', $routes[$routeName]['audience'], $routeName);
            $this->assertNull($routes[$routeName]['actor_guard'], $routeName);
        }
    }
}
