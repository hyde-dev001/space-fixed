<?php

namespace Tests\Feature;

use App\Models\ShopOwner;
use App\Models\User;
use App\Models\HR\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class EmployeeMfaRestorationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_profile_includes_employee_security_state(): void
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);
        $employee = User::factory()->create([
            'shop_owner_id' => $shop->getKey(),
            'role' => 'STAFF',
            'status' => 'active',
        ]);

        $this->actingAs($employee, 'user')
            ->get(route('erp.profile'))
            ->assertInertia(fn ($page) => $page
                ->component('ERP/Profile')
                ->where('security.is_employee', true)
                ->where('security.totp_enabled', false)
            );
    }

    public function test_employee_can_start_mfa_setup_and_load_security_history(): void
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);
        $employee = User::factory()->create([
            'shop_owner_id' => $shop->getKey(),
            'role' => 'STAFF',
            'status' => 'active',
            'password' => Hash::make('current-password'),
        ]);

        $this->actingAs($employee, 'user')
            ->postJson(route('erp.security.totp.setup'), [
                'current_password' => 'current-password',
            ])
            ->assertOk()
            ->assertJsonStructure(['qr_code', 'manual_key', 'expires_at']);

        AuditLog::create([
            'shop_owner_id' => $shop->getKey(),
            'user_id' => $employee->getKey(),
            'module' => AuditLog::MODULE_EMPLOYEE,
            'action' => 'employee_password_changed',
            'entity_type' => User::class,
            'entity_id' => $employee->getKey(),
            'description' => 'Employee changed their password.',
            'severity' => AuditLog::SEVERITY_WARNING,
            'tags' => ['employee_security'],
        ]);

        $this->actingAs($employee, 'user')
            ->getJson(route('erp.security.activity'))
            ->assertOk()
            ->assertJsonPath('data.0.action', 'employee_password_changed')
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($employee, 'user')
            ->getJson(route('erp.security.sessions.index'))
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_employee_login_stops_at_the_mfa_challenge(): void
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);
        $employee = User::factory()->create([
            'shop_owner_id' => $shop->getKey(),
            'role' => 'STAFF',
            'status' => 'active',
            'password' => Hash::make('current-password'),
            'employee_totp_secret' => 'JBSWY3DPEHPK3PXP',
            'employee_totp_enabled_at' => now(),
        ]);

        $this->postJson(route('user.login'), [
            'email' => $employee->email,
            'password' => 'current-password',
        ])
            ->assertStatus(202)
            ->assertJson([
                'success' => false,
                'requires_mfa' => true,
            ])
            ->assertJsonMissing(['message' => 'Login successful!']);

        $this->assertGuest('user');
    }
}
