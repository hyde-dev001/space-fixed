<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\CanonicalShell;

use App\Http\Controllers\Erp\ReadPageController;
use App\Http\Controllers\Erp\WorkspaceController;
use App\Http\Controllers\ShopOwner\ShopSettingsController;
use App\Http\Middleware\EnsureErpAudience;
use App\Http\Middleware\EnsureOwnerErpWorkspaceEnabled;
use App\Http\Middleware\ResolveErpActorContext;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ExistingPresentationCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_dashboard_and_erp_workspace_keep_their_current_components(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ShopOwner/Dashboard', false));

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.erp.workspace'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ERP/Workspace', false));
    }

    public function test_existing_erp_workspace_returns_not_found_when_its_compatibility_flag_is_off(): void
    {
        config(['shop_modules.owner_erp_workspace_enabled' => false]);

        $this->get(route('shop-owner.erp.workspace'))->assertNotFound();
    }

    public function test_existing_operational_read_and_settings_boundaries_keep_their_routes_middleware_and_components(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);

        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);

        $this->assertRouteBoundary(
            'shop-owner.erp.module',
            'shop-owner/erp/{module}',
            WorkspaceController::class,
            [EnsureOwnerErpWorkspaceEnabled::class, EnsureErpAudience::class, ResolveErpActorContext::class, 'auth:shop_owner'],
        );
        $this->assertRouteBoundary(
            'shop-owner.erp.manager.reports',
            'shop-owner/erp/manager/reports',
            ReadPageController::class,
            [EnsureOwnerErpWorkspaceEnabled::class, EnsureErpAudience::class, ResolveErpActorContext::class, 'auth:shop_owner'],
        );
        $this->assertRouteBoundary(
            'shop-owner.erp.manager.audit-logs',
            'shop-owner/erp/manager/audit-logs',
            ReadPageController::class,
            [EnsureOwnerErpWorkspaceEnabled::class, EnsureErpAudience::class, ResolveErpActorContext::class, 'auth:shop_owner'],
        );
        $this->assertRouteBoundary(
            'shop-owner.settings',
            'shop-owner/settings',
            ShopSettingsController::class,
            ['auth:shop_owner'],
        );

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/retail')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ERP/ModuleLanding', false));

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.erp.manager.reports'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ERP/Manager/Reports', false));

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.erp.manager.audit-logs'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ERP/Manager/AuditLogs', false));

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ShopOwner/Settings/shopSetting', false));
    }

    /**
     * @param array<int, string> $requiredMiddleware
     */
    private function assertRouteBoundary(
        string $name,
        string $uri,
        string $controller,
        array $requiredMiddleware,
    ): void {
        $route = RouteFacade::getRoutes()->getByName($name);

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame($uri, $route->uri());
        $this->assertStringStartsWith($controller.'@', $route->getActionName());

        $middleware = $route->middleware();
        foreach ($requiredMiddleware as $middlewareName) {
            $this->assertContains($middlewareName, $middleware, "Missing middleware {$middlewareName} on {$name}.");
        }
    }
}
