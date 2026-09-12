<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class PrivilegedProfileTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_profile_is_canonical_allowlisted_and_truthful_for_an_admin(): void
    {
        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
        ]);

        $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/profile')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('superAdmin/Settings/Profile')
                ->where('admin', [
                    'id' => $admin->id,
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'name' => 'Ada Lovelace',
                    'email' => 'ada@example.test',
                    'role' => 'admin',
                ])
                ->where('auth.super_admin.role', 'admin')
                ->where('auth.super_admin.capabilities', $admin->capabilities())
            );
    }

    public function test_super_admin_profile_label_and_capabilities_are_truthful_without_sensitive_identity_fields(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();

        $response = $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/profile');

        $response->assertInertia(fn (Assert $page): Assert => $page
            ->where('admin.role', SuperAdmin::ROLE_SUPER_ADMIN)
            ->where('auth.super_admin.role', SuperAdmin::ROLE_SUPER_ADMIN)
            ->where('auth.super_admin.capabilities', $admin->capabilities())
            ->missing('admin.password')
            ->missing('admin.mfa_secret')
            ->missing('admin.mfa_recovery_codes')
            ->missing('admin.bootstrap_marker')
            ->missing('admin.security_version')
            ->missing('admin.privileged_sessions')
        );
    }

    public function test_profile_route_is_the_only_privileged_profile_entry_and_legacy_password_method_is_not_registered(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('admin.profile');

        $this->assertNotNull($route);
        $this->assertSame('admin/profile', $route->uri());
        $this->assertNull(\Illuminate\Support\Facades\Route::getRoutes()->getByName('superAdmin.profile'));

        $actions = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->map(fn ($registeredRoute): string => $registeredRoute->getActionName())
            ->implode("\n");

        $this->assertStringNotContainsString('SuperAdminAuthController@updatePassword', $actions);
    }
}
