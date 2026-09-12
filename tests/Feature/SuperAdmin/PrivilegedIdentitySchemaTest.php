<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\PrivilegedSecurityToken;
use App\Models\PrivilegedSession;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PrivilegedIdentitySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_privileged_identity_schema_supports_pending_setup_tokens_and_sessions(): void
    {
        self::assertTrue(Schema::hasColumns('super_admins', [
            'mfa_secret',
            'mfa_recovery_codes',
            'mfa_confirmed_at',
            'mfa_last_used_timestep',
            'security_version',
            'password_changed_at',
            'bootstrap_marker',
        ]));

        $pending = SuperAdmin::factory()->pendingSetup()->create();

        self::assertSame(SuperAdmin::STATUS_PENDING_SETUP, $pending->status);
        self::assertTrue(Schema::hasColumns('privileged_security_tokens', [
            'super_admin_id',
            'created_by_super_admin_id',
            'purpose',
            'token_hash',
            'expires_at',
            'used_at',
        ]));
        self::assertTrue(Schema::hasColumns('privileged_sessions', [
            'session_id',
            'super_admin_id',
            'security_version',
            'authenticated_at',
            'last_seen_at',
        ]));
    }

    public function test_mfa_completion_uses_all_three_required_attributes(): void
    {
        $withoutMfa = SuperAdmin::factory()->activeWithoutMfa()->create();
        $enrolled = SuperAdmin::factory()->mfaEnrolled()->create();

        self::assertFalse($withoutMfa->hasCompletedMfaSetup());
        self::assertTrue($enrolled->hasCompletedMfaSetup());

        $enrolled->forceFill(['mfa_recovery_codes' => []])->save();

        self::assertTrue($enrolled->fresh()->hasCompletedMfaSetup());

        $enrolled->forceFill(['mfa_confirmed_at' => null])->save();

        self::assertFalse($enrolled->fresh()->hasCompletedMfaSetup());
    }

    public function test_security_fields_are_hidden_and_totp_secret_is_encrypted_at_rest(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create([
            'bootstrap_marker' => 'bootstrap-marker-test',
        ]);
        $serialized = $admin->toArray();
        $storedSecret = DB::table((new SuperAdmin())->getTable())
            ->where('id', $admin->id)
            ->value('mfa_secret');

        self::assertNotSame($admin->mfa_secret, $storedSecret);
        self::assertNotContains('password', array_keys($serialized));
        self::assertNotContains('remember_token', array_keys($serialized));
        self::assertNotContains('mfa_secret', array_keys($serialized));
        self::assertNotContains('mfa_recovery_codes', array_keys($serialized));
        self::assertNotContains('bootstrap_marker', array_keys($serialized));
        self::assertSame(1, $admin->security_version);
    }

    public function test_default_factory_is_active_and_mfa_enrolled(): void
    {
        $admin = SuperAdmin::factory()->create();

        self::assertSame(SuperAdmin::STATUS_ACTIVE, $admin->status);
        self::assertTrue($admin->hasCompletedMfaSetup());
        self::assertSame(1, $admin->security_version);
    }

    public function test_token_and_session_models_use_their_security_keys(): void
    {
        $admin = SuperAdmin::factory()->mfaEnrolled()->create();
        $token = PrivilegedSecurityToken::create([
            'super_admin_id' => $admin->id,
            'purpose' => PrivilegedSecurityToken::PURPOSE_SETUP,
            'token_hash' => str_repeat('a', 64),
            'expires_at' => now()->addHour(),
        ]);
        $session = PrivilegedSession::create([
            'session_id' => 'privileged-session-test',
            'super_admin_id' => $admin->id,
            'security_version' => $admin->security_version,
            'authenticated_at' => now(),
        ]);

        self::assertSame(64, mb_strlen($token->token_hash));
        $token->setRawAttributes(array_merge(
            $token->getAttributes(),
            ['super_admin_id' => (string) $admin->id],
        ));
        self::assertSame($admin->id, $token->super_admin_id);
        self::assertSame('session_id', $session->getKeyName());
        self::assertFalse($session->getIncrementing());
        self::assertSame('privileged-session-test', $session->getKey());
        self::assertSame($admin->id, $session->super_admin_id);
    }
}
