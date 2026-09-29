<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ManagerLegacyRouteTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('Manager', 'user');

        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
            'registration_type' => 'company',
        ]);

        $this->manager = User::factory()->for($shop)->create(['role' => 'Manager']);
        $this->manager->assignRole('Manager');
    }

    public function test_verified_legacy_manager_pages_redirect_to_canonical_destinations(): void
    {
        $this->actingAs($this->manager, 'user')
            ->get(route('erp.manager.products'))
            ->assertRedirect(route('erp.manager.inventory-overview'));

        $this->actingAs($this->manager, 'user')
            ->get(route('erp.manager.repair-rejection-review'))
            ->assertRedirect(route('erp.manager.repair-jobs'));

        $this->actingAs($this->manager, 'user')
            ->get(route('erp.manager.suspend-approval'))
            ->assertRedirect(route('erp.manager.suspension-approvals'));
    }

    public function test_obsolete_manager_routes_are_removed_while_required_dss_api_compatibility_remains(): void
    {
        foreach ([
            'erp.manager.user-management',
            'erp.manager.dss-insights',
            'api.manager.products',
            'api.manager.analytics',
        ] as $routeName) {
            $this->assertNull(RouteFacade::getRoutes()->getByName($routeName), $routeName);
        }

        $this->assertInstanceOf(Route::class, RouteFacade::getRoutes()->getByName('api.manager.dss-insights'));
    }

    public function test_canonical_manager_pages_remain_named_and_reachable(): void
    {
        foreach ([
            'erp.manager.dashboard',
            'erp.manager.job-orders',
            'erp.manager.repair-jobs',
            'erp.manager.inventory-overview',
            'erp.manager.staff-workload',
            'erp.manager.leave-approvals',
            'erp.manager.suspension-approvals',
            'erp.manager.reports',
            'erp.manager.audit-logs',
        ] as $routeName) {
            $this->assertInstanceOf(Route::class, RouteFacade::getRoutes()->getByName($routeName), $routeName);
        }
    }
}
