<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

final class ShopModuleRouteCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_authoritative_route_entry_is_loaded_and_has_a_complete_schema(): void
    {
        $routes = config('shop_modules.routes', []);
        $this->assertNotEmpty($routes);

        foreach ($routes as $routeName => $entry) {
            $this->assertIsArray($entry, $routeName);
            $this->assertContains($entry['classification'] ?? null, ['core', 'module', 'excluded'], $routeName);
            $this->assertTrue($entry['mode'] === null || in_array($entry['mode'], config('shop_modules.supported_gate_modes', []), true), $routeName);
            $this->assertIsArray($entry['module_keys'] ?? null, $routeName);
            $this->assertContains($entry['actor_guard'] ?? null, ['user', 'shop_owner', null], $routeName);

            $route = RouteFacade::getRoutes()->getByName($routeName);
            $this->assertInstanceOf(Route::class, $route, "Missing loaded route {$routeName}.");

            $hasModuleMiddleware = in_array('shop.module', $route->middleware(), true)
                || in_array('App\\Http\\Middleware\\EnsureShopModuleEnabled', $route->gatherMiddleware(), true);
            $this->assertSame($entry['classification'] === 'module', $hasModuleMiddleware, $routeName);

            if ($entry['classification'] === 'module') {
                $gathered = $route->gatherMiddleware();
                $moduleIndex = collect($gathered)->search(static fn (string $middleware): bool => str_contains($middleware, 'EnsureShopModuleEnabled')
                    || $middleware === 'shop.module');
                $authIndex = collect($gathered)->search(static fn (string $middleware): bool => str_contains($middleware, 'Authenticate')
                    || str_contains($middleware, 'auth:'.$entry['actor_guard']));
                $this->assertNotFalse($authIndex, $routeName);
                $this->assertNotFalse($moduleIndex, $routeName);
                $this->assertLessThan($moduleIndex, $authIndex, $routeName);
            }
        }
    }

    public function test_module_route_names_are_unique_and_core_routes_are_not_gated(): void
    {
        $routes = config('shop_modules.routes', []);
        $this->assertSame(count($routes), count(array_unique(array_keys($routes))));

        foreach ($routes as $routeName => $entry) {
            if (($entry['classification'] ?? null) !== 'core') {
                continue;
            }

            $route = RouteFacade::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, $routeName);
            $this->assertNotContains('shop.module', $route->middleware(), $routeName);
        }
    }

    public function test_loaded_internal_routes_are_catalogued_in_both_directions_and_methods_match(): void
    {
        $catalog = config('shop_modules.routes', []);
        $errors = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! $this->isInternalErpRoute($route)) {
                continue;
            }

            $routeName = (string) $route->getName();
            if ($routeName === '') {
                $errors[] = "unnamed internal route {$route->uri()}";
                continue;
            }

            if (! array_key_exists($routeName, $catalog)) {
                $errors[] = "missing catalog entry {$routeName}";
                continue;
            }

            $expectedMethods = array_values(array_diff($route->methods(), ['HEAD']));
            $actualMethods = array_values(array_unique(array_map('strtoupper', $catalog[$routeName]['methods'] ?? [])));
            sort($expectedMethods);
            sort($actualMethods);

            if ($actualMethods !== $expectedMethods) {
                $errors[] = sprintf(
                    '%s methods expected [%s], got [%s]',
                    $routeName,
                    implode(',', $expectedMethods),
                    implode(',', $actualMethods),
                );
            }
        }

        $this->assertSame([], $errors, implode(PHP_EOL, $errors));
    }

    public function test_operational_erp_routes_select_audience_and_actor_before_capability_or_binding(): void
    {
        foreach (config('shop_modules.routes', []) as $routeName => $entry) {
            if (! in_array($entry['classification'] ?? null, ['core', 'module'], true)
                || ! $this->isOperationalErpRoute((string) $routeName)) {
                continue;
            }

            $route = RouteFacade::getRoutes()->getByName($routeName);
            $this->assertInstanceOf(Route::class, $route, $routeName);
            $middleware = app('router')->gatherRouteMiddleware($route);

            $audienceIndex = collect($middleware)->search(
                static fn (string $value): bool => str_contains($value, 'EnsureErpAudience'),
            );
            $authIndex = collect($middleware)->search(
                static fn (string $value): bool => str_contains($value, 'Authenticate')
                    || str_contains($value, 'auth:'.($entry['actor_guard'] ?? '')),
            );
            $actorIndex = collect($middleware)->search(
                static fn (string $value): bool => str_contains($value, 'ResolveErpActorContext'),
            );
            $bindingIndex = collect($middleware)->search(
                static fn (string $value): bool => str_contains($value, 'SubstituteBindings'),
            );

            $this->assertNotFalse($audienceIndex, $routeName);
            $this->assertNotFalse($authIndex, $routeName);
            $this->assertNotFalse($actorIndex, $routeName);
            $this->assertLessThan($authIndex, $audienceIndex, $routeName);
            $this->assertLessThan($actorIndex, $authIndex, $routeName);

            if ($entry['classification'] === 'module') {
                $moduleIndex = collect($middleware)->search(
                    static fn (string $value): bool => str_contains($value, 'EnsureShopModuleEnabled'),
                );
                $this->assertNotFalse($moduleIndex, $routeName);
                $this->assertLessThan($moduleIndex, $actorIndex, $routeName);
            }

            if ($bindingIndex !== false) {
                $this->assertLessThan($bindingIndex, $actorIndex, $routeName);
            }
        }
    }

    private function isOperationalErpRoute(string $routeName): bool
    {
        foreach ([
            'api.manager.',
            'crm.',
            'erp.',
            'finance.',
            'hr.',
            'inventory.',
            'procurement.',
            'staff.',
            'shop-owner.erp.',
            'shop_owner.erp.',
            'shop_owner.finance.',
            'shop_owner.hr.',
        ] as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isInternalErpRoute(Route $route): bool
    {
        $name = (string) $route->getName();
        $internalPrefixes = [
            'api.manager.',
            'api.leave.',
            'api.logistics.',
            'api.notifications.',
            'api.activity_logs',
            'api.shop_owner.',
            'api.products.vouchers.',
            'api.customer.repairs.warranty-claims',
            'api.shops.report',
            'erp.',
            'hr.',
            'inventory.',
            'finance.',
            'procurement.',
            'crm.',
            'staff.',
            'permission-audit-logs.',
            'shop-owner.',
            'shop_owner.',
            'shopOwner.',
        ];

        if ($name !== '' && collect($internalPrefixes)->contains(
            fn (string $prefix): bool => str_starts_with($name, $prefix),
        )) {
            return true;
        }

        $middleware = $route->gatherMiddleware();
        $uri = (string) $route->uri();

        return $name === '' && (
            str_contains($uri, 'erp')
            || in_array('shop.module', $middleware, true)
            || collect($middleware)->contains(fn (string $value): bool => str_contains($value, 'GateErpAccess'))
        );
    }
}
