<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\PrivilegedDeliveryType;
use App\Jobs\SendPrivilegedWorkflowMail;
use App\Models\PrivilegedSecurityToken;
use App\Models\PrivilegedSession;
use App\Models\SuperAdmin;
use App\Services\PrivilegedSecurityTokenService;
use App\Services\PrivilegedSessionService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class PrivilegedPasswordRecoveryTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_forgot_password_is_generic_and_only_active_accounts_receive_a_reset_mail(): void
    {
        Queue::fake();
        SuperAdmin::factory()->pendingSetup()->create(['email' => 'pending@example.test']);
        SuperAdmin::factory()->suspended()->create(['email' => 'suspended@example.test']);
        SuperAdmin::factory()->inactive()->create(['email' => 'inactive@example.test']);
        SuperAdmin::factory()->mfaEnrolled()->create(['email' => 'active@example.test']);

        $responses = [];
        foreach ([
            'unknown@example.test',
            'pending@example.test',
            'suspended@example.test',
            'inactive@example.test',
            'active@example.test',
        ] as $email) {
            $response = $this->postJson('/admin/forgot-password', ['email' => strtoupper($email)]);
            $response->assertOk();
            $responses[] = $response->json('message');
        }

        DB::commit();

        self::assertCount(1, array_unique($responses));
        self::assertSame(1, PrivilegedSecurityToken::query()
            ->where('purpose', PrivilegedSecurityToken::PURPOSE_PASSWORD_RESET)
            ->count());
        Queue::assertPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job): bool {
            return $job->deliveryType === PrivilegedDeliveryType::PRIVILEGED_PASSWORD_RESET
                && $job->recipientType === 'super_admin'
                && $job->recipientId === (int) SuperAdmin::query()->where('email', 'active@example.test')->value('id');
        });
    }

    public function test_reset_mailable_is_encrypted_queued_and_places_the_bearer_only_in_the_fragment(): void
    {
        $rawToken = 'reset-fragment-token';
        $mail = new \App\Mail\PrivilegedPasswordResetMail('Admin User', 'admin@example.test', $rawToken);

        self::assertInstanceOf(ShouldQueue::class, $mail);
        self::assertInstanceOf(ShouldBeEncrypted::class, $mail);
        $rendered = $mail->render();
        self::assertStringContainsString('#token='.$rawToken, $rendered);
        self::assertStringNotContainsString('?token='.$rawToken, $rendered);
        self::assertStringNotContainsString('/admin/reset-password/'.$rawToken, $rendered);
    }

    public function test_password_reset_exchange_and_consumption_are_atomic_and_invalidate_all_privileged_sessions(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create([
            'email' => 'reset-target@example.test',
            'password' => 'OldPassword1!',
            'remember_token' => 'remember-before',
        ]);
        $otherRequest = $this->requestWithSessionId('reset-other-session');
        app(PrivilegedSessionService::class)->establish($otherRequest, $admin);
        $issued = app(PrivilegedSecurityTokenService::class)->issue(
            $admin,
            PrivilegedSecurityToken::PURPOSE_PASSWORD_RESET,
            null,
        );
        $rawToken = $issued['raw_token'];
        $oldSecret = $admin->mfa_secret;
        $oldVersion = (int) $admin->security_version;

        $this->get('/admin/reset-password')
            ->assertOk()
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
        $exchange = $this->postJson('/admin/reset-password/exchange', ['token' => $rawToken]);
        $exchange->assertOk()
            ->assertJson(['authorized' => true])
            ->assertJsonStructure(['completion_proof']);
        $completionProof = $exchange->json('completion_proof');
        self::assertIsString($completionProof);
        self::assertNotSame('', $completionProof);
        self::assertNotSame($rawToken, $completionProof);
        self::assertNull(session('privileged_password_reset_authorization'));
        self::assertStringNotContainsString($rawToken, serialize(session()->all()));

        $response = $this->post('/admin/reset-password/complete', [
            'completion_proof' => $completionProof,
            'password' => 'Replacement-Password1!',
            'password_confirmation' => 'Replacement-Password1!',
        ]);
        $response->assertRedirect(route('admin.login'));

        $fresh = $admin->fresh();
        self::assertTrue(Hash::check('Replacement-Password1!', (string) $fresh?->getRawOriginal('password')));
        self::assertFalse(Hash::check('OldPassword1!', (string) $fresh?->getRawOriginal('password')));
        self::assertSame($oldVersion + 1, (int) $fresh?->security_version);
        self::assertNotSame('remember-before', $fresh?->remember_token);
        self::assertNotNull($fresh?->password_changed_at);
        self::assertSame($oldSecret, $fresh?->mfa_secret);
        self::assertNotNull($issued['token']->fresh()?->used_at);
        self::assertDatabaseCount('privileged_sessions', 0);
        self::assertStringNotContainsString($rawToken, $response->headers->get('Location', ''));
    }

    public function test_password_reset_token_is_rejected_after_the_account_leaves_active_state(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $issued = app(PrivilegedSecurityTokenService::class)->issue(
            $admin,
            PrivilegedSecurityToken::PURPOSE_PASSWORD_RESET,
            null,
        );
        $admin->forceFill(['status' => SuperAdmin::STATUS_SUSPENDED])->save();

        $this->postJson('/admin/reset-password/exchange', ['token' => $issued['raw_token']])
            ->assertUnprocessable()
            ->assertJson(['message' => 'The reset link is invalid or expired.']);
        self::assertNull($issued['token']->fresh()?->used_at);
    }

    public function test_failed_password_reset_consumption_does_not_depend_on_exchange_session_authorization(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $issued = app(PrivilegedSecurityTokenService::class)->issue(
            $admin,
            PrivilegedSecurityToken::PURPOSE_PASSWORD_RESET,
            null,
        );

        $exchange = $this->postJson('/admin/reset-password/exchange', ['token' => $issued['raw_token']])
            ->assertOk()
            ->assertJsonStructure(['completion_proof']);
        $completionProof = $exchange->json('completion_proof');
        $admin->forceFill(['status' => SuperAdmin::STATUS_SUSPENDED])->save();

        $this->postJson('/admin/reset-password/complete', [
            'completion_proof' => $completionProof,
            'password' => 'Replacement-Password1!',
            'password_confirmation' => 'Replacement-Password1!',
        ])->assertUnprocessable();

        self::assertNull(session('privileged_password_reset_authorization'));
        self::assertNull($issued['token']->fresh()?->used_at);
    }

    public function test_reset_limiter_normalizes_email_before_counting_attempts(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/admin/forgot-password', [
                'email' => 'RATE-LIMIT@example.test',
            ])->assertOk();
        }

        $this->postJson('/admin/forgot-password', [
            'email' => 'rate-limit@example.test',
        ])->assertTooManyRequests();
    }

    public function test_security_state_is_available_to_both_roles_without_secrets_or_hashes(): void
    {
        foreach ([SuperAdmin::ROLE_ADMIN, SuperAdmin::ROLE_SUPER_ADMIN] as $role) {
            $admin = SuperAdmin::factory()->mfaEnrolled()->create(['role' => $role]);
            $this->actingAsCompletedPrivileged($admin);

            $response = $this->getJson('/admin/security')->assertOk();
            $payload = $response->getContent();
            self::assertStringNotContainsString('mfa_secret', $payload);
            self::assertStringNotContainsString('mfa_recovery_codes', $payload);
            self::assertStringNotContainsString('password', $payload);
            self::assertStringContainsString('recovery_code_count', $payload);
        }
    }

    public function test_password_change_requires_recent_reauthentication_and_preserves_only_the_current_session(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create([
            'password' => 'OldPassword1!',
            'remember_token' => 'remember-before',
        ]);
        $this->actingAsCompletedPrivileged($admin);
        $this->markRecentlyReauthenticated($admin);
        $otherRequest = $this->requestWithSessionId('password-change-other');
        app(PrivilegedSessionService::class)->establish($otherRequest, $admin);
        $oldSecret = $admin->mfa_secret;

        $this->post('/admin/security/password', [
            'password' => 'NewPassword-Change1!',
            'password_confirmation' => 'NewPassword-Change1!',
        ])->assertRedirect(route('admin.security'));

        $fresh = $admin->fresh();
        self::assertTrue(Hash::check('NewPassword-Change1!', (string) $fresh?->getRawOriginal('password')));
        self::assertSame(2, (int) $fresh?->security_version);
        self::assertNotSame('remember-before', $fresh?->remember_token);
        self::assertNotNull($fresh?->password_changed_at);
        self::assertSame($oldSecret, $fresh?->mfa_secret);
        self::assertSame(1, PrivilegedSession::query()->where('super_admin_id', $admin->id)->count());
        self::assertDatabaseMissing('privileged_sessions', ['session_id' => $this->sessionKey('password-change-other')]);
    }

    public function test_password_change_audit_failure_rolls_back_credentials_version_and_sessions(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create([
            'password' => 'OldPassword1!',
            'remember_token' => 'remember-before',
        ]);
        $this->actingAsCompletedPrivileged($admin);
        $this->markRecentlyReauthenticated($admin);
        $this->mock(\App\Services\PrivilegedAudit::class, function ($mock): void {
            $mock->shouldReceive('privilegedPasswordChangeCompleted')
                ->once()
                ->andThrow(new \RuntimeException('audit unavailable'));
        });

        $this->post('/admin/security/password', [
            'password' => 'NewPassword-Change1!',
            'password_confirmation' => 'NewPassword-Change1!',
        ])->assertRedirect(route('admin.security'))
            ->assertSessionHasErrors('error');

        $fresh = $admin->fresh();
        self::assertTrue(Hash::check('OldPassword1!', (string) $fresh?->getRawOriginal('password')));
        self::assertSame(1, (int) $fresh?->security_version);
        self::assertSame('remember-before', $fresh?->remember_token);
        self::assertDatabaseCount('privileged_sessions', 1);
    }

    public function test_recovery_regeneration_returns_plaintext_once_and_requires_acknowledgement(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($admin);
        $this->markRecentlyReauthenticated($admin);
        $oldHashes = $admin->mfa_recovery_codes;

        $response = $this->postJson('/admin/security/recovery/generate')->assertOk();
        $codes = $response->json('recovery_codes');
        $acknowledgementToken = (string) $response->json('acknowledgement_token');
        self::assertCount(8, $codes);
        self::assertNotSame($oldHashes, $admin->fresh()?->mfa_recovery_codes);
        self::assertStringNotContainsString(implode('', $codes), serialize(session()->all()));
        self::assertStringNotContainsString($acknowledgementToken, serialize(session()->all()));
        self::assertStringNotContainsString($acknowledgementToken, Activity::query()->latest('id')->firstOrFail()->properties->toJson());

        $this->postJson('/admin/security/recovery/acknowledge', [
            'token' => $acknowledgementToken,
        ])->assertOk()->assertJson(['acknowledged' => true]);

        $this->postJson('/admin/security/recovery/acknowledge', [
            'token' => $acknowledgementToken,
        ])->assertUnprocessable();
    }

    public function test_lost_recovery_acknowledgement_is_replaced_by_a_new_generation(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($admin);
        $this->markRecentlyReauthenticated($admin);

        $first = $this->postJson('/admin/security/recovery/generate')->assertOk();
        $firstToken = (string) $first->json('acknowledgement_token');
        $second = $this->postJson('/admin/security/recovery/generate')->assertOk();
        $secondToken = (string) $second->json('acknowledgement_token');

        self::assertNotSame($firstToken, $secondToken);
        $this->postJson('/admin/security/recovery/acknowledge', ['token' => $firstToken])
            ->assertUnprocessable();
        $this->postJson('/admin/security/recovery/acknowledge', ['token' => $secondToken])
            ->assertOk();
    }

    public function test_issued_bearer_is_returned_once_but_only_its_hash_is_persisted(): void
    {
        $admin = SuperAdmin::factory()->pendingSetup()->create();
        $service = app(PrivilegedSecurityTokenService::class);

        $issued = $service->issue($admin, PrivilegedSecurityToken::PURPOSE_SETUP, null);
        $rawToken = $issued['raw_token'];
        $record = $issued['token'];
        $authorized = $service->authorize($rawToken, PrivilegedSecurityToken::PURPOSE_SETUP);

        self::assertIsString($rawToken);
        self::assertSame(64, mb_strlen($record->token_hash));
        self::assertSame(hash('sha256', $rawToken), $record->token_hash);
        self::assertArrayNotHasKey('token_hash', $record->toArray());
        self::assertSame([
            'token_id' => $record->id,
            'subject_id' => $admin->id,
            'purpose' => PrivilegedSecurityToken::PURPOSE_SETUP,
            'expires_at' => $record->expires_at->toISOString(),
        ], [
            'token_id' => $authorized['token_id'],
            'subject_id' => $authorized['subject_id'],
            'purpose' => $authorized['purpose'],
            'expires_at' => $authorized['expires_at']->toISOString(),
        ]);
        self::assertArrayNotHasKey('token_hash', $authorized);
    }

    public function test_issuing_a_replacement_invalidates_the_previous_unused_token(): void
    {
        $admin = SuperAdmin::factory()->pendingSetup()->create();
        $service = app(PrivilegedSecurityTokenService::class);
        $first = $service->issue($admin, PrivilegedSecurityToken::PURPOSE_SETUP, null);
        $second = $service->issue($admin, PrivilegedSecurityToken::PURPOSE_SETUP, null);

        try {
            $service->authorize($first['raw_token'], PrivilegedSecurityToken::PURPOSE_SETUP);
            self::fail('The replaced token must be rejected.');
        } catch (\InvalidArgumentException) {
            // Expected.
        }

        self::assertNotNull(PrivilegedSecurityToken::find($first['token']->id)->used_at);
        self::assertSame($admin->id, $service->authorize($second['raw_token'], PrivilegedSecurityToken::PURPOSE_SETUP)['subject_id']);
    }

    public function test_authorized_consumption_marks_the_token_only_after_the_mutation_succeeds(): void
    {
        $admin = SuperAdmin::factory()->pendingSetup()->create();
        $service = app(PrivilegedSecurityTokenService::class);
        $issued = $service->issue($admin, PrivilegedSecurityToken::PURPOSE_SETUP, null);
        $authorization = $service->authorize($issued['raw_token'], PrivilegedSecurityToken::PURPOSE_SETUP);

        $result = $service->consumeAuthorized(
            $authorization['token_id'],
            $authorization['subject_id'],
            $authorization['purpose'],
            function (SuperAdmin $subject): string {
                $subject->forceFill(['password_changed_at' => now()])->save();

                return 'mutated';
            },
        );

        self::assertSame('mutated', $result);
        self::assertNotNull(PrivilegedSecurityToken::find($issued['token']->id)->used_at);
        self::assertNotNull($admin->fresh()->password_changed_at);

        $this->expectException(\InvalidArgumentException::class);
        $service->consume($issued['raw_token'], PrivilegedSecurityToken::PURPOSE_SETUP, static fn (): true => true);
    }

    public function test_authorized_consumption_rejects_changed_setup_state_without_consuming(): void
    {
        $admin = SuperAdmin::factory()->pendingSetup()->create();
        $service = app(PrivilegedSecurityTokenService::class);
        $issued = $service->issue($admin, PrivilegedSecurityToken::PURPOSE_SETUP, null);
        $authorization = $service->authorize($issued['raw_token'], PrivilegedSecurityToken::PURPOSE_SETUP);
        $admin->forceFill(['status' => SuperAdmin::STATUS_ACTIVE])->save();

        try {
            $service->consumeAuthorized(
                $authorization['token_id'],
                $authorization['subject_id'],
                $authorization['purpose'],
                static fn (): true => true,
            );
            self::fail('A setup token must be rejected after the subject leaves setup state.');
        } catch (\InvalidArgumentException) {
            // Expected.
        }

        self::assertNull(PrivilegedSecurityToken::find($issued['token']->id)->used_at);
    }

    public function test_privileged_session_registry_validates_stage_identity_and_version(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $request = $this->requestWithSessionId('privileged-session-a');
        $service = app(PrivilegedSessionService::class);

        $service->establish($request, $admin);

        self::assertTrue($service->validate($request, $admin));
        self::assertSame('complete', $request->session()->get('privileged_auth_stage'));
        self::assertSame($admin->security_version, $request->session()->get('privileged_security_version'));
        self::assertTrue(PrivilegedSession::query()->whereKey($this->sessionKey('privileged-session-a'))->exists());

        $admin->forceFill(['security_version' => 2])->save();

        self::assertFalse($service->validate($request, $admin->fresh()));
    }

    public function test_invalidate_all_removes_only_mapped_privileged_sessions_without_changing_authority(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $other = SuperAdmin::factory()->mfaEnrolled()->create();
        $service = app(PrivilegedSessionService::class);
        $service->establish($this->requestWithSessionId('privileged-session-a'), $admin);
        $service->establish($this->requestWithSessionId('privileged-session-b'), $admin);
        $service->establish($this->requestWithSessionId('ordinary-session'), $other);

        $service->invalidateAllAfterCommit($admin);

        self::assertSame(1, PrivilegedSession::query()->count());
        self::assertSame($this->sessionKey('ordinary-session'), PrivilegedSession::query()->value('session_id'));
        self::assertSame(1, $admin->fresh()->security_version);
    }

    public function test_invalidate_others_preserves_and_re_registers_only_the_current_session(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $service = app(PrivilegedSessionService::class);
        $currentRequest = $this->requestWithSessionId('privileged-current');
        $service->establish($currentRequest, $admin);
        $service->establish($this->requestWithSessionId('privileged-other'), $admin);
        $admin->forceFill(['security_version' => 2])->save();

        $service->invalidateOthersAfterCommit($currentRequest, $admin->fresh());

        self::assertSame([$this->sessionKey('privileged-current')], PrivilegedSession::query()->pluck('session_id')->all());
        self::assertSame(2, PrivilegedSession::query()->value('security_version'));
        self::assertTrue($service->validate($currentRequest, $admin->fresh()));
    }

    public function test_database_session_cleanup_uses_only_mapped_ids_and_keeps_ordinary_sessions(): void
    {
        config(['session.driver' => 'database']);
        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $service = app(PrivilegedSessionService::class);
        $service->establish($this->requestWithSessionId('privileged-db-session'), $admin);
        DB::table('sessions')->insert([
            ['id' => $this->sessionKey('privileged-db-session'), 'payload' => 'privileged', 'last_activity' => now()->timestamp],
            ['id' => $this->sessionKey('ordinary-db-session'), 'payload' => 'ordinary', 'last_activity' => now()->timestamp],
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $service->invalidateAllAfterCommit($admin);

        self::assertDatabaseMissing('sessions', ['id' => $this->sessionKey('privileged-db-session')]);
        self::assertDatabaseHas('sessions', ['id' => $this->sessionKey('ordinary-db-session')]);
        self::assertNotSame([], $queries);
        self::assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'sessions') && str_contains($sql, 'user_id')));
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

    private function useDatabaseQueue(): void
    {
        config(['queue.default' => 'database']);
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

}
