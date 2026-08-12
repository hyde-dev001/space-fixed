<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\PrivilegedSession;
use App\Models\SuperAdmin;
use App\Services\PrivilegedMfaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class PrivilegedAuthenticationFlowTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_login_failures_are_generic_across_unknown_and_non_active_accounts(): void
    {
        $wrongPasswordAdmin = $this->admin();
        $suspended = SuperAdmin::factory()->mfaEnrolled()->suspended()->create([
            'email' => 'suspended@example.test',
        ]);
        $inactive = SuperAdmin::factory()->mfaEnrolled()->inactive()->create([
            'email' => 'inactive@example.test',
        ]);
        $pending = SuperAdmin::factory()->pendingSetup()->create([
            'email' => 'pending@example.test',
        ]);

        $cases = [
            ['unknown@example.test', 'TestPassword1!'],
            [$wrongPasswordAdmin->email, 'WrongPassword1!'],
            [$suspended->email, 'TestPassword1!'],
            [$inactive->email, 'TestPassword1!'],
            [$pending->email, 'TestPassword1!'],
        ];

        $messages = [];
        foreach ($cases as [$email, $password]) {
            $response = $this->post('/admin/login', compact('email', 'password'));

            $response->assertRedirect(route('admin.login'))
                ->assertSessionHasErrors('email');
            $messages[] = session('errors')->get('email')[0];
        }

        self::assertCount(1, array_unique($messages));
    }

    public function test_active_without_mfa_enters_setup_stage_without_a_privileged_session(): void
    {
        $admin = SuperAdmin::factory()->activeWithoutMfa()->create([
            'email' => 'setup-required@example.test',
            'password' => 'TestPassword1!',
        ]);

        $response = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'TestPassword1!',
            'remember' => true,
        ]);

        $response->assertRedirect('/admin/mfa/setup');
        self::assertSame('setup', session('privileged_auth_stage'));
        self::assertSame($admin->id, session('privileged_super_admin_id'));
        self::assertDatabaseCount('privileged_sessions', 0);
        self::assertFalse($this->hasRememberCookie($response));
    }

    public function test_active_mfa_account_enters_challenge_stage_and_cannot_operate(): void
    {
        $admin = $this->admin(['email' => 'challenge@example.test']);

        $response = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'TestPassword1!',
            'remember' => true,
        ]);

        $response->assertRedirect('/admin/mfa/challenge');
        self::assertSame('mfa_challenge', session('privileged_auth_stage'));
        self::assertSame($admin->id, session('privileged_super_admin_id'));
        self::assertDatabaseCount('privileged_sessions', 0);
        self::assertFalse($this->hasRememberCookie($response));

        $this->syncSessionCookie($response);
        $this->get('/admin/system-monitoring')->assertRedirect(route('admin.login'));
    }

    public function test_newer_totp_completes_login_updates_last_login_and_honors_remember_me(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 12, 12, 0, 15, 'UTC'));
        $admin = $this->admin(['email' => 'totp@example.test']);
        $login = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'TestPassword1!',
            'remember' => true,
        ]);
        $this->syncSessionCookie($login);
        $passwordStageSessionId = session()->getId();

        $timestep = intdiv(Carbon::now()->timestamp, 30);
        $code = (new Google2FA())->oathTotp($admin->mfa_secret, $timestep);
        $response = $this->post('/admin/mfa/challenge', ['code' => $code]);

        $response->assertRedirect(route('admin.system-monitoring'));
        $fresh = $admin->fresh();
        self::assertNotNull($fresh?->last_login_at);
        self::assertSame('127.0.0.1', $fresh?->last_login_ip);
        self::assertSame($timestep, $fresh?->mfa_last_used_timestep);
        $privilegedSession = PrivilegedSession::query()
            ->where('super_admin_id', $admin->id)
            ->firstOrFail();
        self::assertNotSame($passwordStageSessionId, $privilegedSession->session_id);
        self::assertSame((int) $admin->security_version, (int) $privilegedSession->security_version);
        self::assertTrue($this->hasRememberCookie($response));
    }

    public function test_malformed_and_replayed_totp_do_not_complete_the_session(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 12, 12, 0, 15, 'UTC'));
        $admin = $this->admin(['email' => 'replay@example.test']);
        $login = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'TestPassword1!',
        ]);
        $this->syncSessionCookie($login);

        $this->post('/admin/mfa/challenge', ['code' => 'not-a-code'])
            ->assertRedirect('/admin/mfa/challenge')
            ->assertSessionHasErrors('code');
        self::assertSame('mfa_challenge', session('privileged_auth_stage'));
        self::assertDatabaseCount('privileged_sessions', 0);

        $timestep = intdiv(Carbon::now()->timestamp, 30);
        $code = (new Google2FA())->oathTotp($admin->mfa_secret, $timestep);
        $firstSuccess = $this->post('/admin/mfa/challenge', ['code' => $code]);
        $firstSuccess
            ->assertRedirect(route('admin.system-monitoring'));

        $this->syncSessionCookie($firstSuccess);
        $this->post('/admin/logout');
        $secondLogin = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'TestPassword1!',
        ]);
        $this->syncSessionCookie($secondLogin);

        $this->post('/admin/mfa/challenge', ['code' => $code])
            ->assertRedirect('/admin/mfa/challenge')
            ->assertSessionHasErrors('code');
        self::assertDatabaseCount('privileged_sessions', 0);
    }

    public function test_recovery_code_is_single_use_and_another_code_remains_usable(): void
    {
        $mfa = app(PrivilegedMfaService::class);
        $codes = $mfa->generateRecoveryCodes();
        $admin = $this->admin(['email' => 'recovery@example.test']);
        $admin->forceFill(['mfa_recovery_codes' => $mfa->hashRecoveryCodes($codes)])->save();

        $login = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'TestPassword1!',
        ]);
        $this->syncSessionCookie($login);
        $this->post('/admin/mfa/challenge', ['code' => $codes[0]])
            ->assertRedirect(route('admin.system-monitoring'));
        self::assertFalse(collect($admin->fresh()->mfa_recovery_codes ?? [])
            ->contains(fn (string $hash): bool => Hash::check(str_replace('-', '', $codes[0]), $hash)));

        $logout = $this->post('/admin/logout');
        $this->syncSessionCookie($logout);
        $secondLogin = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'TestPassword1!',
        ]);
        $this->syncSessionCookie($secondLogin);

        $this->post('/admin/mfa/challenge', ['code' => $codes[0]])
            ->assertRedirect('/admin/mfa/challenge')
            ->assertSessionHasErrors('code');
        $this->post('/admin/mfa/challenge', ['code' => $codes[1]])
            ->assertRedirect(route('admin.system-monitoring'));
    }

    public function test_login_and_mfa_limiters_use_generic_responses(): void
    {
        $loginEmail = 'rate-limit-login@example.test';
        $mfaEmail = 'rate-limit-mfa@example.test';
        $admin = $this->admin(['email' => $loginEmail]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/admin/login', [
                'email' => strtoupper($loginEmail),
                'password' => 'WrongPassword1!',
            ])->assertRedirect(route('admin.login'));
        }

        $this->post('/admin/login', [
            'email' => $loginEmail,
            'password' => 'WrongPassword1!',
        ])->assertTooManyRequests();

        $mfaAdmin = $this->admin(['email' => $mfaEmail]);
        $login = $this->post('/admin/login', [
            'email' => $mfaAdmin->email,
            'password' => 'TestPassword1!',
        ]);
        $this->syncSessionCookie($login);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/admin/mfa/challenge', ['code' => 'not-a-code'])
                ->assertRedirect('/admin/mfa/challenge');
        }

        $this->post('/admin/mfa/challenge', ['code' => 'not-a-code'])
            ->assertTooManyRequests();
    }

    public function test_logout_removes_registry_invalidates_session_and_rotates_csrf_token(): void
    {
        $admin = $this->admin(['email' => 'logout@example.test']);
        $this->actingAsCompletedPrivileged($admin);
        $sessionId = session()->getId();
        $csrfToken = session()->token();

        $response = $this->post('/admin/logout');

        $response->assertRedirect(route('admin.login'));
        self::assertDatabaseMissing('privileged_sessions', ['session_id' => $sessionId]);
        self::assertNotSame($sessionId, session()->getId());
        self::assertNotSame($csrfToken, session()->token());
    }

    public function test_security_audit_events_never_store_credentials_codes_or_session_ids(): void
    {
        $admin = $this->admin(['email' => 'audit@example.test']);
        $password = 'TestPassword1!';
        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'WrongPassword1!',
        ]);

        $login = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => $password,
        ]);
        $this->syncSessionCookie($login);
        $this->post('/admin/mfa/challenge', ['code' => 'not-a-code']);

        $audit = Activity::query()->where('log_name', 'privileged')->get()->toJson();
        self::assertStringNotContainsString($admin->email, $audit);
        self::assertStringNotContainsString($password, $audit);
        self::assertStringNotContainsString('not-a-code', $audit);
        self::assertStringNotContainsString(session()->getId(), $audit);
    }

    /** @param array<string, mixed> $overrides */
    private function admin(array $overrides = []): SuperAdmin
    {
        return SuperAdmin::factory()->mfaEnrolled()->create(array_merge([
            'password' => 'TestPassword1!',
        ], $overrides));
    }

    private function syncSessionCookie(TestResponse $response): void
    {
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($candidate): bool => $candidate->getName() === config('session.cookie'));

        if ($cookie !== null) {
            $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
    }

    private function hasRememberCookie(TestResponse $response): bool
    {
        return collect($response->headers->getCookies())
            ->contains(fn ($cookie): bool => str_starts_with($cookie->getName(), 'remember_super_admin'));
    }
}
