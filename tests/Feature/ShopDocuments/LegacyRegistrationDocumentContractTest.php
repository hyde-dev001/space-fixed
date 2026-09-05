<?php

declare(strict_types=1);

namespace Tests\Feature\ShopDocuments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

final class LegacyRegistrationDocumentContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_registration_mutation_routes_are_removed(): void
    {
        foreach (['api/shop/register', 'api/shop/register-full', 'shop/register-full'] as $uri) {
            $this->postJson('/'.$uri)->assertNotFound();
        }

        $this->assertDatabaseCount('shop_owners', 0);
        $this->assertDatabaseCount('shop_documents', 0);
    }

    public function test_canonical_registration_route_is_the_only_registration_write_boundary(): void
    {
        $route = RouteFacade::getRoutes()->getByName('shop-owner.register');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('shop-owner/register', $route->uri());
        $this->assertStringContainsString('ShopOwnerAuthController@register', $route->getActionName());
    }
}
