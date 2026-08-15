<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\CanonicalShell;

use App\Http\Controllers\ShopOwner\ShopSettingsController;
use App\Http\Middleware\EnsureOwnerErpWorkspaceEnabled;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CanonicalOwnerSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_canonical_settings_section_reuses_the_existing_component_with_a_trusted_initial_section(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        foreach ($this->settingsRoutes() as $name => $definition) {
            $this->actingAs($owner, 'shop_owner')
                ->get(route($name))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('ShopOwner/Settings/shopSetting', false)
                    ->where('initialSection', $definition['section']));
        }
    }

    public function test_compatibility_settings_defaults_to_the_existing_top_section(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/Settings/shopSetting', false)
                ->where('initialSection', 'profile'));

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/settings/not-a-section')
            ->assertNotFound();
    }

    public function test_canonical_settings_routes_are_static_owner_aliases_without_erp_workspace_gate(): void
    {
        foreach ($this->settingsRoutes() as $name => $definition) {
            $route = RouteFacade::getRoutes()->getByName($name);

            $this->assertInstanceOf(Route::class, $route);
            $this->assertSame($definition['uri'], $route->uri());
            $this->assertSame(ShopSettingsController::class . '@index', $route->getActionName());
            $this->assertContains('auth:shop_owner', $route->middleware());
            $this->assertNotContains(EnsureOwnerErpWorkspaceEnabled::class, $route->gatherMiddleware());
            $this->assertStringNotContainsString('/erp', $route->uri());
            $this->assertSame(
                1,
                collect(RouteFacade::getRoutes()->getRoutes())
                    ->filter(static fn (Route $candidate): bool => $candidate->getName() === $name)
                    ->count(),
            );
        }
    }

    public function test_complete_phase_two_canonical_route_inventory_has_one_static_url_per_destination(): void
    {
        $inventory = [
            'shop-owner.shell.home' => 'shop-owner/home',
            'shop-owner.shell.operate.retail' => 'shop-owner/operate/retail',
            'shop-owner.shell.operate.repair' => 'shop-owner/operate/repair',
            'shop-owner.shell.operate.customers' => 'shop-owner/operate/customers',
            'shop-owner.shell.operate.payments' => 'shop-owner/operate/payments',
            'shop-owner.shell.oversee.finance' => 'shop-owner/oversee/finance',
            'shop-owner.shell.oversee.workforce' => 'shop-owner/oversee/workforce',
            'shop-owner.shell.oversee.inventory' => 'shop-owner/oversee/inventory',
            'shop-owner.shell.oversee.procurement' => 'shop-owner/oversee/procurement',
            'shop-owner.shell.oversee.logistics' => 'shop-owner/oversee/logistics',
            'shop-owner.shell.reports' => 'shop-owner/reports',
            'shop-owner.shell.audit' => 'shop-owner/audit',
            ...array_map(
                static fn (array $definition): string => $definition['uri'],
                $this->settingsRoutes(),
            ),
        ];

        foreach ($inventory as $name => $uri) {
            $route = RouteFacade::getRoutes()->getByName($name);

            $this->assertInstanceOf(Route::class, $route, "Missing canonical route {$name}.");
            $this->assertSame($uri, $route->uri());
            $this->assertStringNotContainsString('/erp', $route->uri());
        }
    }

    /**
     * @return array<string, array{uri: string, section: string}>
     */
    private function settingsRoutes(): array
    {
        return [
            'shop-owner.shell.settings.profile' => [
                'uri' => 'shop-owner/settings/profile',
                'section' => 'profile',
            ],
            'shop-owner.shell.settings.modules-team' => [
                'uri' => 'shop-owner/settings/modules-team',
                'section' => 'modules-team',
            ],
            'shop-owner.shell.settings.payments-approvals' => [
                'uri' => 'shop-owner/settings/payments-approvals',
                'section' => 'payments-approvals',
            ],
            'shop-owner.shell.settings.operations' => [
                'uri' => 'shop-owner/settings/operations',
                'section' => 'operations',
            ],
            'shop-owner.shell.settings.policies-compliance' => [
                'uri' => 'shop-owner/settings/policies-compliance',
                'section' => 'policies-compliance',
            ],
            'shop-owner.shell.settings.subscription' => [
                'uri' => 'shop-owner/settings/subscription',
                'section' => 'subscription',
            ],
        ];
    }
}
