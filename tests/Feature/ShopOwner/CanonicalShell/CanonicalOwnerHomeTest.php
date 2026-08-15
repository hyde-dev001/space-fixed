<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\CanonicalShell;

use App\Http\Controllers\ShopOwner\DashboardController;
use App\Http\Controllers\ShopOwner\ShopOwnerDashboardController;
use App\Http\Middleware\EnsureOwnerErpWorkspaceEnabled;
use App\Models\ShopOwner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CanonicalOwnerHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_dashboard_and_canonical_home_reuse_the_same_page_with_trusted_placeholder_selection(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        config([
            'owner_shell.enabled' => false,
            'shop_modules.owner_erp_workspace_enabled' => false,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/Dashboard', false)
                ->where('showPhaseThreePlaceholders', false)
                ->missing('ownerActionCenter'));

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/Dashboard', false)
                ->where('showPhaseThreePlaceholders', true)
                ->missing('ownerActionCenter'));
    }

    public function test_canonical_home_is_registered_once_without_erp_workspace_gate_and_works_with_flags_off(): void
    {
        config([
            'owner_shell.enabled' => false,
            'shop_modules.owner_erp_workspace_enabled' => false,
        ]);

        $route = RouteFacade::getRoutes()->getByName('shop-owner.shell.home');

        $this->assertNotNull($route);
        $this->assertSame('shop-owner/home', $route->uri());
        $this->assertSame(ShopOwnerDashboardController::class, $route->getControllerClass());
        $this->assertContains('auth:shop_owner', $route->middleware());
        $this->assertNotContains(EnsureOwnerErpWorkspaceEnabled::class, $route->gatherMiddleware());
        $this->assertSame(
            1,
            collect(RouteFacade::getRoutes()->getRoutes())
                ->filter(static fn ($candidate): bool => $candidate->getName() === 'shop-owner.shell.home')
                ->count(),
        );
        $this->assertTrue(RouteFacade::has('shop-owner.shell.home'));
        $this->assertSame('shop-owner/dashboard', RouteFacade::getRoutes()->getByName('shop-owner.dashboard')->uri());
    }

    public function test_dashboard_routes_do_not_query_phase_three_attention_sources(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $forbiddenQueries = [];
        $forbiddenTerms = [
            'approval',
            'exception',
            'notification',
            'refund',
            'repair',
            'payroll',
            'attention',
        ];

        DB::listen(function (QueryExecuted $query) use (&$forbiddenQueries, $forbiddenTerms): void {
            $sql = strtolower($query->sql);

            foreach ($forbiddenTerms as $term) {
                if (str_contains($sql, $term)) {
                    $forbiddenQueries[] = $query->sql;
                    break;
                }
            }
        });

        config([
            'owner_shell.enabled' => false,
            'shop_modules.owner_erp_workspace_enabled' => false,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.shell.home'))
            ->assertOk();

        $this->assertSame([], $forbiddenQueries);
    }

    public function test_existing_dashboard_stats_api_remains_the_same_route_and_controller_boundary(): void
    {
        $route = collect(RouteFacade::getRoutes()->getRoutes())
            ->first(static fn ($candidate): bool => $candidate->uri() === 'api/shop-owner/dashboard/stats');

        $this->assertNotNull($route);
        $this->assertSame('api/shop-owner/dashboard/stats', $route->uri());
        $this->assertSame(DashboardController::class . '@getStats', $route->getActionName());
    }
}
