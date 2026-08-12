<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\PrivilegedSecurityToken;
use App\Models\PrivilegedSession;
use App\Models\SuperAdmin;
use App\Services\PrivilegedSecurityTokenService;
use App\Services\PrivilegedSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PrivilegedPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

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
}
