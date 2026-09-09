<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\EmployeeInvitationService;
use App\Services\EmployeeSecurityService;
use App\Services\EmployeeMfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeeAccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_service_permissions_cannot_reset_employee_credentials(): void
    {
        $shop = $this->shop();
        $target = $this->employeeAccount($shop);
        $targetEmployee = $this->employeeProfile($target);

        $attendanceOnly = $this->employeeAccount($shop);
        $this->grant($attendanceOnly, 'access-attendance-records');

        $this->actingAs($attendanceOnly, 'user')
            ->postJson(route('hr.employees.reset_password', ['id' => $targetEmployee->getKey()]))
            ->assertForbidden();

        $payslipOnly = $this->employeeAccount($shop);
        $this->grant($payslipOnly, 'access-view-payslip');

        $this->actingAs($payslipOnly, 'user')
            ->postJson(route('hr.employees.resend_invite', ['id' => $targetEmployee->getKey()]))
            ->assertForbidden();

        $combined = $this->employeeAccount($shop);
        $this->grant($combined, 'access-attendance-records', 'access-view-payslip');

        $this->actingAs($combined, 'user')
            ->postJson(route('hr.employees.reset_password', ['id' => $targetEmployee->getKey()]))
            ->assertForbidden();
    }

    public function test_employee_credential_operations_are_tenant_scoped(): void
    {
        $actor = $this->employeeAccount($this->shop());
        $this->grant($actor, 'manage-employee-accounts');

        $foreignEmployee = $this->employeeProfile($this->employeeAccount($this->shop()));

        $this->actingAs($actor, 'user')
            ->postJson(route('hr.employees.reset_password', ['id' => $foreignEmployee->getKey()]))
            ->assertNotFound();
    }

    public function test_employee_credentials_and_security_secrets_are_hidden_from_serialization(): void
    {
        $user = $this->employeeAccount($this->shop());
        $user->forceFill([
            'invite_token' => 'legacy-token',
            'invite_token_hash' => hash('sha256', 'legacy-token'),
            'employee_totp_secret' => 'SECRET123',
            'employee_totp_recovery_codes' => ['hashed-code'],
        ])->save();

        $serializedUser = $user->fresh()->toArray();

        foreach ([
            'password',
            'invite_token',
            'invite_token_hash',
            'employee_totp_secret',
            'employee_totp_recovery_codes',
            'security_version',
        ] as $field) {
            $this->assertArrayNotHasKey($field, $serializedUser);
        }

        $employee = Employee::factory()->make(['password' => 'legacy-hash']);
        $this->assertArrayNotHasKey('password', $employee->toArray());
    }

    public function test_invitation_tokens_are_hashed_rotated_and_single_use(): void
    {
        $user = $this->employeeAccount($this->shop(), [
            'password' => null,
            'force_password_change' => true,
        ]);
        $service = app(EmployeeInvitationService::class);

        $first = $service->issue($user)['token'];
        $second = $service->issue($user)['token'];

        $this->assertSame(64, strlen($second));
        $this->assertNull($user->fresh()->invite_token);
        $this->assertSame(
            hash('sha256', $second),
            DB::table('users')->where('id', $user->getKey())->value('invite_token_hash'),
        );
        $this->assertNull($service->find($first));
        $this->assertTrue($service->find($second)->is($user));

        $service->clear($user->fresh());
        $this->assertNull($service->find($second));
    }

    public function test_employee_company_accounts_are_excluded_from_email_otp_recovery(): void
    {
        Mail::fake();
        $user = $this->employeeAccount($this->shop(), [
            'email' => 'inventory1@solespace.com',
        ]);

        $this->post(route('password.otp.send'), ['email' => $user->email])
            ->assertRedirect(route('password.otp', ['email' => $user->email]));

        Mail::assertNothingSent();
        $this->assertNull(Cache::get('password_reset_otp:'.sha1($user->email)));
    }

    public function test_employee_api_login_still_works_when_totp_is_disabled(): void
    {
        $user = $this->employeeAccount($this->shop(), [
            'email' => 'employee-api@solespace.test',
            'password' => Hash::make('CurrentPass1!'),
        ]);
        $this->employeeProfile($user);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'CurrentPass1!',
        ])->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token']);
    }

    public function test_employee_api_login_cannot_bypass_enabled_totp(): void
    {
        $user = $this->employeeAccount($this->shop(), [
            'email' => 'employee-api-mfa@solespace.test',
            'password' => Hash::make('CurrentPass1!'),
        ]);
        $this->employeeProfile($user);
        $user->forceFill([
            'employee_totp_secret' => app(EmployeeMfaService::class)->generateSecret(),
            'employee_totp_enabled_at' => now(),
        ])->save();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'CurrentPass1!',
        ])->assertUnprocessable()
            ->assertJsonMissingPath('token');

        $this->assertGuest('user');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_force_password_change_is_enforced_before_protected_api_access(): void
    {
        $user = $this->employeeAccount($this->shop(), [
            'force_password_change' => true,
        ]);

        $this->actingAs($user, 'user')
            ->getJson('/api/user/me')
            ->assertForbidden();
    }

    public function test_employee_password_change_revokes_other_api_tokens(): void
    {
        $user = $this->employeeAccount($this->shop(), [
            'password' => Hash::make('CurrentPass1!'),
        ]);
        $this->employeeProfile($user);
        $user->createToken('old-device');

        $this->actingAs($user, 'user')
            ->post('/erp/password', [
                'current_password' => 'CurrentPass1!',
                'password' => 'NewStrongPass1!',
                'password_confirmation' => 'NewStrongPass1!',
            ])
            ->assertRedirect();

        $freshUser = $user->fresh();
        $this->assertTrue(Hash::check('NewStrongPass1!', (string) $freshUser->password));
        $this->assertSame(2, (int) $freshUser->security_version);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_employee_can_view_and_revoke_other_database_sessions(): void
    {
        config(['session.driver' => 'database']);

        $user = $this->employeeAccount($this->shop());
        $currentSessionId = str_repeat('c', 40);
        $otherSessionId = str_repeat('o', 40);

        DB::table('sessions')->insert([
            [
                'id' => $currentSessionId,
                'user_id' => $user->getKey(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Current Browser',
                'payload' => '',
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => $otherSessionId,
                'user_id' => $user->getKey(),
                'ip_address' => '127.0.0.2',
                'user_agent' => 'Other Browser',
                'payload' => '',
                'last_activity' => now()->timestamp,
            ],
        ]);

        $request = Request::create('/erp/profile');
        $request->setLaravelSession(app('session')->driver('array'));
        $request->session()->setId($currentSessionId);

        $service = app(EmployeeSecurityService::class);
        $this->assertCount(2, $service->activeSessions($request, $user));

        $user->createToken('old-device');
        $this->assertSame(1, $service->logoutOtherSessions($request, $user));

        $this->assertDatabaseHas('sessions', ['id' => $currentSessionId]);
        $this->assertDatabaseMissing('sessions', ['id' => $otherSessionId]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(2, (int) $user->fresh()->security_version);
    }

    public function test_file_sessions_still_expose_the_current_employee_session(): void
    {
        config(['session.driver' => 'file']);

        $user = $this->employeeAccount($this->shop());

        $this->actingAs($user, 'user')
            ->withHeader('User-Agent', 'Chrome/120.0 Windows')
            ->getJson('/erp/security/sessions?per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.device', 'Chrome / Windows')
            ->assertJsonPath('data.0.current', true);
    }

    public function test_employee_security_activity_is_paginated_and_human_readable(): void
    {
        $shop = $this->shop();
        $user = $this->employeeAccount($shop);
        $employee = $this->employeeProfile($user);
        $otherUser = $this->employeeAccount($shop);
        $otherEmployee = $this->employeeProfile($otherUser);

        foreach (range(1, 6) as $index) {
            \App\Models\HR\AuditLog::create([
                'shop_owner_id' => $shop->getKey(),
                'employee_id' => $employee->getKey(),
                'module' => \App\Models\HR\AuditLog::MODULE_EMPLOYEE,
                'action' => 'employee_password_changed',
                'entity_type' => User::class,
                'entity_id' => $user->getKey(),
                'description' => "Password change {$index}.",
                'severity' => \App\Models\HR\AuditLog::SEVERITY_WARNING,
                'tags' => ['employee_security'],
            ]);
        }

        \App\Models\HR\AuditLog::create([
            'shop_owner_id' => $shop->getKey(),
            'employee_id' => $otherEmployee->getKey(),
            'module' => \App\Models\HR\AuditLog::MODULE_EMPLOYEE,
            'action' => 'employee_password_changed',
            'entity_type' => User::class,
            'entity_id' => $otherUser->getKey(),
            'description' => 'Another employee password change.',
            'severity' => \App\Models\HR\AuditLog::SEVERITY_WARNING,
            'tags' => ['employee_security'],
        ]);

        $this->actingAs($user, 'user')
            ->getJson('/erp/security/activity?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 6)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.label', 'Password changed');
    }

    public function test_employee_active_sessions_are_paginated_and_scoped_to_their_account(): void
    {
        config(['session.driver' => 'database']);

        $shop = $this->shop();
        $user = $this->employeeAccount($shop);
        $otherUser = $this->employeeAccount($shop);

        foreach (range(1, 3) as $index) {
            DB::table('sessions')->insert([
                'id' => str_repeat((string) $index, 40),
                'user_id' => $user->getKey(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Chrome/120.0 Windows',
                'payload' => '',
                'last_activity' => now()->timestamp,
            ]);
        }

        DB::table('sessions')->insert([
            'id' => str_repeat('4', 40),
            'user_id' => $otherUser->getKey(),
            'ip_address' => '127.0.0.2',
            'user_agent' => 'Firefox/120.0 Linux',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($user, 'user')
            ->getJson('/erp/security/sessions?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device', 'Chrome / Windows');
    }

    public function test_employee_sign_in_outcomes_are_recorded_in_security_activity(): void
    {
        $shop = $this->shop();
        $user = $this->employeeAccount($shop, [
            'password' => Hash::make('CurrentPass1!'),
        ]);
        $employee = $this->employeeProfile($user);

        $this->postJson(route('user.login'), [
            'email' => $user->email,
            'password' => 'WrongPass1!',
        ])->assertUnprocessable();

        $this->postJson(route('user.login'), [
            'email' => $user->email,
            'password' => 'CurrentPass1!',
        ])->assertOk();

        $this->assertDatabaseHas('hr_audit_logs', [
            'employee_id' => $employee->getKey(),
            'action' => 'employee_login_failed',
        ]);
        $this->assertDatabaseHas('hr_audit_logs', [
            'employee_id' => $employee->getKey(),
            'action' => 'employee_login_succeeded',
        ]);
    }

    public function test_invitation_acceptance_uses_the_hardened_password_setup_path(): void
    {
        $user = $this->employeeAccount($this->shop(), [
            'password' => null,
            'force_password_change' => true,
        ]);
        $this->employeeProfile($user);
        $token = app(EmployeeInvitationService::class)->issue($user)['token'];

        $this->post(route('invitation.accept-invitation.submit', ['token' => $token]), [
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ])->assertRedirect(route('login'));

        $freshUser = $user->fresh();
        $this->assertTrue(Hash::check('NewStrongPass1!', (string) $freshUser->password));
        $this->assertFalse((bool) $freshUser->force_password_change);
        $this->assertNull($freshUser->invite_token_hash);
        $this->assertSame(2, (int) $freshUser->security_version);
    }

    public function test_invitation_acceptance_requires_a_matching_employee_record(): void
    {
        $user = $this->employeeAccount($this->shop(), [
            'password' => null,
            'force_password_change' => true,
        ]);
        $token = app(EmployeeInvitationService::class)->issue($user)['token'];

        $this->post(route('invitation.accept-invitation.submit', ['token' => $token]), [
            'password' => 'NewStrongPass1!',
            'password_confirmation' => 'NewStrongPass1!',
        ])->assertOk();

        $freshUser = $user->fresh();
        $this->assertNull($freshUser->password);
        $this->assertNotNull($freshUser->invite_token_hash);
        $this->assertSame(1, (int) $freshUser->security_version);
    }

    public function test_totp_enrollment_stays_disabled_until_a_valid_code_is_verified(): void
    {
        $user = $this->employeeAccount($this->shop(), [
            'password' => Hash::make('CurrentPass1!'),
        ]);
        $this->employeeProfile($user);

        $setup = $this->actingAs($user, 'user')
            ->postJson(route('erp.security.totp.setup'), [
                'current_password' => 'CurrentPass1!',
            ])
            ->assertOk();

        $manualKey = (string) $setup->json('manual_key');
        $this->assertNotSame('', $manualKey);
        $this->assertFalse($user->fresh()->hasEmployeeTotpEnabled());

        $validCode = app(Google2FA::class)->getCurrentOtp($manualKey);
        $invalidCode = $validCode === '000000' ? '000001' : '000000';

        $this->actingAs($user, 'user')
            ->postJson(route('erp.security.totp.verify'), ['code' => $invalidCode])
            ->assertUnprocessable();
        $this->assertFalse($user->fresh()->hasEmployeeTotpEnabled());

        $enabled = $this->actingAs($user, 'user')
            ->postJson(route('erp.security.totp.verify'), ['code' => $validCode])
            ->assertOk();

        $this->assertTrue($user->fresh()->hasEmployeeTotpEnabled());
        $this->assertCount(8, $enabled->json('recovery_codes'));
    }

    public function test_employee_totp_login_requires_the_second_factor(): void
    {
        $user = $this->employeeAccount($this->shop(), [
            'email' => 'repairer1@solespace.com',
            'password' => Hash::make('CurrentPass1!'),
        ]);
        $this->employeeProfile($user);
        $secret = app(EmployeeMfaService::class)->generateSecret();
        $user->forceFill([
            'employee_totp_secret' => $secret,
            'employee_totp_enabled_at' => now(),
            'employee_totp_recovery_codes' => [],
        ])->save();

        $login = $this->postJson(route('user.login'), [
            'email' => $user->email,
            'password' => 'CurrentPass1!',
        ])->assertStatus(202);

        $login->assertJsonPath('requires_mfa', true);
        $this->assertGuest('user');

        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->postJson(route('erp.mfa.challenge.verify'), ['code' => $code])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertAuthenticated('user');
    }

    public function test_authorized_management_can_reset_employee_totp_only_in_the_same_shop(): void
    {
        $shop = $this->shop();
        $actor = $this->employeeAccount($shop);
        $this->grant($actor, 'reset-employee-mfa');
        $target = $this->employeeAccount($shop);
        $targetEmployee = $this->employeeProfile($target);
        $target->forceFill([
            'employee_totp_secret' => app(EmployeeMfaService::class)->generateSecret(),
            'employee_totp_enabled_at' => now(),
            'employee_totp_recovery_codes' => ['hashed-code'],
        ])->save();
        $target->createToken('old-device');

        $this->actingAs($actor, 'user')
            ->postJson(route('hr.employees.reset_mfa', ['id' => $targetEmployee->getKey()]))
            ->assertOk();

        $freshTarget = $target->fresh();
        $this->assertFalse($freshTarget->hasEmployeeTotpEnabled());
        $this->assertNull($freshTarget->employee_totp_recovery_codes);
        $this->assertSame(2, (int) $freshTarget->security_version);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function shop(): ShopOwner
    {
        return ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function employeeAccount(ShopOwner $shop, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'employee-'.Str::lower(Str::random(12)).'@solespace.test',
            'password' => Hash::make('CurrentPass1!'),
            'shop_owner_id' => $shop->getKey(),
            'role' => 'STAFF',
            'status' => 'active',
            'force_password_change' => false,
        ], $attributes));
    }

    private function employeeProfile(User $user): Employee
    {
        return Employee::factory()->active()->create([
            'shop_owner_id' => $user->shop_owner_id,
            'email' => $user->email,
            'status' => 'active',
        ]);
    }

    private function grant(User $user, string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'user'));
        }
    }
}
