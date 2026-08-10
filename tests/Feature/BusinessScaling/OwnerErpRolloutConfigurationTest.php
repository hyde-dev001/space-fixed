<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Http\Middleware\EnsureErpAudience;
use App\Http\Middleware\EnsureOwnerErpWorkspaceEnabled;
use App\Http\Middleware\ResolveErpActorContext;
use App\Models\ShopOwner;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class OwnerErpRolloutConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_workspace_topology_is_identical_when_the_flag_changes(): void
    {
        config(['shop_modules.owner_erp_workspace_enabled' => false]);
        $disabledTopology = $this->ownerErpTopology();

        config(['shop_modules.owner_erp_workspace_enabled' => true]);
        $enabledTopology = $this->ownerErpTopology();

        $this->assertNotEmpty($disabledTopology);
        $this->assertSame($disabledTopology, $enabledTopology);
        $this->assertArrayHasKey('shop-owner.erp.workspace', $disabledTopology);
        $this->assertArrayHasKey('shop-owner.erp.api.workspace', $disabledTopology);
    }

    public function test_disabled_workspace_returns_safe_not_found_before_actor_or_binding_resolution(): void
    {
        config(['shop_modules.owner_erp_workspace_enabled' => false]);

        $this->getJson('/shop-owner/erp/workspace?shop_owner_id=999999')
            ->assertNotFound();
    }

    public function test_enabled_workspace_uses_owner_session_and_passes_the_feature_boundary(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.erp.workspace'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ERP/Workspace', false)
                ->has('enabledModules')
                ->has('unavailableModules')
                ->has('navigationGroups')
                ->where('urls.portal', route('shop-owner.dashboard'))
                ->where('urls.settings', route('shop-owner.settings'))
            );
    }

    public function test_owner_api_routes_use_web_sessions_and_the_same_feature_boundary(): void
    {
        $route = RouteFacade::getRoutes()->getByName('shop-owner.erp.api.workspace');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('api/shop-owner/erp/workspace', $route->uri());
        $this->assertContains('web', $route->middleware());
        $this->assertContains(EnsureOwnerErpWorkspaceEnabled::class, $route->middleware());

        $resolved = app('router')->gatherRouteMiddleware($route);
        $featureIndex = collect($resolved)->search(
            static fn (string $middleware): bool => str_contains($middleware, 'EnsureOwnerErpWorkspaceEnabled'),
        );
        $audienceIndex = collect($resolved)->search(
            static fn (string $middleware): bool => str_contains($middleware, 'EnsureErpAudience'),
        );
        $authIndex = collect($resolved)->search(
            static fn (string $middleware): bool => str_contains($middleware, 'Authenticate:shop_owner'),
        );
        $actorIndex = collect($resolved)->search(
            static fn (string $middleware): bool => str_contains($middleware, 'ResolveErpActorContext'),
        );

        $this->assertNotFalse($featureIndex);
        $this->assertNotFalse($audienceIndex);
        $this->assertNotFalse($authIndex);
        $this->assertNotFalse($actorIndex);
        $this->assertLessThan($audienceIndex, $featureIndex);
        $this->assertLessThan($authIndex, $audienceIndex);
        $this->assertLessThan($actorIndex, $authIndex);
    }

    public function test_production_boot_fails_closed_when_workspace_is_enabled_without_module_enforcement(): void
    {
        config([
            'app.env' => 'production',
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => false,
        ]);
        $this->app->instance('env', 'production');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SHOP_OWNER_ERP_WORKSPACE_ENABLED');

        (new AppServiceProvider($this->app))->boot();
    }

    /**
     * @return array<string, array{methods: array<int, string>, uri: string, middleware: array<int, string>}>
     */
    private function ownerErpTopology(): array
    {
        $topology = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'shop-owner.erp.')) {
                continue;
            }

            $topology[$name] = [
                'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
                'uri' => $route->uri(),
                'middleware' => $route->middleware(),
            ];
        }

        ksort($topology);

        return $topology;
    }
}
