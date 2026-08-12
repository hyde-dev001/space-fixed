<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\PrivilegedSession;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class PhaseOneRouteSecurityTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'super_admin.auth', 'privileged.active', 'privileged.mfa'])
            ->get('/__tests/privileged-operation', static fn () => response()->json(['ok' => true]))
            ->name('tests.privileged.operation');
        Route::middleware(['web', 'super_admin.auth', 'privileged.active', 'privileged.mfa', 'privileged.recent'])
            ->get('/__tests/privileged-reauth', static fn () => response()->json(['ok' => true]))
            ->name('tests.privileged.reauth');
        Route::middleware(['web', 'privileged.no-store'])
            ->get('/__tests/privileged-security-page', static fn () => response('security'))
            ->name('tests.privileged.security-page');
    }

    public function test_unauthenticated_and_setup_required_sessions_cannot_reach_operations(): void
    {
        $this->get('/__tests/privileged-operation')->assertRedirect('/admin/login');

        $admin = SuperAdmin::factory()->activeWithoutMfa()->create();
        $this->actingAs($admin, 'super_admin');

        $this->get('/__tests/privileged-operation')->assertRedirect('/admin/login');

        session()->put('privileged_auth_stage', 'setup');

        $this->get('/__tests/privileged-operation')->assertRedirect('/admin/mfa/setup');
    }

    public function test_pending_suspended_inactive_and_malformed_mfa_accounts_are_denied(): void
    {
        foreach ([
            SuperAdmin::factory()->pendingSetup()->create(),
            SuperAdmin::factory()->mfaEnrolled()->suspended()->create(),
            SuperAdmin::factory()->mfaEnrolled()->inactive()->create(),
        ] as $admin) {
            $this->actingAsCompletedPrivileged($admin);

            $this->getJson('/__tests/privileged-operation')->assertUnauthorized();
        }

        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $admin->forceFill(['mfa_secret' => null])->save();
        $this->actingAsCompletedPrivileged($admin);
        $this->getJson('/__tests/privileged-operation')->assertUnauthorized();

        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $admin->forceFill(['mfa_recovery_codes' => null])->save();
        $this->actingAsCompletedPrivileged($admin);
        $this->getJson('/__tests/privileged-operation')->assertUnauthorized();
    }

    public function test_empty_exhausted_recovery_codes_remain_mfa_complete(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $admin->forceFill(['mfa_recovery_codes' => []])->save();
        $this->actingAsCompletedPrivileged($admin);

        $this->getJson('/__tests/privileged-operation')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_stale_or_unregistered_complete_sessions_are_denied(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($admin);
        PrivilegedSession::query()->delete();

        $this->getJson('/__tests/privileged-operation')->assertUnauthorized();

        $this->actingAsCompletedPrivileged($admin);
        $admin->forceFill(['security_version' => 2])->save();

        $this->getJson('/__tests/privileged-operation')->assertUnauthorized();
    }

    public function test_recent_reauthentication_is_version_bound_and_no_store_is_explicit(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($admin);

        $this->get('/__tests/privileged-reauth')->assertRedirect('/admin/reauthenticate');
        $this->getJson('/__tests/privileged-reauth')
            ->assertStatus(423)
            ->assertJson(['message' => 'Recent reauthentication required.']);

        session()->put([
            'privileged_reauthenticated_at' => now()->timestamp,
            'privileged_reauthenticated_security_version' => $admin->security_version,
        ]);

        $this->getJson('/__tests/privileged-reauth')->assertOk();
        $this->get('/__tests/privileged-security-page')
            ->assertOk()
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertHeader('Pragma', 'no-cache');
    }

    public function test_operational_privileged_routes_declare_the_security_boundary(): void
    {
        $allowlisted = [
            'admin.login',
            'admin.login.post',
            'admin.logout',
            'admin.setup',
            'admin.setup.exchange',
            'admin.setup.complete',
            'admin.mfa.challenge',
            'admin.mfa.challenge.verify',
            'admin.mfa.setup',
            'admin.mfa.setup.verify',
            'admin.mfa.setup.recovery.acknowledge',
        ];
        $operational = collect(Route::getRoutes()->getRoutes())
            ->filter(function ($route): bool {
                $uri = ltrim($route->uri(), '/');

                return str_starts_with($uri, 'admin/')
                    || str_starts_with($uri, 'superAdmin/')
                    || str_starts_with($uri, 'api/admin/notifications');
            })
            ->reject(fn ($route): bool => in_array($route->getName(), $allowlisted, true));

        self::assertNotEmpty($operational);
        foreach ($operational as $route) {
            self::assertContains('super_admin.auth', $route->middleware(), $route->uri());
            self::assertContains('privileged.active', $route->middleware(), $route->uri());
            self::assertContains('privileged.mfa', $route->middleware(), $route->uri());
        }

        $fixedCapabilities = [
            'admin.system-monitoring' => SuperAdmin::CAP_VIEW_MONITORING,
            'superAdmin.system-monitoring-dashboard' => SuperAdmin::CAP_VIEW_MONITORING,
            'admin.shop-reports' => SuperAdmin::CAP_MODERATE_REPORTS,
            'admin.shop-reports.action' => SuperAdmin::CAP_MODERATE_REPORTS,
            'superAdmin.flagged-accounts' => SuperAdmin::CAP_MODERATE_REPORTS,
            'superAdmin.flagged-accounts.mark-reviewed' => SuperAdmin::CAP_MODERATE_REPORTS,
            'superAdmin.flagged-accounts.dismiss' => SuperAdmin::CAP_MODERATE_REPORTS,
            'superAdmin.flagged-accounts.ban' => SuperAdmin::CAP_MODERATE_REPORTS,
            'admin.suspension-appeals' => SuperAdmin::CAP_VIEW_APPEALS,
            'admin.appeals.approve' => SuperAdmin::CAP_RESOLVE_APPEALS,
            'admin.appeals.reject' => SuperAdmin::CAP_RESOLVE_APPEALS,
            'admin.data-reports' => SuperAdmin::CAP_VIEW_PRIVILEGED_AUDIT,
            'superAdmin.data-report-access' => SuperAdmin::CAP_VIEW_PRIVILEGED_AUDIT,
        ];

        foreach ($fixedCapabilities as $name => $capability) {
            $route = Route::getRoutes()->getByName($name);

            self::assertNotNull($route, $name);
            self::assertContains("privileged.capability:{$capability}", $route->middleware(), $name);
        }
    }
}
