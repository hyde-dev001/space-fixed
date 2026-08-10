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

    public function test_route_entries_use_the_explicit_gate_contract(): void
    {
        $routes = config('shop_modules.routes');
        $moduleKeys = array_keys(config('shop_modules.modules'));

        $this->assertNotEmpty($routes);

        foreach ($routes as $routeName => $route) {
            $this->assertNotSame('', $routeName);
            $this->assertContains($route['classification'], ['core', 'excluded', 'module']);
            $this->assertContains($route['mode'], config('shop_modules.supported_gate_modes'));
            foreach ($route['module_keys'] as $moduleKey) {
                $this->assertIsString($moduleKey);
            }
            foreach ($route['actor_guards'] as $actorGuard) {
                $this->assertIsString($actorGuard);
            }
            $this->assertIsBool($route['customer_capable']);

            if ($route['classification'] === 'module') {
                $this->assertNotEmpty($route['module_keys']);
                $this->assertSame([], array_diff($route['module_keys'], $moduleKeys));
            } else {
                $this->assertSame([], $route['module_keys']);
            }
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
}
