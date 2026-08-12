<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\PrivilegedSecurityToken;
use App\Models\PrivilegedSession;
use App\Models\SuperAdmin;
use App\Services\AdministratorIdentityService;
use App\Services\PrivilegedSessionService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class AdministratorIdentityLifecycleTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_self_suspend_deactivate_role_and_mfa_reset_are_denied(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($admin);
        $this->markRecentlyReauthenticated($admin);

        $this->postJson("/admin/admins/{$admin->id}/suspend")->assertForbidden();
        $this->postJson("/admin/admins/{$admin->id}/deactivate")->assertForbidden();
        $this->patchJson("/admin/admins/{$admin->id}/role", ['role' => SuperAdmin::ROLE_ADMIN])
            ->assertForbidden();
        $this->postJson("/admin/admins/{$admin->id}/mfa/reset")->assertForbidden();
    }

    public function test_regular_admin_is_denied_platform_identity_mutations(): void
    {
        $actor = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $target = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($actor);
        $this->markRecentlyReauthenticated($actor);

        $this->postJson("/admin/admins/{$target->id}/suspend")->assertForbidden();
        $this->postJson("/admin/admins/{$target->id}/deactivate")->assertForbidden();
        $this->patchJson("/admin/admins/{$target->id}/role", ['role' => SuperAdmin::ROLE_SUPER_ADMIN])
            ->assertForbidden();
        $this->postJson("/admin/admins/{$target->id}/mfa/reset")->assertForbidden();
    }

    public function test_suspended_target_can_be_activated_without_a_setup_token(): void
    {
        $actor = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $target = SuperAdmin::factory()->admin()->mfaEnrolled()->suspended()->create();
        $version = (int) $target->security_version;
        $this->actingAsCompletedPrivileged($actor);
        $this->markRecentlyReauthenticated($actor);

        $this->postJson("/admin/admins/{$target->id}/activate")
            ->assertOk()
            ->assertJson(['status' => SuperAdmin::STATUS_ACTIVE]);

        self::assertSame(SuperAdmin::STATUS_ACTIVE, $target->fresh()?->status);
        self::assertSame($version + 1, (int) $target->fresh()?->security_version);
        self::assertDatabaseCount('privileged_security_tokens', 0);
    }

    public function test_inactive_target_returns_to_setup_with_a_fresh_invitation(): void
    {
        config(['queue.default' => 'database']);
        $actor = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $target = SuperAdmin::factory()->admin()->mfaEnrolled()->inactive()->create();
        $this->actingAsCompletedPrivileged($actor);
        $this->markRecentlyReauthenticated($actor);

        $this->postJson("/admin/admins/{$target->id}/activate")
            ->assertOk()
            ->assertJson(['status' => SuperAdmin::STATUS_PENDING_SETUP]);

        $fresh = $target->fresh();
        self::assertSame(SuperAdmin::STATUS_PENDING_SETUP, $fresh?->status);
        self::assertFalse($fresh?->hasCompletedMfaSetup());
        self::assertDatabaseHas('privileged_security_tokens', [
            'super_admin_id' => $target->id,
            'purpose' => PrivilegedSecurityToken::PURPOSE_SETUP,
            'used_at' => null,
        ]);
        self::assertSame(1, DB::table('jobs')->count());
    }

    public function test_role_updates_are_allowlisted_and_increment_security_version(): void
    {
        $actor = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $target = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($actor);
        $this->markRecentlyReauthenticated($actor);

        $this->patchJson("/admin/admins/{$target->id}/role", ['role' => 'platform_owner'])
            ->assertUnprocessable();

        $oldVersion = (int) $target->security_version;
        $this->patchJson("/admin/admins/{$target->id}/role", ['role' => SuperAdmin::ROLE_SUPER_ADMIN])
            ->assertOk()
            ->assertJson(['role' => SuperAdmin::ROLE_SUPER_ADMIN]);

        self::assertSame(SuperAdmin::ROLE_SUPER_ADMIN, $target->fresh()?->role);
        self::assertSame($oldVersion + 1, (int) $target->fresh()?->security_version);
    }

    public function test_platform_mfa_reset_preserves_target_status_and_invalidates_target_sessions(): void
    {
        $actor = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $target = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $targetRequest = $this->requestWithSessionId('identity-target-session');
        app(PrivilegedSessionService::class)->establish($targetRequest, $target);
        $oldVersion = (int) $target->security_version;
        $this->actingAsCompletedPrivileged($actor);
        $this->markRecentlyReauthenticated($actor);

        $this->postJson("/admin/admins/{$target->id}/mfa/reset")
            ->assertOk()
            ->assertJson(['status' => SuperAdmin::STATUS_ACTIVE]);

        $fresh = $target->fresh();
        self::assertSame(SuperAdmin::STATUS_ACTIVE, $fresh?->status);
        self::assertFalse($fresh?->hasCompletedMfaSetup());
        self::assertSame($oldVersion + 1, (int) $fresh?->security_version);
        self::assertDatabaseMissing('privileged_sessions', [
            'session_id' => $this->sessionKey('identity-target-session'),
        ]);
    }

    public function test_own_mfa_reset_reenters_setup_and_invalidates_the_current_complete_session(): void
    {
        $actor = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($actor);
        $this->markRecentlyReauthenticated($actor);

        $this->postJson('/admin/security/mfa/reset')
            ->assertOk()
            ->assertJson(['status' => SuperAdmin::STATUS_ACTIVE]);

        $fresh = $actor->fresh();
        self::assertFalse($fresh?->hasCompletedMfaSetup());
        self::assertSame('setup', session('privileged_auth_stage'));
        self::assertSame($actor->id, session('privileged_super_admin_id'));
        self::assertSame($fresh?->security_version, session('privileged_security_version'));
        self::assertSame(0, PrivilegedSession::query()->where('super_admin_id', $actor->id)->count());
        self::assertNull(session('privileged_reauthenticated_at'));
        self::assertNotNull(auth('super_admin')->user());
    }

    public function test_own_mfa_reset_is_rejected_for_the_final_enrolled_super_admin(): void
    {
        $actor = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($actor);
        $this->markRecentlyReauthenticated($actor);

        $this->postJson('/admin/security/mfa/reset')
            ->assertUnprocessable();

        self::assertTrue($actor->fresh()?->hasCompletedMfaSetup());
    }

    public function test_final_invariant_counts_empty_recovery_arrays_but_not_malformed_mfa(): void
    {
        $actor = SuperAdmin::factory()->superAdmin()->activeWithoutMfa()->create();
        $keeper = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $keeper->forceFill(['mfa_recovery_codes' => []])->save();
        $malformed = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $malformed->forceFill(['mfa_secret' => null])->save();
        $service = app(AdministratorIdentityService::class);
        $request = Request::create('/admin/admins', 'POST');

        $service->suspend($request, $actor, $malformed->id);
        self::assertSame(SuperAdmin::STATUS_SUSPENDED, $malformed->fresh()?->status);

        $this->expectException(AuthorizationException::class);
        $service->suspend($request, $actor, $keeper->id);
    }

    public function test_bootstrap_pending_account_does_not_fail_the_final_invariant_before_initial_setup(): void
    {
        $actor = SuperAdmin::factory()->superAdmin()->activeWithoutMfa()->create();
        $pending = SuperAdmin::factory()->pendingSetup()->create();
        $pending->forceFill(['bootstrap_marker' => 'platform'])->save();
        $target = SuperAdmin::factory()->admin()->activeWithoutMfa()->create();
        $service = app(AdministratorIdentityService::class);

        $service->suspend(Request::create('/admin/admins', 'POST'), $actor, $target->id);

        self::assertSame(SuperAdmin::STATUS_SUSPENDED, $target->fresh()?->status);
    }

    public function test_identity_audit_failure_rolls_back_status_version_and_sessions(): void
    {
        $actor = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $target = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        app(PrivilegedSessionService::class)->establish($this->requestWithSessionId('identity-audit-target'), $target);
        $oldVersion = (int) $target->security_version;
        $this->actingAsCompletedPrivileged($actor);
        $this->markRecentlyReauthenticated($actor);
        $this->mock(\App\Services\PrivilegedAudit::class, function ($mock): void {
            $mock->shouldReceive('privilegedAdministratorSuspended')
                ->once()
                ->andThrow(new \RuntimeException('audit unavailable'));
        });

        $this->postJson("/admin/admins/{$target->id}/suspend")
            ->assertUnprocessable();

        self::assertSame(SuperAdmin::STATUS_ACTIVE, $target->fresh()?->status);
        self::assertSame($oldVersion, (int) $target->fresh()?->security_version);
        self::assertDatabaseHas('privileged_sessions', [
            'session_id' => $this->sessionKey('identity-audit-target'),
        ]);
    }

    private function markRecentlyReauthenticated(SuperAdmin $admin): void
    {
        session()->put([
            'privileged_reauthenticated_at' => now()->timestamp,
            'privileged_reauthenticated_security_version' => (int) $admin->security_version,
        ]);
        session()->save();
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    }

    private function requestWithSessionId(string $sessionId): Request
    {
        $store = new Store('testing', new ArraySessionHandler(120));
        $store->setId($this->sessionKey($sessionId));
        $store->start();

        $request = Request::create('/admin');
        $request->setLaravelSession($store);

        return $request;
    }

    private function sessionKey(string $label): string
    {
        return str_pad(str_replace('-', '', $label), 40, '0');
    }
}
