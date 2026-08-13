<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\Concerns\BuildsPhaseTwoWorkflowFixtures;
use Tests\TestCase;

final class PhaseTwoBaselineContractTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use BuildsPhaseTwoWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware([
            'web',
            'super_admin.auth',
            'privileged.active',
            'privileged.mfa',
            'privileged.capability:intervene_accounts',
        ])->get('/__tests/phase-two-operational', static fn () => response()->json(['ok' => true]));
    }

    public function test_both_privileged_roles_reach_operational_capabilities_only_after_completed_mfa(): void
    {
        foreach ([$this->phaseTwoAdmin(), $this->phaseTwoSuperAdmin()] as $admin) {
            $this->actingAs($admin, 'super_admin')
                ->get('/__tests/phase-two-operational')
                ->assertRedirect('/admin/login');

            $this->actingAsCompletedPrivileged($admin)
                ->getJson('/__tests/phase-two-operational')
                ->assertOk()
                ->assertJson(['ok' => true]);
        }
    }

    public function test_appeal_decisions_remain_super_admin_only(): void
    {
        $route = Route::getRoutes()->getByName('admin.appeals.approve');

        self::assertNotNull($route);
        self::assertContains('privileged.capability:resolve_appeals', $route->middleware());

        $admin = $this->phaseTwoAdmin();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson('/admin/appeals/999999/approve')
            ->assertForbidden();
    }

    public function test_private_registration_documents_remain_behind_privileged_boundaries(): void
    {
        foreach ([
            'admin.shop-documents.show' => 'privileged.capability:review_registrations',
            'admin.users.valid-id.show' => 'privileged.capability:intervene_accounts',
        ] as $routeName => $capability) {
            $route = Route::getRoutes()->getByName($routeName);

            self::assertNotNull($route, $routeName);
            self::assertContains('super_admin.auth', $route->middleware(), $routeName);
            self::assertContains('privileged.active', $route->middleware(), $routeName);
            self::assertContains('privileged.mfa', $route->middleware(), $routeName);
            self::assertContains($capability, $route->middleware(), $routeName);
        }
    }

    public function test_administrators_have_no_archive_or_delete_endpoint_and_user_shop_hard_delete_is_absent(): void
    {
        foreach ([
            'admin.admins.archive',
            'admin.admins.delete',
            'admin.shops.delete',
            'admin.users.delete',
        ] as $routeName) {
            self::assertNull(Route::getRoutes()->getByName($routeName), "Forbidden route still exists: {$routeName}");
        }

        self::assertFileDoesNotExist(app_path('Http/Controllers/SuperAdminController.php'));

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! $route instanceof RoutingRoute) {
                continue;
            }

            if (preg_match('#^admin/(admins|shops|users)(/|$)#', ltrim($route->uri(), '/')) === 1) {
                self::assertNotContains('DELETE', $route->methods(), $route->uri());
            }
        }
    }
}
