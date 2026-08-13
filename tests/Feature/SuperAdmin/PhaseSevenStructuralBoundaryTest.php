<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class PhaseSevenStructuralBoundaryTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_administrator_management_has_one_canonical_route_owner_per_action(): void
    {
        $this->assertCanonicalRoute(
            name: 'admin.administrators.index',
            methods: ['GET', 'HEAD'],
            uri: 'admin/administrators',
            action: 'App\\Http\\Controllers\\superAdmin\\AdministratorManagementController@index',
            capability: SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
        );
        $this->assertCanonicalRoute(
            name: 'admin.administrators.create',
            methods: ['GET', 'HEAD'],
            uri: 'admin/administrators/create',
            action: 'App\\Http\\Controllers\\superAdmin\\AdministratorManagementController@create',
            capability: SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
        );
        $this->assertCanonicalRoute(
            name: 'admin.administrators.store',
            methods: ['POST'],
            uri: 'admin/administrators',
            action: 'App\\Http\\Controllers\\superAdmin\\AdministratorManagementController@store',
            capability: SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
            requiresRecentReauthentication: true,
        );

        $mutations = [
            'setup.resend' => [
                'methods' => ['POST'],
                'uri' => 'admin/administrators/{administrator}/setup/resend',
                'action' => 'resendSetupInvitation',
            ],
            'suspend' => [
                'methods' => ['POST'],
                'uri' => 'admin/administrators/{administrator}/suspend',
                'action' => 'suspend',
            ],
            'deactivate' => [
                'methods' => ['POST'],
                'uri' => 'admin/administrators/{administrator}/deactivate',
                'action' => 'deactivate',
            ],
            'activate' => [
                'methods' => ['POST'],
                'uri' => 'admin/administrators/{administrator}/activate',
                'action' => 'activate',
            ],
            'role.update' => [
                'methods' => ['PATCH'],
                'uri' => 'admin/administrators/{administrator}/role',
                'action' => 'updateRole',
            ],
            'mfa.reset' => [
                'methods' => ['POST'],
                'uri' => 'admin/administrators/{administrator}/mfa/reset',
                'action' => 'resetMfa',
            ],
        ];

        foreach ($mutations as $suffix => $contract) {
            $this->assertCanonicalRoute(
                name: 'admin.administrators.'.$suffix,
                methods: $contract['methods'],
                uri: $contract['uri'],
                action: 'App\\Http\\Controllers\\superAdmin\\AdministratorManagementController@'.$contract['action'],
                capability: $suffix === 'mfa.reset'
                    ? SuperAdmin::CAP_MANAGE_PLATFORM_SECURITY
                    : SuperAdmin::CAP_MANAGE_ADMINISTRATORS,
                requiresRecentReauthentication: true,
            );
        }
    }

    public function test_legacy_administrator_mutations_are_absent_and_page_aliases_are_get_only_redirects(): void
    {
        foreach (['admin.create-admin.store', 'admin.admins.setup.resend', 'admin.admins.suspend', 'admin.admins.deactivate', 'admin.admins.activate', 'admin.admins.role.update', 'admin.admins.mfa.reset'] as $routeName) {
            self::assertNull(RouteFacade::getRoutes()->getByName($routeName), "Retired route still exists: {$routeName}");
        }

        foreach ([
            'admin.admin-management' => 'admin/admin',
            'admin.create-admin' => 'admin/create-admin',
        ] as $routeName => $uri) {
            $route = RouteFacade::getRoutes()->getByName($routeName);

            $this->assertInstanceOf(Route::class, $route, "Missing compatibility route {$routeName}");
            $this->assertSame(['GET', 'HEAD'], $route->methods(), $routeName.' methods');
            $this->assertSame($uri, $route->uri(), $routeName.' URI');
            $this->assertSame('Closure', $route->getActionName(), $routeName.' action');
        }

        $admin = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($admin);

        $this->get('/admin/admin?status=suspended&page=3')
            ->assertRedirect(route('admin.administrators.index', ['status' => 'suspended', 'page' => 3]));
        $this->get('/admin/create-admin?from=legacy')
            ->assertRedirect(route('admin.administrators.create', ['from' => 'legacy']));

        $this->post('/admin/create-admin')->assertStatus(405);
        $this->post('/admin/admins/'.$admin->id.'/suspend')->assertStatus(404);
    }

    public function test_account_intervention_has_one_canonical_shop_and_user_owner(): void
    {
        $this->assertCanonicalRoute(
            name: 'admin.shops.index',
            methods: ['GET', 'HEAD'],
            uri: 'admin/shops',
            action: 'App\\Http\\Controllers\\superAdmin\\RegisteredShopController@index',
            capability: SuperAdmin::CAP_INTERVENE_ACCOUNTS,
        );
        $this->assertCanonicalRoute(
            name: 'admin.shops.show',
            methods: ['GET', 'HEAD'],
            uri: 'admin/shops/{shopOwner}',
            action: 'App\\Http\\Controllers\\superAdmin\\RegisteredShopController@show',
            capability: SuperAdmin::CAP_INTERVENE_ACCOUNTS,
        );
        $this->assertCanonicalRoute(
            name: 'admin.users.index',
            methods: ['GET', 'HEAD'],
            uri: 'admin/users',
            action: 'App\\Http\\Controllers\\superAdmin\\UserInterventionController@index',
            capability: SuperAdmin::CAP_INTERVENE_ACCOUNTS,
        );

        foreach ([
            'shops.suspend' => ['admin/shops/{shopOwner}/suspend', 'suspend', false],
            'shops.reactivate' => ['admin/shops/{shopOwner}/reactivate', 'reactivate', false],
            'shops.archive' => ['admin/shops/{shopOwner}/archive', 'archive', true],
            'shops.restore' => ['admin/shops/{shopOwner}/restore', 'restore', true],
            'users.suspend' => ['admin/users/{user}/suspend', 'suspend', false],
            'users.reactivate' => ['admin/users/{user}/reactivate', 'reactivate', false],
            'users.archive' => ['admin/users/{user}/archive', 'archive', true],
            'users.restore' => ['admin/users/{user}/restore', 'restore', true],
        ] as $suffix => [$uri, $method, $requiresRecent]) {
            $owner = str_starts_with($suffix, 'shops.')
                ? 'RegisteredShopController'
                : 'UserInterventionController';

            $this->assertCanonicalRoute(
                name: 'admin.'.$suffix,
                methods: ['POST'],
                uri: $uri,
                action: 'App\\Http\\Controllers\\superAdmin\\'.$owner.'@'.$method,
                capability: SuperAdmin::CAP_INTERVENE_ACCOUNTS,
                requiresRecentReauthentication: $requiresRecent,
            );
        }

        self::assertNull(RouteFacade::getRoutes()->getByName('admin.shops.activate'));
        self::assertNull(RouteFacade::getRoutes()->getByName('admin.users.activate'));
    }

    public function test_account_intervention_page_aliases_are_get_only_and_preserve_path_and_query(): void
    {
        foreach ([
            'admin.registered-shops' => 'admin/registered-shops',
            'admin.shops.details' => 'admin/shops/{id}/details',
            'admin.user-management' => 'admin/user-management',
            'superAdmin.super-admin-user-management' => 'superAdmin/super-admin-user-management',
        ] as $routeName => $uri) {
            $route = RouteFacade::getRoutes()->getByName($routeName);

            $this->assertInstanceOf(Route::class, $route, "Missing compatibility route {$routeName}");
            $this->assertSame(['GET', 'HEAD'], $route->methods(), $routeName.' methods');
            $this->assertSame($uri, $route->uri(), $routeName.' URI');
            $this->assertSame('Closure', $route->getActionName(), $routeName.' action');
        }

        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($admin);

        $this->get('/admin/registered-shops?lifecycle=archived&page=3')
            ->assertRedirect(route('admin.shops.index', ['lifecycle' => 'archived', 'page' => 3]));
        $this->get('/admin/shops/123/details?tab=documents')
            ->assertRedirect(route('admin.shops.show', [
                'shopOwner' => 123,
                'tab' => 'documents',
            ]));
        $this->get('/admin/user-management?lifecycle=archived')
            ->assertRedirect(route('admin.users.index', ['lifecycle' => 'archived']));
        $this->get('/superAdmin/super-admin-user-management?lifecycle=archived')
            ->assertRedirect(route('admin.users.index', ['lifecycle' => 'archived']));

        foreach ([
            '/admin/shops/123/activate',
            '/admin/users/123/activate',
        ] as $retiredMutation) {
            $this->post($retiredMutation)->assertStatus(404);
        }
    }

    public function test_billing_pages_and_plan_mutations_have_focused_owners(): void
    {
        $this->assertCanonicalRoute(
            name: 'admin.subscriptions.index',
            methods: ['GET', 'HEAD'],
            uri: 'admin/subscriptions',
            action: 'App\\Http\\Controllers\\superAdmin\\SubscriptionManagementController@index',
            capability: SuperAdmin::CAP_MANAGE_PLANS,
        );

        foreach ([
            'admin.plans.store' => ['POST', 'admin/plans', 'store'],
            'admin.plans.update' => ['PUT', 'admin/plans/{premiumPlan}', 'update'],
            'admin.plans.archive' => ['POST', 'admin/plans/{premiumPlan}/archive', 'archive'],
            'admin.plans.reactivate' => ['POST', 'admin/plans/{premiumPlan}/reactivate', 'reactivate'],
        ] as $name => [$method, $uri, $action]) {
            $this->assertCanonicalRoute(
                name: $name,
                methods: [$method],
                uri: $uri,
                action: 'App\\Http\\Controllers\\superAdmin\\PremiumPlanController@'.$action,
                capability: SuperAdmin::CAP_MANAGE_PLANS,
            );
        }

        foreach ([
            'admin.premium-plans.store',
            'admin.premium-plans.update',
            'admin.premium-plans.archive',
            'admin.premium-plans.reactivate',
        ] as $retiredRoute) {
            self::assertNull(RouteFacade::getRoutes()->getByName($retiredRoute), $retiredRoute);
        }

        foreach ([
            'admin.subscriptions.cancel' => 'cancel',
            'admin.subscriptions.legacy-correction' => 'legacyCorrection',
            'admin.subscription-payments.refunds.store' => 'refund',
        ] as $name => $action) {
            $route = RouteFacade::getRoutes()->getByName($name);

            $this->assertInstanceOf(Route::class, $route, $name);
            $this->assertSame(
                'App\\Http\\Controllers\\superAdmin\\SubscriptionInterventionController@'.$action,
                $route->getActionName(),
                $name.' owner',
            );
        }
    }

    public function test_subscription_management_page_is_a_get_only_compatibility_redirect(): void
    {
        $route = RouteFacade::getRoutes()->getByName('admin.subscription-management');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame('admin/subscription-management', $route->uri());
        $this->assertSame('Closure', $route->getActionName());

        $admin = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($admin);

        $this->get('/admin/subscription-management?status=active&page=2')
            ->assertRedirect(route('admin.subscriptions.index', [
                'status' => 'active',
                'page' => 2,
            ]));

        $this->post('/admin/subscription-management')->assertStatus(405);
    }

    public function test_registration_and_flagged_account_routes_have_canonical_owners(): void
    {
        $this->assertCanonicalRoute(
            name: 'admin.registrations.index',
            methods: ['GET', 'HEAD'],
            uri: 'admin/registrations',
            action: 'App\\Http\\Controllers\\superAdmin\\ShopOwnerRegistrationViewController@index',
            capability: SuperAdmin::CAP_REVIEW_REGISTRATIONS,
        );

        foreach ([
            'admin.registrations.approve' => ['admin/registrations/{shopOwner}/approve', 'approve'],
            'admin.registrations.reject' => ['admin/registrations/{shopOwner}/reject', 'reject'],
        ] as $name => [$uri, $action]) {
            $this->assertCanonicalRoute(
                name: $name,
                methods: ['POST'],
                uri: $uri,
                action: 'App\\Http\\Controllers\\superAdmin\\ShopOwnerRegistrationViewController@'.$action,
                capability: SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            );
        }

        $this->assertCanonicalRoute(
            name: 'admin.flagged-accounts.index',
            methods: ['GET', 'HEAD'],
            uri: 'admin/flagged-accounts',
            action: 'App\\Http\\Controllers\\superAdmin\\FlaggedAccountsController@index',
            capability: SuperAdmin::CAP_MODERATE_REPORTS,
        );

        foreach ([
            'mark-reviewed' => 'markReviewed',
            'dismiss' => 'dismiss',
            'ban' => 'ban',
        ] as $suffix => $action) {
            $this->assertCanonicalRoute(
                name: 'admin.flagged-accounts.'.$suffix,
                methods: ['POST'],
                uri: 'admin/flagged-accounts/{id}/'.$suffix,
                action: 'App\\Http\\Controllers\\superAdmin\\FlaggedAccountsController@'.$action,
                capability: SuperAdmin::CAP_MODERATE_REPORTS,
            );
        }

        foreach ([
            'admin.shop-owner-approve',
            'admin.shop-owner-reject',
            'superAdmin.flagged-accounts.mark-reviewed',
            'superAdmin.flagged-accounts.dismiss',
            'superAdmin.flagged-accounts.ban',
        ] as $retiredRoute) {
            self::assertNull(RouteFacade::getRoutes()->getByName($retiredRoute), $retiredRoute);
        }

        $oldMutationRoutes = collect(RouteFacade::getRoutes())
            ->filter(fn (Route $route): bool => in_array('POST', $route->methods(), true))
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'admin/shop-owner-registration/')
                || str_starts_with($route->uri(), 'superAdmin/flagged-accounts/'));

        $this->assertCount(0, $oldMutationRoutes);
    }

    public function test_registration_and_flagged_account_legacy_pages_are_get_only_redirects(): void
    {
        foreach ([
            'admin.shop-owner-registration-view' => 'admin/shop-owner-registration-view',
            'superAdmin.shop-owner-registration-view' => 'superAdmin/shop-owner-registration-view',
            'superAdmin.flagged-accounts' => 'superAdmin/flagged-accounts',
        ] as $routeName => $uri) {
            $routes = collect(RouteFacade::getRoutes())
                ->filter(fn (Route $route): bool => $route->getName() === $routeName);

            $this->assertNotEmpty($routes, "Missing compatibility route {$routeName}");
            foreach ($routes as $route) {
                $this->assertSame(['GET', 'HEAD'], $route->methods(), $routeName.' methods');
                $this->assertSame($uri, $route->uri(), $routeName.' URI');
                $this->assertSame('Closure', $route->getActionName(), $routeName.' action');
            }
        }

        $admin = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($admin);

        $this->get('/admin/shop-owner-registration-view?status=pending&page=2')
            ->assertRedirect(route('admin.registrations.index', [
                'status' => 'pending',
                'page' => 2,
            ]));
        $this->get('/superAdmin/shop-owner-registration-view?status=approved')
            ->assertRedirect(route('admin.registrations.index', ['status' => 'approved']));
        $this->get('/superAdmin/flagged-accounts?status=pending')
            ->assertRedirect(route('admin.flagged-accounts.index', ['status' => 'pending']));

        $this->post('/admin/shop-owner-registration-view')->assertStatus(405);
        $this->post('/superAdmin/flagged-accounts')->assertStatus(405);
    }

    public function test_legacy_shop_registration_mutations_are_absent_and_shop_register_is_a_get_redirect(): void
    {
        foreach (['api/shop/register', 'api/shop/register-full', 'shop/register-full'] as $uri) {
            $routes = collect(RouteFacade::getRoutes())
                ->filter(fn (Route $route): bool => $route->uri() === $uri)
                ->filter(fn (Route $route): bool => in_array('POST', $route->methods(), true));

            $this->assertCount(0, $routes, "Retired registration mutation still exists: {$uri}");
        }

        self::assertStringNotContainsString(
            'ShopRegistrationController',
            collect(RouteFacade::getRoutes())
                ->map(fn (Route $route): string => $route->getActionName())
                ->implode('|'),
        );

        $route = RouteFacade::getRoutes()->getByName('shop.register.form');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame('shop/register', $route->uri());
        $this->assertSame('Closure', $route->getActionName());

        $this->get('/shop/register?source=legacy&page=2')
            ->assertRedirect(route('shop-owner-register', [
                'source' => 'legacy',
                'page' => 2,
            ]));

        $this->post('/shop/register')->assertStatus(405);
        $this->post('/api/shop/register')->assertStatus(404);
        $this->post('/api/shop/register-full')->assertStatus(404);
        $this->post('/shop/register-full')->assertStatus(404);
    }

    /**
     * @param array<int, string> $methods
     */
    private function assertCanonicalRoute(
        string $name,
        array $methods,
        string $uri,
        string $action,
        string $capability,
        bool $requiresRecentReauthentication = false,
    ): void {
        $route = RouteFacade::getRoutes()->getByName($name);

        $this->assertInstanceOf(Route::class, $route, "Missing route {$name}");
        $this->assertSame($methods, $route->methods(), $name.' methods');
        $this->assertSame($uri, $route->uri(), $name.' URI');
        $this->assertSame($action, $route->getActionName(), $name.' owner');
        $this->assertContains('super_admin.auth', $route->middleware(), $name);
        $this->assertContains('privileged.active', $route->middleware(), $name);
        $this->assertContains('privileged.mfa', $route->middleware(), $name);
        $this->assertContains('privileged.capability:'.$capability, $route->middleware(), $name);

        if ($requiresRecentReauthentication) {
            $this->assertContains('privileged.recent', $route->middleware(), $name);
        }

        $sameSemanticRoutes = collect(RouteFacade::getRoutes())
            ->filter(fn (Route $candidate): bool => $candidate->uri() === $uri)
            ->filter(fn (Route $candidate): bool => $candidate->methods() === $methods);

        $this->assertCount(1, $sameSemanticRoutes, $name.' must have one registered route');
    }
}
