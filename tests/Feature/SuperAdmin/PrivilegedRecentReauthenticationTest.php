<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\PrivilegedSession;
use App\Models\SuperAdmin;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class PrivilegedRecentReauthenticationTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'super_admin.auth', 'privileged.active', 'privileged.mfa', 'privileged.recent'])
            ->get('/admin/__tests/privileged-reauth', static fn () => response()->json(['ok' => true]))
            ->name('tests.privileged.reauth.recent');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_current_password_and_newer_totp_create_a_version_bound_recent_grant(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create([
            'password' => 'Current-Password1!',
        ]);
        $this->actingAsCompletedPrivileged($admin);
        $code = $this->totpCode($admin);

        $this->postJson('/admin/reauthenticate', [
            'password' => 'Current-Password1!',
            'code' => $code,
            'intended' => '/admin/security',
        ])->assertOk()->assertJson([
            'reauthenticated' => true,
            'redirect_to' => '/admin/security',
        ]);

        self::assertIsInt(session('privileged_reauthenticated_at'));
        self::assertSame((int) $admin->security_version, session('privileged_reauthenticated_security_version'));
        self::assertNotNull($admin->fresh()?->mfa_last_used_timestep);
    }

    public function test_external_intended_urls_are_replaced_with_a_local_security_destination(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create([
            'password' => 'Current-Password1!',
        ]);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson('/admin/reauthenticate', [
            'password' => 'Current-Password1!',
            'code' => $this->totpCode($admin),
            'intended' => 'https://evil.example.test/admin/security',
        ])->assertOk()->assertJsonPath('redirect_to', '/admin/security');
    }

    public function test_password_only_and_totp_only_attempts_fail_without_a_recent_grant(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create([
            'password' => 'Current-Password1!',
        ]);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson('/admin/reauthenticate', [
            'password' => 'Current-Password1!',
        ])->assertUnprocessable();
        $this->postJson('/admin/reauthenticate', [
            'code' => $this->totpCode($admin),
        ])->assertUnprocessable();

        self::assertNull(session('privileged_reauthenticated_at'));
    }

    public function test_recovery_codes_cannot_satisfy_recent_reauthentication(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create([
            'password' => 'Current-Password1!',
        ]);
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson('/admin/reauthenticate', [
            'password' => 'Current-Password1!',
            'code' => 'factory-recovery-1',
        ])->assertUnprocessable()
            ->assertJson(['message' => 'Reauthentication failed.']);

        self::assertNull(session('privileged_reauthenticated_at'));
    }

    public function test_wrong_password_and_replayed_totp_fail_generically(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create([
            'password' => 'Current-Password1!',
        ]);
        $this->actingAsCompletedPrivileged($admin);
        $code = $this->totpCode($admin);

        $this->postJson('/admin/reauthenticate', [
            'password' => 'Wrong-Password1!',
            'code' => $code,
        ])->assertUnprocessable()
            ->assertJson(['message' => 'Reauthentication failed.']);

        $this->postJson('/admin/reauthenticate', [
            'password' => 'Current-Password1!',
            'code' => $code,
        ])->assertOk();

        $this->postJson('/admin/reauthenticate', [
            'password' => 'Current-Password1!',
            'code' => $code,
        ])->assertUnprocessable()
            ->assertJson(['message' => 'Reauthentication failed.']);
    }

    public function test_reauthentication_attempts_are_throttled_by_session_and_ip(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create([
            'password' => 'Current-Password1!',
        ]);
        $this->actingAsCompletedPrivileged($admin);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/admin/reauthenticate', [
                'password' => 'Wrong-Password1!',
                'code' => '000000',
            ])->assertUnprocessable();
        }

        $this->postJson('/admin/reauthenticate', [
            'password' => 'Wrong-Password1!',
            'code' => '000000',
        ])->assertTooManyRequests();
    }

    public function test_stale_or_version_mismatched_grants_are_cleared_by_recent_middleware(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($admin);
        session()->put([
            'privileged_reauthenticated_at' => now()->subMinutes(16)->timestamp,
            'privileged_reauthenticated_security_version' => (int) $admin->security_version,
        ]);
        session()->save();

        $this->postJson('/admin/security/password', [
            'password' => 'New-Password1!',
            'password_confirmation' => 'New-Password1!',
        ])->assertStatus(423);
        self::assertNull(session('privileged_reauthenticated_at'));
        self::assertNull(session('privileged_reauthenticated_security_version'));

        session()->put([
            'privileged_reauthenticated_at' => now()->timestamp,
            'privileged_reauthenticated_security_version' => (int) $admin->security_version,
        ]);
        session()->save();
        $admin->forceFill(['security_version' => (int) $admin->security_version + 1])->save();
        session()->put('privileged_security_version', (int) $admin->fresh()?->security_version);
        session()->save();
        PrivilegedSession::query()
            ->where('super_admin_id', $admin->id)
            ->update(['security_version' => (int) $admin->fresh()?->security_version]);

        $this->postJson('/admin/security/password', [
            'password' => 'New-Password1!',
            'password_confirmation' => 'New-Password1!',
        ])->assertStatus(423);
        self::assertNull(session('privileged_reauthenticated_at'));
        self::assertNull(session('privileged_reauthenticated_security_version'));
    }

    public function test_recent_middleware_preserves_a_safe_destination_for_deliberate_retry(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($admin);

        $this->get('/admin/__tests/privileged-reauth')->assertRedirect('/admin/reauthenticate');
        self::assertSame('/admin/__tests/privileged-reauth', session('privileged_reauth_intended'));

        $reauthenticationPage = $this->get('/admin/reauthenticate');
        self::assertSame(200, $reauthenticationPage->status(), $reauthenticationPage->getContent());
        $reauthenticationPage->assertInertia(fn (Assert $page) => $page
            ->component('superAdmin/Auth/PrivilegedReauthenticate')
            ->where('intended', '/admin/__tests/privileged-reauth'));

        self::assertNull(session('privileged_reauth_intended'));
    }

    public function test_reauthentication_audit_failure_rolls_back_totp_consumption_and_grant(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create([
            'password' => 'Current-Password1!',
        ]);
        $this->actingAsCompletedPrivileged($admin);
        $this->mock(\App\Services\PrivilegedAudit::class, function ($mock): void {
            $mock->shouldReceive('privilegedReauthenticationSucceeded')
                ->once()
                ->andThrow(new \RuntimeException('audit unavailable'));
        });

        $this->postJson('/admin/reauthenticate', [
            'password' => 'Current-Password1!',
            'code' => $this->totpCode($admin),
        ])->assertUnprocessable()
            ->assertJson(['message' => 'Reauthentication failed.']);

        self::assertNull(session('privileged_reauthenticated_at'));
        self::assertNull($admin->fresh()?->mfa_last_used_timestep);
    }

    private function totpCode(SuperAdmin $admin): string
    {
        return (new Google2FA())->oathTotp(
            (string) $admin->mfa_secret,
            intdiv(now()->timestamp, 30),
        );
    }
}
