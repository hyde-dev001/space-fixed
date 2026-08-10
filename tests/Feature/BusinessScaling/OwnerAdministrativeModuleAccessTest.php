<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

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
                || (! str_starts_with((string) $routeName, 'shop_owner.') && ! str_starts_with((string) $routeName, 'shop-owner.'))) {
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
}
