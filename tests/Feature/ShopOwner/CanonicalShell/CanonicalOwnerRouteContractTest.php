<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\CanonicalShell;

use App\Http\Controllers\Erp\ReadPageController;
use App\Http\Controllers\Erp\WorkspaceController;
use App\Http\Middleware\EnsureOwnerErpWorkspaceEnabled;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CanonicalOwnerRouteContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_canonical_route_inventory_is_registered_once_without_erp_or_legacy_paths(): void
    {
        foreach ($this->operationalRoutes() as $name => $definition) {
            $route = RouteFacade::getRoutes()->getByName($name);

            $this->assertInstanceOf(Route::class, $route, "Missing canonical route {$name}.");
            $this->assertSame($definition['uri'], $route->uri());
            $this->assertSame(
                1,
                collect(RouteFacade::getRoutes()->getRoutes())
                    ->filter(static fn (Route $candidate): bool => $candidate->getName() === $name)
                    ->count(),
                "Canonical route {$name} must be registered exactly once.",
            );
            $this->assertStringNotContainsString('/erp', $route->uri());
            $this->assertStringNotContainsString('/legacy', $route->uri());
            $this->assertNotContains(EnsureOwnerErpWorkspaceEnabled::class, $route->gatherMiddleware());
            $this->assertContains('auth:shop_owner', $route->middleware());
            $this->assertContains('erp.audience', $route->middleware());
            $this->assertContains('erp.actor', $route->middleware());
            $expectedAction = $definition['method'] === null
                ? $definition['controller']
                : $definition['controller'].'@'.$definition['method'];
            $this->assertSame($expectedAction, $route->getActionName());

            if ($definition['module']) {
                $this->assertContains('shop.module', $route->middleware());
            }
        }
    }

    public function test_canonical_routes_remain_registered_with_both_rollout_and_erp_workspace_flags_off(): void
    {
        config([
            'owner_shell.enabled' => false,
            'shop_modules.owner_erp_workspace_enabled' => false,
        ]);

        foreach (array_keys($this->operationalRoutes()) as $name) {
            $this->assertTrue(RouteFacade::has($name), "Canonical route {$name} disappeared with flags off.");
        }
    }

    public function test_canonical_customers_route_exposes_the_overview_and_only_owner_readable_local_pages(): void
    {
        config('shop_modules.enforcement_enabled', true);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'crm',
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/operate/customers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ERP/ModuleLanding', false)
                ->where('activeModule.key', 'crm')
                ->where('activeModule.overview.label', 'Overview')
                ->where('activeModule.overview.url', route('shop-owner.shell.operate.customers'))
                ->where('activeModule.pages', fn (Collection $pages): bool => $pages
                    ->pluck('routeName')
                    ->all() === [
                        'shop-owner.erp.crm.dashboard',
                        'shop-owner.erp.crm.customers',
                        'shop-owner.erp.crm.customer-reviews',
                    ])
            );
    }

    public function test_canonical_finance_route_keeps_unproven_local_pages_out_of_the_payload(): void
    {
        config('shop_modules.enforcement_enabled', true);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'finance',
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/oversee/finance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeModule.overview.url', route('shop-owner.shell.oversee.finance'))
                ->has('activeModule.pages', 0)
            );
    }

    /**
     * @return array<string, array{uri: string, controller: class-string, method: string|null, module: bool}>
     */
    private function operationalRoutes(): array
    {
        return [
            'shop-owner.shell.operate.retail' => [
                'uri' => 'shop-owner/operate/retail',
                'controller' => WorkspaceController::class,
                'method' => 'module',
                'module' => true,
            ],
            'shop-owner.shell.operate.repair' => [
                'uri' => 'shop-owner/operate/repair',
                'controller' => WorkspaceController::class,
                'method' => 'module',
                'module' => true,
            ],
            'shop-owner.shell.operate.customers' => [
                'uri' => 'shop-owner/operate/customers',
                'controller' => WorkspaceController::class,
                'method' => 'module',
                'module' => true,
            ],
            'shop-owner.shell.operate.payments' => [
                'uri' => 'shop-owner/operate/payments',
                'controller' => 'App\\Http\\Controllers\\ShopOwner\\CanonicalOwnerPaymentsController',
                'method' => null,
                'module' => true,
            ],
            'shop-owner.shell.oversee.finance' => [
                'uri' => 'shop-owner/oversee/finance',
                'controller' => WorkspaceController::class,
                'method' => 'module',
                'module' => true,
            ],
            'shop-owner.shell.oversee.workforce' => [
                'uri' => 'shop-owner/oversee/workforce',
                'controller' => WorkspaceController::class,
                'method' => 'module',
                'module' => true,
            ],
            'shop-owner.shell.oversee.inventory' => [
                'uri' => 'shop-owner/oversee/inventory',
                'controller' => WorkspaceController::class,
                'method' => 'module',
                'module' => true,
            ],
            'shop-owner.shell.oversee.procurement' => [
                'uri' => 'shop-owner/oversee/procurement',
                'controller' => WorkspaceController::class,
                'method' => 'module',
                'module' => true,
            ],
            'shop-owner.shell.oversee.logistics' => [
                'uri' => 'shop-owner/oversee/logistics',
                'controller' => WorkspaceController::class,
                'method' => 'module',
                'module' => true,
            ],
            'shop-owner.shell.reports' => [
                'uri' => 'shop-owner/reports',
                'controller' => ReadPageController::class,
                'method' => 'managerReports',
                'module' => false,
            ],
            'shop-owner.shell.audit' => [
                'uri' => 'shop-owner/audit',
                'controller' => ReadPageController::class,
                'method' => 'managerAuditLogs',
                'module' => false,
            ],
        ];
    }
}
