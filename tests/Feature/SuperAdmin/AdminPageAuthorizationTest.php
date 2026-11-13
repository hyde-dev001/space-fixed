<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\AdminPage;
use App\Models\AdminPagePermission;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class AdminPageAuthorizationTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_regular_admin_can_open_the_common_dashboard_without_a_permission_row(): void
    {
        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/system-monitoring')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('superAdmin/SystemMonitoringDashboard'));
    }

    public function test_regular_admin_with_dashboard_access_can_open_the_page(): void
    {
        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        AdminPagePermission::grant($admin, AdminPage::DASHBOARD);

        $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/system-monitoring')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('superAdmin/SystemMonitoringDashboard'));
    }

    public function test_super_admin_does_not_need_page_permission_rows(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/system-monitoring')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('superAdmin/SystemMonitoringDashboard'));
    }

    public function test_page_access_is_revoked_on_the_next_request_without_logout(): void
    {
        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        AdminPagePermission::grant($admin, AdminPage::PLATFORM_FEES);

        $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/platform-fees')
            ->assertSuccessful();

        AdminPagePermission::query()
            ->where('super_admin_id', $admin->id)
            ->where('page_key', AdminPage::PLATFORM_FEES->value)
            ->delete();

        $this->get('/admin/platform-fees')->assertForbidden();
    }

    public function test_super_admin_can_replace_regular_admin_page_access_atomically_and_audit_the_change(): void
    {
        $actor = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $target = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        AdminPagePermission::grant($target, AdminPage::DASHBOARD);
        $this->actingAsCompletedPrivileged($actor);
        $this->markRecentlyReauthenticated($actor);

        $this->patchJson("/admin/administrators/{$target->id}/page-access", [
            'page_access' => [AdminPage::PLATFORM_FEES->value, AdminPage::REGISTERED_SHOPS->value],
        ])
            ->assertOk()
            ->assertJsonPath('page_access', [
                AdminPage::PLATFORM_FEES->value,
                AdminPage::REGISTERED_SHOPS->value,
            ]);

        self::assertSame([
            AdminPage::PLATFORM_FEES->value,
            AdminPage::REGISTERED_SHOPS->value,
        ], $target->fresh()->pagePermissions()->orderBy('page_key')->pluck('page_key')->all());

        $activity = Activity::query()
            ->where('event', 'privileged_admin_page_access_changed')
            ->latest('id')
            ->first();

        self::assertNotNull($activity);
        self::assertSame($target->id, (int) $activity->subject_id);
        self::assertSame([
            AdminPage::DASHBOARD->value,
        ], $activity->properties['old_page_access']);
        self::assertSame([
            AdminPage::PLATFORM_FEES->value,
            AdminPage::REGISTERED_SHOPS->value,
        ], $activity->properties['new_page_access']);
    }

    public function test_page_access_update_rejects_unknown_and_super_admin_only_keys(): void
    {
        $actor = SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
        $target = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        AdminPagePermission::grant($target, AdminPage::DASHBOARD);
        $this->actingAsCompletedPrivileged($actor);
        $this->markRecentlyReauthenticated($actor);

        $this->patchJson("/admin/administrators/{$target->id}/page-access", [
            'page_access' => ['admin_management'],
        ])->assertUnprocessable();

        self::assertSame([
            AdminPage::DASHBOARD->value,
        ], $target->fresh()->pagePermissions()->pluck('page_key')->all());

        $this->patchJson("/admin/administrators/{$target->id}/page-access", [
            'page_access' => ['unknown_page'],
        ])->assertUnprocessable();
    }

    public function test_regular_admin_cannot_change_any_admin_page_access(): void
    {
        $actor = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $target = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $this->actingAsCompletedPrivileged($actor);
        $this->markRecentlyReauthenticated($actor);

        $this->patchJson("/admin/administrators/{$target->id}/page-access", [
            'page_access' => [AdminPage::PLATFORM_FEES->value],
        ])->assertForbidden();

        self::assertDatabaseMissing('admin_page_permissions', [
            'super_admin_id' => $target->id,
            'page_key' => AdminPage::PLATFORM_FEES->value,
        ]);
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
