<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Mail\PrivilegedSetupLinkMail;
use App\Enums\PrivilegedDeliveryType;
use App\Jobs\SendPrivilegedWorkflowMail;
use App\Models\PrivilegedSecurityToken;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use App\Services\PrivilegedSecurityTokenService;
use App\Services\PrivilegedSetupProofService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class PrivilegedBootstrapAndInvitationTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_bootstrap_is_interactive_has_no_secret_arguments_and_queues_an_encrypted_setup_mail(): void
    {
        $this->useDatabaseQueue();
        $command = app('Illuminate\Contracts\Console\Kernel')->all()['super-admin:bootstrap'];

        self::assertFalse($command->getDefinition()->hasArgument('password'));
        self::assertFalse($command->getDefinition()->hasArgument('token'));
        self::assertFalse($command->getDefinition()->hasOption('password'));
        self::assertFalse($command->getDefinition()->hasOption('token'));

        $this->artisan('super-admin:bootstrap')
            ->expectsQuestion('First name', 'Platform')
            ->expectsQuestion('Last name', 'Owner')
            ->expectsQuestion('Email', 'platform@example.test')
            ->expectsQuestion('Phone', '09171234567')
            ->assertSuccessful()
            ->expectsOutputToContain('Operation ID:');

        $admin = SuperAdmin::query()->firstOrFail();
        self::assertSame(SuperAdmin::STATUS_PENDING_SETUP, $admin->status);
        self::assertSame(SuperAdmin::ROLE_SUPER_ADMIN, $admin->role);
        self::assertSame('platform', $admin->bootstrap_marker);
        self::assertFalse(Hash::check('BootstrapPassword1!', (string) $admin->getRawOriginal('password')));

        $token = PrivilegedSecurityToken::query()->firstOrFail();
        self::assertSame(PrivilegedSecurityToken::PURPOSE_SETUP, $token->purpose);
        self::assertSame(64, strlen((string) $token->token_hash));
        self::assertSame(1, DB::table('jobs')->count());

        $payload = (string) DB::table('jobs')->value('payload');
        self::assertStringNotContainsString('/admin/setup#token=', $payload);
        self::assertStringNotContainsString((string) $admin->email, $payload);
    }

    public function test_bootstrap_refuses_existing_accounts_but_can_replace_the_sole_pending_platform_account(): void
    {
        $existing = SuperAdmin::factory()->activeWithoutMfa()->create();

        $this->artisan('super-admin:bootstrap')
            ->assertFailed()
            ->doesntExpectOutputToContain('First name');
        self::assertSame(1, SuperAdmin::query()->count());

        $existing->delete();
        $pending = SuperAdmin::factory()->pendingSetup()->create([
            'email' => 'old-platform@example.test',
        ]);
        $pending->forceFill(['bootstrap_marker' => 'platform'])->save();
        app(PrivilegedSecurityTokenService::class)->issue($pending, PrivilegedSecurityToken::PURPOSE_SETUP, null);
        $oldHash = (string) PrivilegedSecurityToken::query()->firstOrFail()->token_hash;

        $this->artisan('super-admin:bootstrap')
            ->expectsConfirmation('Replace the pending platform setup account?', 'yes')
            ->assertSuccessful();

        self::assertSame(1, SuperAdmin::query()->count());
        self::assertSame($pending->id, SuperAdmin::query()->firstOrFail()->id);
        self::assertSame(2, PrivilegedSecurityToken::query()->count());
        self::assertNotSame($oldHash, (string) PrivilegedSecurityToken::query()->latest('id')->value('token_hash'));
    }

    public function test_bootstrap_audit_failure_rolls_back_account_and_setup_token(): void
    {
        $this->mock(\App\Services\PrivilegedAudit::class, function ($mock): void {
            $mock->shouldReceive('privilegedBootstrapCreated')
                ->once()
                ->andThrow(new \RuntimeException('audit unavailable'));
        });

        $this->artisan('super-admin:bootstrap')
            ->expectsQuestion('First name', 'Platform')
            ->expectsQuestion('Last name', 'Owner')
            ->expectsQuestion('Email', 'rollback@example.test')
            ->expectsQuestion('Phone', '09171234567')
            ->assertFailed();

        self::assertDatabaseCount('super_admins', 0);
        self::assertDatabaseCount('privileged_security_tokens', 0);
    }

    public function test_setup_mailable_is_queued_and_places_the_bearer_only_in_the_fragment(): void
    {
        $rawToken = 'fragment-token-value';
        $mail = new PrivilegedSetupLinkMail('Platform Owner', 'platform@example.test', $rawToken);

        self::assertInstanceOf(ShouldQueue::class, $mail);
        self::assertInstanceOf(ShouldBeEncrypted::class, $mail);

        $rendered = $mail->render();
        self::assertStringContainsString('#token='.$rawToken, $rendered);
        self::assertStringNotContainsString('?token='.$rawToken, $rendered);
        self::assertStringNotContainsString('/admin/setup/'.$rawToken, $rendered);
    }

    public function test_only_reauthenticated_manager_can_invite_without_accepting_a_password(): void
    {
        $actor = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($actor);
        $this->markRecentlyReauthenticated($actor);

        $response = $this->post('/admin/administrators', [
            'first_name' => 'Invited',
            'last_name' => 'Admin',
            'email' => 'invited@example.test',
            'phone' => '09171234567',
            'role' => SuperAdmin::ROLE_ADMIN,
        ]);

        $response->assertRedirect(route('admin.administrators.index'));
        $invited = SuperAdmin::query()->where('email', 'invited@example.test')->firstOrFail();
        self::assertSame(SuperAdmin::STATUS_PENDING_SETUP, $invited->status);
        self::assertFalse(Hash::check('TestPassword1!', (string) $invited->getRawOriginal('password')));
        self::assertDatabaseHas('privileged_security_tokens', [
            'super_admin_id' => $invited->id,
            'purpose' => PrivilegedSecurityToken::PURPOSE_SETUP,
        ]);
        self::assertDatabaseHas('activity_log', ['event' => 'privileged_invitation_created']);

        $adminActor = SuperAdmin::factory()->admin()->create();
        $this->actingAsCompletedPrivileged($adminActor);
        $this->markRecentlyReauthenticated($adminActor);
        $this->post('/admin/administrators', [
            'first_name' => 'Denied',
            'last_name' => 'Admin',
            'email' => 'denied@example.test',
            'phone' => '09171234568',
            'role' => SuperAdmin::ROLE_ADMIN,
        ])->assertForbidden();
        self::assertDatabaseMissing('super_admins', ['email' => 'denied@example.test']);
    }

    public function test_invitation_rejects_phone_numbers_with_non_digit_characters(): void
    {
        $actor = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($actor);
        $this->markRecentlyReauthenticated($actor);

        $this->from('/admin/administrators/create')->post('/admin/administrators', [
            'first_name' => 'Invalid',
            'last_name' => 'Phone',
            'email' => 'invalid-phone@example.test',
            'phone' => '0917-123-4567',
            'role' => SuperAdmin::ROLE_ADMIN,
        ])->assertRedirect('/admin/administrators/create')
            ->assertSessionHasErrors(['phone']);

        self::assertDatabaseMissing('super_admins', ['email' => 'invalid-phone@example.test']);
    }

    public function test_invitation_resend_replaces_the_old_token_and_keeps_pending_state_when_delivery_is_deferred(): void
    {
        Queue::fake();
        $actor = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($actor);
        $this->markRecentlyReauthenticated($actor);

        $this->post('/admin/administrators', [
            'first_name' => 'Invited',
            'last_name' => 'Admin',
            'email' => 'resend@example.test',
            'phone' => '09171234567',
            'role' => SuperAdmin::ROLE_ADMIN,
        ])->assertRedirect();
        DB::commit();

        $invited = SuperAdmin::query()->where('email', 'resend@example.test')->firstOrFail();
        $oldToken = PrivilegedSecurityToken::query()
            ->where('super_admin_id', $invited->id)
            ->where('purpose', PrivilegedSecurityToken::PURPOSE_SETUP)
            ->firstOrFail();

        $this->post("/admin/administrators/{$invited->id}/setup/resend")
            ->assertRedirect(route('admin.administrators.index'));
        $this->assertSame(2, Queue::pushed(SendPrivilegedWorkflowMail::class)->count());

        self::assertNotNull($oldToken->fresh()?->used_at);
        self::assertSame(2, PrivilegedSecurityToken::query()->where('super_admin_id', $invited->id)->count());

        $this->post("/admin/administrators/{$invited->id}/setup/resend")
            ->assertRedirect(route('admin.administrators.index'));
        $this->assertSame(3, Queue::pushed(SendPrivilegedWorkflowMail::class)->count());
        self::assertSame(SuperAdmin::STATUS_PENDING_SETUP, $invited->fresh()?->status);

        Queue::assertPushed(SendPrivilegedWorkflowMail::class, function (SendPrivilegedWorkflowMail $job): bool {
            return $job->deliveryType === PrivilegedDeliveryType::PRIVILEGED_ADMIN_SETUP
                && $job->recipientType === 'super_admin';
        });
    }

    public function test_setup_exchange_returns_an_opaque_proof_without_storing_authorization_in_session(): void
    {
        $pending = SuperAdmin::factory()->pendingSetup()->create();
        $issued = app(PrivilegedSecurityTokenService::class)->issue(
            $pending,
            PrivilegedSecurityToken::PURPOSE_SETUP,
            null,
        );
        $rawToken = $issued['raw_token'];

        $this->get('/admin/setup')
            ->assertOk()
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertSee('PrivilegedSetup', false);

        $response = $this->postJson('/admin/setup/exchange', ['token' => $rawToken]);
        $response->assertOk()
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertJson(['authorized' => true])
            ->assertJsonStructure(['authorized', 'completion_proof']);
        $completionProof = $response->json('completion_proof');

        self::assertIsString($completionProof);
        self::assertNotSame('', $completionProof);
        self::assertNotSame($rawToken, $completionProof);
        self::assertStringNotContainsString($rawToken, $response->getContent());
        self::assertNull(session('privileged_setup_authorization'));
        self::assertStringNotContainsString($rawToken, serialize(session()->all()));
        self::assertStringNotContainsString($completionProof, serialize(session()->all()));
    }

    public function test_setup_password_consumes_the_authorized_token_atomically_and_enters_mfa_setup(): void
    {
        $pending = SuperAdmin::factory()->pendingSetup()->create([
            'email' => 'pending-setup@example.test',
        ]);
        $issued = app(PrivilegedSecurityTokenService::class)->issue(
            $pending,
            PrivilegedSecurityToken::PURPOSE_SETUP,
            null,
        );
        $rawToken = $issued['raw_token'];
        $token = $issued['token'];

        $completionProof = $this->exchangeSetupProof($rawToken);
        $response = $this->post('/admin/setup/complete', [
            'completion_proof' => $completionProof,
            'password' => 'LongEnough-Setup1!',
            'password_confirmation' => 'LongEnough-Setup1!',
        ]);

        $response->assertRedirect('/admin/mfa/setup');
        $fresh = $pending->fresh();
        self::assertNotNull($fresh?->password_changed_at);
        self::assertNotNull($fresh?->mfa_secret);
        self::assertNull($fresh?->mfa_confirmed_at);
        self::assertSame(SuperAdmin::STATUS_PENDING_SETUP, $fresh?->status);
        self::assertNotNull($token->fresh()?->used_at);
        self::assertSame('setup', session('privileged_auth_stage'));
        self::assertSame($pending->id, session('privileged_super_admin_id'));
        self::assertDatabaseCount('privileged_sessions', 0);

        $this->postJson('/admin/setup/complete', [
            'completion_proof' => $completionProof,
            'password' => 'Another-Setup2!',
            'password_confirmation' => 'Another-Setup2!',
        ])->assertUnprocessable();
    }

    public function test_setup_password_completes_without_exchange_session_authorization(): void
    {
        $pending = SuperAdmin::factory()->pendingSetup()->create([
            'email' => 'session-independent@example.test',
        ]);
        $issued = app(PrivilegedSecurityTokenService::class)->issue(
            $pending,
            PrivilegedSecurityToken::PURPOSE_SETUP,
            null,
        );

        $proof = $this->exchangeSetupProof($issued['raw_token']);
        self::assertNull(session('privileged_setup_authorization'));
        session()->forget('privileged_setup_authorization');
        session()->save();

        $this->post('/admin/setup/complete', [
            'completion_proof' => $proof,
            'password' => 'LongEnough-Setup1!',
            'password_confirmation' => 'LongEnough-Setup1!',
        ])->assertRedirect('/admin/mfa/setup');

        self::assertNotNull($pending->fresh()?->password_changed_at);
        self::assertNotNull($pending->fresh()?->mfa_secret);
        self::assertNotNull($issued['token']->fresh()?->used_at);
    }

    public function test_invalid_completion_proofs_do_not_change_setup_state_or_expose_credentials(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 13, 12, 0, 0, 'UTC'));
        $pending = SuperAdmin::factory()->pendingSetup()->create();
        $otherPending = SuperAdmin::factory()->pendingSetup()->create();
        $issued = app(PrivilegedSecurityTokenService::class)->issue(
            $pending,
            PrivilegedSecurityToken::PURPOSE_SETUP,
            null,
        );
        $proof = $this->exchangeSetupProof($issued['raw_token']);
        $modifiedProof = substr_replace($proof, $proof[10] === 'A' ? 'B' : 'A', 10, 1);
        $payload = json_decode(Crypt::decryptString($proof), true, flags: JSON_THROW_ON_ERROR);
        $payload['purpose'] = PrivilegedSecurityToken::PURPOSE_PASSWORD_RESET;
        $wrongPurposeProof = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        $wrongSubjectProof = app(PrivilegedSetupProofService::class)->issue(
            tokenId: (int) $issued['token']->id,
            subjectId: (int) $otherPending->id,
            tokenExpiresAt: $issued['token']->expires_at,
        );
        Log::spy();

        $this->postJson('/admin/setup/complete', [
            'password' => 'LongEnough-Setup1!',
            'password_confirmation' => 'LongEnough-Setup1!',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('completion_proof');

        foreach ([$modifiedProof, $wrongPurposeProof, $wrongSubjectProof] as $invalidProof) {
            $response = $this->postJson('/admin/setup/complete', [
                'completion_proof' => $invalidProof,
                'password' => 'LongEnough-Setup1!',
                'password_confirmation' => 'LongEnough-Setup1!',
            ])->assertUnprocessable();

            self::assertStringNotContainsString($invalidProof, $response->getContent());
        }

        Carbon::setTestNow(now()->addMinutes(16));
        $expiredResponse = $this->postJson('/admin/setup/complete', [
            'completion_proof' => $proof,
            'password' => 'LongEnough-Setup1!',
            'password_confirmation' => 'LongEnough-Setup1!',
        ])->assertUnprocessable();

        self::assertStringNotContainsString($proof, $expiredResponse->getContent());
        self::assertNull($pending->fresh()?->password_changed_at);
        self::assertNull($pending->fresh()?->mfa_secret);
        self::assertNull($issued['token']->fresh()?->used_at);
        Log::shouldHaveReceived('warning')->times(4)->withArgs(
            function (string $message, array $context) use ($proof, $modifiedProof, $wrongPurposeProof, $wrongSubjectProof): bool {
                $serializedContext = serialize($context);

                return in_array($message, [
                    'Privileged setup completion proof rejected',
                    'Privileged setup authorization rejected',
                ], true)
                    && ! str_contains($serializedContext, 'LongEnough-Setup1!')
                    && ! str_contains($serializedContext, $proof)
                    && ! str_contains($serializedContext, $modifiedProof)
                    && ! str_contains($serializedContext, $wrongPurposeProof)
                    && ! str_contains($serializedContext, $wrongSubjectProof);
            },
        );
    }

    public function test_unexpected_setup_completion_failure_is_reported_without_exposing_credentials(): void
    {
        $pending = SuperAdmin::factory()->pendingSetup()->create();
        $issued = app(PrivilegedSecurityTokenService::class)->issue(
            $pending,
            PrivilegedSecurityToken::PURPOSE_SETUP,
            null,
        );
        $completionProof = $this->exchangeSetupProof($issued['raw_token']);

        $this->mock(PrivilegedAudit::class, function ($mock): void {
            $mock->shouldReceive('privilegedSetupPasswordCompleted')
                ->once()
                ->andThrow(new \RuntimeException('audit unavailable'));
        });
        Log::spy();

        $response = $this->post('/admin/setup/complete', [
            'completion_proof' => $completionProof,
            'password' => 'LongEnough-Setup1!',
            'password_confirmation' => 'LongEnough-Setup1!',
        ]);

        $response->assertRedirect('/admin/setup')->assertSessionHasErrors([
            'error' => 'Setup could not be completed. Please try again.',
        ]);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Privileged setup password completion failed'
                && ($context['exception_class'] ?? null) === \RuntimeException::class
                && ! array_key_exists('password', $context)
                && ! array_key_exists('password_confirmation', $context)
                && ! array_key_exists('completion_proof', $context);
        });
        self::assertNull($pending->fresh()?->password_changed_at);
        self::assertNull($issued['token']->fresh()?->used_at);
    }

    public function test_rejected_setup_authorization_is_logged_without_sensitive_details(): void
    {
        $pending = SuperAdmin::factory()->pendingSetup()->create();
        $issued = app(PrivilegedSecurityTokenService::class)->issue(
            $pending,
            PrivilegedSecurityToken::PURPOSE_SETUP,
            null,
        );
        $completionProof = $this->exchangeSetupProof($issued['raw_token']);
        $issued['token']->forceFill(['used_at' => now()])->save();
        Log::spy();

        $this->post('/admin/setup/complete', [
            'completion_proof' => $completionProof,
            'password' => 'LongEnough-Setup1!',
            'password_confirmation' => 'LongEnough-Setup1!',
        ])->assertRedirect('/admin/setup');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Privileged setup authorization rejected'
                && ($context['exception_class'] ?? null) === \InvalidArgumentException::class
                && ! array_key_exists('password', $context)
                && ! array_key_exists('password_confirmation', $context)
                && ! array_key_exists('completion_proof', $context)
                && ! array_key_exists('exception_message', $context);
        });
    }

    public function test_existing_active_account_can_enroll_mfa_and_acknowledge_recovery_codes_without_status_change(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 12, 12, 0, 15, 'UTC'));
        $admin = SuperAdmin::factory()->activeWithoutMfa()->create([
            'email' => 'active-enrollment@example.test',
            'password' => 'ExistingPassword1!',
        ]);

        $login = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'ExistingPassword1!',
        ]);
        $this->syncSessionCookie($login);
        $this->get('/admin/mfa/setup')->assertOk();

        $admin->refresh();
        $timestep = intdiv(Carbon::now()->timestamp, 30);
        $code = (new Google2FA())->oathTotp((string) $admin->mfa_secret, $timestep);
        $verification = $this->postJson('/admin/mfa/setup/verify', ['code' => $code]);

        $verification->assertOk()->assertJsonStructure(['recovery_codes', 'acknowledgement_token']);
        $recoveryCodes = $verification->json('recovery_codes');
        $acknowledgementToken = (string) $verification->json('acknowledgement_token');
        self::assertCount(8, $recoveryCodes);
        self::assertStringNotContainsString(implode('', $recoveryCodes), serialize(session()->all()));
        self::assertStringNotContainsString($acknowledgementToken, serialize(session()->all()));

        $admin->refresh();
        self::assertSame(SuperAdmin::STATUS_ACTIVE, $admin->status);
        self::assertNull($admin->mfa_confirmed_at);
        self::assertFalse($admin->hasCompletedMfaSetup());
        self::assertCount(8, $admin->mfa_recovery_codes);

        $acknowledge = $this->post('/admin/mfa/setup/recovery/acknowledge', [
            'token' => $acknowledgementToken,
        ]);
        $acknowledge->assertRedirect(route('admin.system-monitoring'));
        $this->syncSessionCookie($acknowledge);

        $admin->refresh();
        self::assertSame(SuperAdmin::STATUS_ACTIVE, $admin->status);
        self::assertTrue($admin->hasCompletedMfaSetup());
        self::assertNotNull($admin->mfa_confirmed_at);
        self::assertDatabaseHas('privileged_sessions', [
            'super_admin_id' => $admin->id,
            'security_version' => $admin->security_version,
        ]);
        $this->get('/admin/system-monitoring')->assertOk();
    }

    public function test_pending_setup_becomes_active_only_after_mfa_enrollment_and_recovery_acknowledgement(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 12, 12, 0, 15, 'UTC'));
        $pending = SuperAdmin::factory()->pendingSetup()->create([
            'email' => 'pending-complete@example.test',
        ]);
        $issued = app(PrivilegedSecurityTokenService::class)->issue(
            $pending,
            PrivilegedSecurityToken::PURPOSE_SETUP,
            null,
        );
        $rawToken = $issued['raw_token'];

        $completionProof = $this->exchangeSetupProof($rawToken);
        $this->post('/admin/setup/complete', [
            'completion_proof' => $completionProof,
            'password' => 'LongEnough-Setup1!',
            'password_confirmation' => 'LongEnough-Setup1!',
        ])->assertRedirect('/admin/mfa/setup');

        $pending->refresh();
        $timestep = intdiv(Carbon::now()->timestamp, 30);
        $code = (new Google2FA())->oathTotp((string) $pending->mfa_secret, $timestep);
        $verification = $this->postJson('/admin/mfa/setup/verify', ['code' => $code])->assertOk();
        $acknowledgementToken = (string) $verification->json('acknowledgement_token');

        $this->post('/admin/mfa/setup/recovery/acknowledge', [
            'token' => $acknowledgementToken,
        ])->assertRedirect(route('admin.system-monitoring'));

        self::assertSame(SuperAdmin::STATUS_ACTIVE, $pending->fresh()?->status);
        self::assertTrue($pending->fresh()?->hasCompletedMfaSetup());
    }

    public function test_setup_rejects_used_or_expired_bearers_without_leaking_the_token(): void
    {
        $pending = SuperAdmin::factory()->pendingSetup()->create();
        $issued = app(PrivilegedSecurityTokenService::class)->issue(
            $pending,
            PrivilegedSecurityToken::PURPOSE_SETUP,
            null,
        );
        $rawToken = $issued['raw_token'];

        $this->postJson('/admin/setup/exchange', ['token' => 'not-a-valid-token'])
            ->assertUnprocessable()
            ->assertJson(['message' => 'The setup link is invalid or expired.']);

        $completionProof = $this->exchangeSetupProof($rawToken);
        $this->exchangeSetupProof($rawToken);

        $pending->forceFill(['status' => SuperAdmin::STATUS_ACTIVE])->save();
        $this->postJson('/admin/setup/complete', [
            'completion_proof' => $completionProof,
            'password' => 'LongEnough-Setup1!',
            'password_confirmation' => 'LongEnough-Setup1!',
        ])->assertUnprocessable();
        self::assertStringNotContainsString($rawToken, serialize(session()->all()));
    }

    public function test_enrollment_replaces_unacknowledged_recovery_codes_with_a_newer_totp(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 12, 12, 0, 15, 'UTC'));
        $admin = SuperAdmin::factory()->activeWithoutMfa()->create([
            'email' => 'replace-codes@example.test',
            'password' => 'ExistingPassword1!',
        ]);
        $login = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'ExistingPassword1!',
        ]);
        $this->syncSessionCookie($login);
        $this->get('/admin/mfa/setup');
        $admin->refresh();

        $firstCode = (new Google2FA())->oathTotp((string) $admin->mfa_secret, intdiv(Carbon::now()->timestamp, 30));
        $first = $this->postJson('/admin/mfa/setup/verify', ['code' => $firstCode])->assertOk();
        $firstAck = (string) $first->json('acknowledgement_token');
        $firstHashes = $admin->fresh()?->mfa_recovery_codes;

        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $admin->refresh();
        $secondCode = (new Google2FA())->oathTotp((string) $admin->mfa_secret, intdiv(Carbon::now()->timestamp, 30));
        $second = $this->postJson('/admin/mfa/setup/verify', ['code' => $secondCode])->assertOk();
        $secondAck = (string) $second->json('acknowledgement_token');

        self::assertNotSame($firstAck, $secondAck);
        self::assertNotSame($firstHashes, $admin->fresh()?->mfa_recovery_codes);
        $this->postJson('/admin/mfa/setup/recovery/acknowledge', ['token' => $firstAck])
            ->assertUnprocessable();
        $this->post('/admin/mfa/setup/recovery/acknowledge', ['token' => $secondAck])
            ->assertRedirect(route('admin.system-monitoring'));
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

    private function syncSessionCookie(TestResponse $response): void
    {
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($candidate): bool => $candidate->getName() === config('session.cookie'));

        if ($cookie !== null) {
            $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
    }

    private function exchangeSetupProof(string $rawToken): string
    {
        $response = $this->postJson('/admin/setup/exchange', ['token' => $rawToken]);
        $response->assertOk()->assertJsonStructure(['authorized', 'completion_proof']);
        $completionProof = $response->json('completion_proof');

        self::assertIsString($completionProof);
        self::assertNotSame('', $completionProof);
        self::assertNotSame($rawToken, $completionProof);
        self::assertNull(session('privileged_setup_authorization'));

        return $completionProof;
    }
}
