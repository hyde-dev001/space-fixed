<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Enums\ShopOwnerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhaseZeroAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_admin_request_redirects_to_login(): void
    {
        $this->get('/admin/admin')
            ->assertRedirect(route('admin.login'));
    }

    #[DataProvider('restrictedRequests')]
    public function test_regular_admin_is_denied_restricted_actions(
        string $method,
        string $uri,
        array $payload = [],
    ): void {
        $admin = SuperAdmin::factory()->admin()->create();

        $this->actingAs($admin, 'super_admin')
            ->call($method, $uri, $payload)
            ->assertForbidden();
    }

    public static function restrictedRequests(): array
    {
        return [
            'administrator management' => ['GET', '/admin/admin'],
            'administrator creation' => ['POST', '/admin/create-admin'],
            'plan management' => ['POST', '/admin/premium-plans'],
            'subscription intervention' => ['POST', '/admin/subscriptions/1/cancel'],
            'appeal decision' => ['POST', '/admin/appeals/1/approve'],
        ];
    }

    public function test_regular_admin_can_review_registrations_and_intervene_on_accounts(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $user = User::factory()->create(['status' => 'suspended']);

        $this->actingAs($admin, 'super_admin')
            ->get('/admin/shop-owner-registration-view')
            ->assertOk();

        $this->actingAs($admin, 'super_admin')
            ->postJson("/admin/users/{$user->id}/activate")
            ->assertOk();

        $this->assertSame('active', $user->fresh()->status);
    }

    public function test_super_admin_can_reach_every_phase_zero_capability_area(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $user = User::factory()->create(['status' => 'suspended']);

        $this->actingAs($admin, 'super_admin')
            ->get('/admin/admin')
            ->assertOk();

        $this->actingAs($admin, 'super_admin')
            ->get('/admin/shop-owner-registration-view')
            ->assertOk();

        $this->actingAs($admin, 'super_admin')
            ->get('/admin/registered-shops')
            ->assertOk();

        $this->actingAs($admin, 'super_admin')
            ->get('/admin/subscription-management')
            ->assertOk();

        $this->actingAs($admin, 'super_admin')
            ->postJson("/admin/users/{$user->id}/activate")
            ->assertOk();
    }

    public function test_restricted_routes_declare_the_fixed_capability(): void
    {
        $expected = [
            'admin.admin-management' => SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
            'admin.create-admin.store' => SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
            'admin.admins.suspend' => SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
            'admin.admins.activate' => SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
            'admin.shop-owner-registration-view' => SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            'admin.shop-owner-approve' => SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            'admin.shop-owner-reject' => SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            'admin.business-upgrade-requests.index' => SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            'admin.business-upgrade-requests.update' => SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            'admin.business-upgrade-requests.documents.download' => SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            'admin.registered-shops' => SuperAdmin::CAP_INTERVENE_ACCOUNTS,
            'admin.shops.details' => SuperAdmin::CAP_INTERVENE_ACCOUNTS,
            'admin.shops.suspend' => SuperAdmin::CAP_INTERVENE_ACCOUNTS,
            'admin.shops.activate' => SuperAdmin::CAP_INTERVENE_ACCOUNTS,
            'admin.user-management' => SuperAdmin::CAP_INTERVENE_ACCOUNTS,
            'admin.users.suspend' => SuperAdmin::CAP_INTERVENE_ACCOUNTS,
            'admin.users.activate' => SuperAdmin::CAP_INTERVENE_ACCOUNTS,
            'admin.premium-plans.store' => SuperAdmin::CAP_MANAGE_PLANS,
            'admin.premium-plans.update' => SuperAdmin::CAP_MANAGE_PLANS,
            'admin.premium-plans.archive' => SuperAdmin::CAP_MANAGE_PLANS,
            'admin.premium-plans.reactivate' => SuperAdmin::CAP_MANAGE_PLANS,
            'admin.subscription-management' => SuperAdmin::CAP_MANAGE_PLANS,
            'admin.subscriptions.cancel' => SuperAdmin::CAP_INTERVENE_SUBSCRIPTIONS,
            'admin.subscriptions.upgrade' => SuperAdmin::CAP_INTERVENE_SUBSCRIPTIONS,
            'admin.subscriptions.downgrade' => SuperAdmin::CAP_INTERVENE_SUBSCRIPTIONS,
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
}
