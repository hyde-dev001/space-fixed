<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\ShopOwnerStatus;
use App\Models\AccountSuspension;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

class PhaseZeroAuthorizationTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_unauthenticated_admin_request_redirects_to_login(): void
    {
        $this->get('/admin/administrators')
            ->assertRedirect(route('admin.login'));
    }

    #[DataProvider('restrictedRequests')]
    public function test_regular_admin_is_denied_restricted_actions(
        string $method,
        string $uri,
        array $payload = [],
    ): void {
        $admin = SuperAdmin::factory()->admin()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->call($method, $uri, $payload, $this->prepareCookiesForRequest())
            ->assertForbidden();
    }

    public static function restrictedRequests(): array
    {
        return [
            'administrator management' => ['GET', '/admin/administrators'],
            'administrator creation' => ['POST', '/admin/administrators'],
            'plan management' => ['POST', '/admin/premium-plans'],
            'appeal decision' => ['POST', '/admin/appeals/1/approve'],
        ];
    }

    public function test_regular_admin_can_review_registrations_and_intervene_on_accounts(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $user = $this->suspendedUserWithCurrentIdentity();

        $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/shop-owner-registration-view')
            ->assertOk();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson("/admin/users/{$user->id}/reactivate", [
                'reactivation_reason' => 'Phase zero capability regression check',
            ])
            ->assertOk();

        $this->assertSame('active', $user->fresh()->status);
    }

    public function test_super_admin_can_reach_every_phase_zero_capability_area(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $user = $this->suspendedUserWithCurrentIdentity();

        $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/administrators')
            ->assertOk();

        $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/shop-owner-registration-view')
            ->assertOk();

        $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/shops')
            ->assertOk();

        $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/subscription-management')
            ->assertOk();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson("/admin/users/{$user->id}/reactivate", [
                'reactivation_reason' => 'Phase zero capability regression check',
            ])
            ->assertOk();
    }

    private function suspendedUserWithCurrentIdentity(): User
    {
        $user = User::factory()->create(['status' => 'suspended']);
        $suspension = AccountSuspension::create([
            'account_type' => AccountSuspension::ACCOUNT_TYPE_CUSTOMER,
            'account_id' => $user->id,
            'source' => AccountSuspension::SOURCE_LEGACY_RECONCILIATION,
            'reason' => 'Phase zero authorization fixture',
            'started_at' => now(),
        ]);
        $user->forceFill(['current_suspension_id' => $suspension->id])->save();

        return $user->fresh();
    }

    public function test_restricted_routes_declare_the_fixed_capability(): void
    {
        $expected = [
            'admin.administrators.index' => SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
            'admin.administrators.store' => SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
            'admin.administrators.suspend' => SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
            'admin.administrators.activate' => SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
            'admin.shop-owner-registration-view' => SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            'admin.shop-documents.show' => SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            'admin.shop-owner-approve' => SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            'admin.shop-owner-reject' => SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            'admin.business-upgrade-requests.index' => SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            'admin.business-upgrade-requests.update' => SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            'admin.business-upgrade-requests.documents.download' => SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            'admin.shops.index' => SuperAdmin::CAP_INTERVENE_ACCOUNTS,
            'admin.shops.show' => SuperAdmin::CAP_INTERVENE_ACCOUNTS,
            'admin.shops.suspend' => SuperAdmin::CAP_INTERVENE_ACCOUNTS,
            'admin.shops.reactivate' => SuperAdmin::CAP_INTERVENE_ACCOUNTS,
            'admin.users.index' => SuperAdmin::CAP_INTERVENE_ACCOUNTS,
            'admin.users.valid-id.show' => SuperAdmin::CAP_INTERVENE_ACCOUNTS,
            'admin.users.suspend' => SuperAdmin::CAP_INTERVENE_ACCOUNTS,
            'admin.users.reactivate' => SuperAdmin::CAP_INTERVENE_ACCOUNTS,
            'admin.premium-plans.store' => SuperAdmin::CAP_MANAGE_PLANS,
            'admin.premium-plans.update' => SuperAdmin::CAP_MANAGE_PLANS,
            'admin.premium-plans.archive' => SuperAdmin::CAP_MANAGE_PLANS,
            'admin.premium-plans.reactivate' => SuperAdmin::CAP_MANAGE_PLANS,
            'admin.subscription-management' => SuperAdmin::CAP_MANAGE_PLANS,
            'admin.appeals.approve' => SuperAdmin::CAP_RESOLVE_APPEALS,
            'admin.appeals.reject' => SuperAdmin::CAP_RESOLVE_APPEALS,
        ];

        foreach ($expected as $routeName => $capability) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Missing route {$routeName}");
            $this->assertContains('super_admin.auth', $route->middleware());
            $this->assertContains("privileged.capability:{$capability}", $route->middleware());
        }
    }

    public function test_registration_mutations_have_one_canonical_owner_and_no_legacy_mutation(): void
    {
        $approvalRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => $route->uri() === 'admin/shop-owner-registration/{id}/approve')
            ->filter(fn ($route) => in_array('POST', $route->methods(), true));
        $rejectionRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => $route->uri() === 'admin/shop-owner-registration/{id}/reject')
            ->filter(fn ($route) => in_array('POST', $route->methods(), true));

        $this->assertCount(1, $approvalRoutes);
        $this->assertCount(1, $rejectionRoutes);
        $this->assertSame('admin.shop-owner-approve', $approvalRoutes->first()->getName());
        $this->assertSame('admin.shop-owner-reject', $rejectionRoutes->first()->getName());

        $shopOwner = ShopOwner::factory()->create(['status' => 'pending']);

        $this->postJson("/superAdmin/shop-owner-registration/{$shopOwner->id}/approve")
            ->assertStatus(404);
        $this->postJson("/superAdmin/shop-owner-registration/{$shopOwner->id}/reject", [
            'rejection_reason' => 'Not accepted',
        ])->assertStatus(404);

        $this->assertSame(ShopOwnerStatus::PENDING, $shopOwner->fresh()->status);
    }

    public function test_registration_route_compatibility_is_get_only_and_redirects_to_admin(): void
    {
        $canonicalIndex = collect(Route::getRoutes())
            ->filter(fn ($route) => $route->getName() === 'admin.shop-owner-registration-view');
        $canonicalApproval = collect(Route::getRoutes())
            ->filter(fn ($route) => $route->getName() === 'admin.shop-owner-approve');
        $canonicalRejection = collect(Route::getRoutes())
            ->filter(fn ($route) => $route->getName() === 'admin.shop-owner-reject');
        $legacyCompatibility = collect(Route::getRoutes())
            ->filter(fn ($route) => $route->uri() === 'superAdmin/shop-owner-registration-view');

        $this->assertCount(1, $canonicalIndex);
        $this->assertCount(1, $canonicalApproval);
        $this->assertCount(1, $canonicalRejection);
        $this->assertCount(1, $legacyCompatibility);
        $this->assertSame(['GET', 'HEAD'], $legacyCompatibility->first()->methods());

        $admin = SuperAdmin::factory()->admin()->create();
        $this->actingAsCompletedPrivileged($admin)
            ->get('/superAdmin/shop-owner-registration-view')
            ->assertRedirect(route('admin.shop-owner-registration-view'));
    }

    public function test_legacy_registration_mutations_are_not_registered_for_authenticated_admins(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $shopOwner = ShopOwner::factory()->create(['status' => ShopOwnerStatus::PENDING]);

        foreach (['approve', 'reject'] as $decision) {
            $response = $this->actingAsCompletedPrivileged($admin)
                ->postJson("/superAdmin/shop-owner-registration/{$shopOwner->id}/{$decision}", [
                    'rejection_reason' => 'Not accepted',
                ]);

            $this->assertContains($response->getStatusCode(), [404, 405]);
        }

        $this->assertSame(ShopOwnerStatus::PENDING, $shopOwner->fresh()->status);
    }
}
