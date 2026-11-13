<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class CustomerMfaSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_profile_exposes_mfa_state_and_private_security_activity(): void
    {
        $customer = $this->customer();
        $otherCustomer = $this->customer();

        AuditLog::create([
            'user_id' => $customer->getKey(),
            'actor_user_id' => $customer->getKey(),
            'action' => 'customer_password_changed',
            'object_type' => User::class,
            'object_id' => $customer->getKey(),
            'target_type' => 'customer_security',
            'target_id' => $customer->getKey(),
            'metadata' => [
                'description' => 'Customer changed their password.',
                'severity' => 'warning',
            ],
        ]);
        AuditLog::create([
            'user_id' => $otherCustomer->getKey(),
            'actor_user_id' => $otherCustomer->getKey(),
            'action' => 'customer_password_changed',
            'object_type' => User::class,
            'object_id' => $otherCustomer->getKey(),
            'target_type' => 'customer_security',
            'target_id' => $otherCustomer->getKey(),
            'metadata' => [
                'description' => 'Other customer changed their password.',
                'severity' => 'warning',
            ],
        ]);

        $this->actingAs($customer, 'user')
            ->get(route('customer-profile'))
            ->assertInertia(fn ($page) => $page
                ->component('UserSide/Profile/customerProfile')
                ->where('security.totp_enabled', false)
                ->where('security.activity.0.action', 'customer_password_changed')
            );

        $this->actingAs($customer, 'user')
            ->getJson('/customer-profile/security/activity')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'customer_password_changed')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['description' => 'Other customer changed their password.']);
    }

    public function test_successful_customer_login_is_recorded_in_security_activity(): void
    {
        $customer = $this->customer([
            'password' => Hash::make('CurrentPass1!'),
        ]);

        $this->postJson(route('user.login'), [
            'email' => $customer->email,
            'password' => 'CurrentPass1!',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertAuthenticatedAs($customer->fresh(), 'user');

        $this->getJson(route('customer.security.activity'))
            ->assertOk()
            ->assertJsonPath('data.0.action', 'customer_login_succeeded')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_customer_can_enroll_regenerate_and_disable_mfa(): void
    {
        $customer = $this->customer(['password' => Hash::make('CurrentPass1!')]);

        $setup = $this->actingAs($customer, 'user')
            ->postJson('/customer-profile/security/totp/setup', [
                'current_password' => 'CurrentPass1!',
            ])
            ->assertOk()
            ->assertJsonStructure(['qr_code', 'manual_key', 'expires_at']);

        $secret = (string) $setup->json('manual_key');
        $code = (new Google2FA())->oathTotp($secret, intdiv(now()->timestamp, 30));

        $verification = $this->actingAs($customer, 'user')
            ->postJson('/customer-profile/security/totp/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonStructure(['recovery_codes']);

        $fresh = $customer->fresh();
        self::assertNotNull($fresh?->employee_totp_secret);
        self::assertNotNull($fresh?->employee_totp_enabled_at);
        self::assertNotSame($secret, DB::table('users')->where('id', $customer->getKey())->value('employee_totp_secret'));

        $regenerateCode = (new Google2FA())->oathTotp((string) $fresh?->employee_totp_secret, intdiv(now()->timestamp, 30));
        $this->actingAs($customer, 'user')
            ->postJson('/customer-profile/security/totp/recovery-codes/regenerate', [
                'current_password' => 'CurrentPass1!',
                'code' => $regenerateCode,
            ])
            ->assertOk()
            ->assertJsonStructure(['recovery_codes']);

        $this->travel(31)->seconds();
        $disableCode = (new Google2FA())->oathTotp((string) $customer->fresh()?->employee_totp_secret, intdiv(now()->timestamp, 30));
        $oldVersion = (int) $customer->fresh()?->security_version;

        $this->actingAs($customer, 'user')
            ->postJson('/customer-profile/security/totp/disable', [
                'current_password' => 'CurrentPass1!',
                'code' => $disableCode,
            ])
            ->assertOk();

        $disabled = $customer->fresh();
        self::assertNull($disabled?->employee_totp_secret);
        self::assertNull($disabled?->employee_totp_enabled_at);
        self::assertGreaterThan($oldVersion, (int) $disabled?->security_version);
    }

    public function test_customer_login_requires_mfa_and_completes_only_after_verification(): void
    {
        $customer = $this->customer([
            'password' => Hash::make('CurrentPass1!'),
            'employee_totp_secret' => 'JBSWY3DPEHPK3PXP',
            'employee_totp_enabled_at' => now(),
        ]);

        $login = $this->postJson(route('user.login'), [
            'email' => $customer->email,
            'password' => 'CurrentPass1!',
        ]);

        $login->assertStatus(202)
            ->assertJson([
                'success' => false,
                'requires_mfa' => true,
            ])
            ->assertJsonMissing(['message' => 'Login successful!']);
        $this->assertGuest('user');

        $this->postJson(route('customer.mfa.challenge.verify'), ['code' => '000000'])
            ->assertStatus(422);
        $this->assertGuest('user');

        $code = (new Google2FA())->oathTotp('JBSWY3DPEHPK3PXP', intdiv(now()->timestamp, 30));
        $this->postJson(route('customer.mfa.challenge.verify'), ['code' => $code])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertAuthenticatedAs($customer->fresh(), 'user');
    }

    public function test_legacy_api_login_does_not_issue_a_token_for_an_mfa_customer(): void
    {
        $customer = $this->customer([
            'password' => Hash::make('CurrentPass1!'),
            'employee_totp_secret' => 'JBSWY3DPEHPK3PXP',
            'employee_totp_enabled_at' => now(),
        ]);

        $this->postJson('/api/login', [
            'email' => $customer->email,
            'password' => 'CurrentPass1!',
        ])
            ->assertStatus(422)
            ->assertJsonMissingPath('token');
    }

    public function test_customer_security_routes_are_isolated_and_have_no_customer_sessions_route(): void
    {
        $customer = $this->customer();
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        $employee = User::factory()->create([
            'shop_owner_id' => $shop->getKey(),
            'role' => 'STAFF',
            'status' => 'active',
        ]);

        $this->actingAs($employee, 'user')
            ->getJson('/customer-profile/security/activity')
            ->assertStatus(403);

        $this->actingAs($customer, 'user')
            ->getJson(route('erp.security.activity'))
            ->assertStatus(403);

        self::assertFalse(app('router')->getRoutes()->hasNamedRoute('customer.security.sessions.index'));
        self::assertFalse(app('router')->getRoutes()->hasNamedRoute('customer.security.sessions.logout-others'));
    }

    /** @param array<string, mixed> $attributes */
    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => null,
            'shop_owner_id' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ], $attributes));
    }
}
