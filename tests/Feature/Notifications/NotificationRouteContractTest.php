<?php

namespace Tests\Feature\Notifications;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationRouteContractTest extends TestCase
{
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
}
