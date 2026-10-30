<?php

namespace Tests\Feature\Auth;

use App\Models\ShopOwner;
use App\Models\User;
use App\Services\EmployeeMfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeMfaLoginFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private function employee(string $legacy = 'STAFF', string $role = 'Staff'): User
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'company', 'business_type' => 'both']);
        $user = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => $legacy, 'status' => 'active',
            'password' => 'Local-Mfa-Test-72!', 'force_password_change' => false,
            'employee_totp_secret' => self::SECRET, 'employee_totp_enabled_at' => now()]);
        Role::findOrCreate($role, 'user');
        $user->assignRole($role);
        return $user;
    }

    private function passwordLogin(User $user): void
    {
        $this->postJson(route('user.login'), ['email' => $user->email, 'password' => 'Local-Mfa-Test-72!'])
            ->assertStatus(202)->assertJsonPath('requires_mfa', true);
        $this->assertGuest('user');
    }

    public static function employeeRoles(): array
    {
        return [['STAFF', 'Logistics Dispatcher'], ['STAFF', 'Logistics Rider'], ['STAFF', 'Staff'],
            ['MANAGER', 'Manager'], ['FINANCE', 'Finance'], ['HR', 'HR'], ['REPAIRER', 'Repairer']];
    }

    #[DataProvider('employeeRoles')]
    public function test_password_login_reaches_challenge_and_requires_second_factor(string $legacy, string $role): void
    {
        $user = $this->employee($legacy, $role);
        $this->passwordLogin($user);
        $this->get(route('erp.mfa.challenge'))->assertOk()->assertInertia(fn ($page) => $page
            ->component('ERP/EmployeeMfaChallenge')->where('companyAccount', $user->email));
        $this->getJson(route('erp.profile'))->assertUnauthorized();
        $this->postJson(route('erp.mfa.challenge.verify'), ['code' => 'invalid'])->assertUnprocessable();
        $this->assertGuest('user');
        $this->postJson(route('erp.mfa.challenge.verify'), ['code' => app(Google2FA::class)->getCurrentOtp(self::SECRET)])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertAuthenticatedAs($user, 'user');
        $this->get(route('erp.time-in'))->assertOk();
        $this->assertTrue($user->fresh()->hasRole($role));
    }

    public function test_challenge_without_a_pending_login_cannot_authenticate(): void
    {
        $this->get(route('erp.mfa.challenge'))->assertRedirect(route('login'));
        $this->postJson(route('erp.mfa.challenge.verify'), ['code' => '123456'])->assertUnprocessable();
        $this->assertGuest('user');
    }

    public function test_revoked_pending_session_cannot_complete_mfa(): void
    {
        $user = $this->employee();
        $this->passwordLogin($user);
        $user->increment('security_version');
        $this->get(route('erp.mfa.challenge'))->assertRedirect(route('login'));
        $this->postJson(route('erp.mfa.challenge.verify'), ['code' => app(Google2FA::class)->getCurrentOtp(self::SECRET)])->assertUnprocessable();
        $this->assertGuest('user');
    }

    public function test_inactive_employee_cannot_finish_a_pending_challenge(): void
    {
        $user = $this->employee();
        $this->passwordLogin($user);
        $user->update(['status' => 'inactive']);
        $this->postJson(route('erp.mfa.challenge.verify'), ['code' => app(Google2FA::class)->getCurrentOtp(self::SECRET)])->assertUnprocessable();
        $this->assertGuest('user');
    }

    public function test_recovery_code_is_consumed_once_and_five_invalid_attempts_clear_the_challenge(): void
    {
        $user = $this->employee();
        $recovery = 'ABCD-1234-EF56-7890-ABCD';
        $user->forceFill(['employee_totp_recovery_codes' => app(EmployeeMfaService::class)->hashRecoveryCodes([$recovery])])->save();
        $this->passwordLogin($user);
        $this->postJson(route('erp.mfa.challenge.verify'), ['code' => $recovery])->assertOk();
        $this->assertSame([], $user->fresh()->employee_totp_recovery_codes);
        auth('user')->logout();
        $this->passwordLogin($user);
        $this->postJson(route('erp.mfa.challenge.verify'), ['code' => $recovery])->assertUnprocessable();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('erp.mfa.challenge.verify'), ['code' => 'invalid'])->assertUnprocessable();
        }
        $this->get(route('erp.mfa.challenge'))->assertRedirect(route('login'));
        $this->assertGuest('user');
    }
}
