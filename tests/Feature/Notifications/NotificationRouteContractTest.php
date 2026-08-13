<?php

namespace Tests\Feature\Notifications;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationRouteContractTest extends TestCase
{
    #[Test]
    public function customer_notification_routes_start_the_session_before_authentication(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/routes/api.php');

        $this->assertIsString($source);
        $this->assertStringContainsString(
            "Route::prefix('notifications')->middleware(['web', 'auth:user'])",
            $source,
        );
    }

    #[Test]
    public function notification_namespaces_have_unique_and_canonical_endpoints(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route) => [
                'uri' => $route->uri(),
                'methods' => collect($route->methods())
                    ->filter(fn ($method) => $method !== 'HEAD')
                    ->values()
                    ->all(),
                'name' => $route->getName(),
            ]);

        $customer = $routes->where('uri', 'api/notifications');

        $this->assertSame(1, $customer->count());
        $this->assertTrue($routes->contains(fn (array $route) => $route['uri'] === 'api/notifications/mark-all-read'));
        $this->assertFalse($routes->contains(fn (array $route) => $route['uri'] === 'api/notifications/read-all'));
    }

    #[Test]
    public function no_notification_route_collisions_exist_after_unification(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->flatMap(function ($route) {
                $uri = (string) $route->uri();

                if (!str_contains($uri, 'notifications')) {
                    return [];
                }

                return collect($route->methods())
                    ->filter(fn ($method) => $method !== 'HEAD')
                    ->map(fn ($method) => [
                        'signature' => strtoupper((string) $method) . ' ' . $uri,
                    ])
                    ->values()
                    ->all();
            });

        $collisions = $routes
            ->groupBy('signature')
            ->filter(fn ($group) => $group->count() > 1);

        $this->assertCount(0, $collisions, 'Found duplicate notification route signatures: ' . $collisions->keys()->implode(', '));
    }
}
