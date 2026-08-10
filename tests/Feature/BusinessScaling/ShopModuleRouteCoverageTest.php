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
}
