<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Http\Middleware\AttachPrivilegedCorrelationId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;
use App\Models\SuperAdmin;

final class PhaseFourSurfaceBoundaryTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_canonical_phase_four_read_routes_are_protected_and_do_not_render_retired_surfaces(): void
    {
        foreach ([
            'admin.notifications',
            'admin.profile',
            'admin.audit',
            'admin.security',
            'admin.system-monitoring',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertContains('GET', $route->methods(), $name);
            $this->assertContains(AttachPrivilegedCorrelationId::class, $route->middleware(), $name);
            $this->assertContains('super_admin.auth', $route->middleware(), $name);
            $this->assertContains('privileged.active', $route->middleware(), $name);
            $this->assertContains('privileged.mfa', $route->middleware(), $name);
            $this->assertStringNotContainsString('NotificationCommunicationTools', $route->getActionName(), $name);
        }
    }

    public function test_legacy_communications_get_redirects_to_the_real_notification_inbox(): void
    {
        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->get('/superAdmin/notification-communication-tools')
            ->assertRedirect(route('admin.notifications'));
    }

    public function test_removed_fake_and_unsafe_mutation_handlers_are_not_registered(): void
    {
        $actions = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->getActionName())
            ->implode("\n");

        foreach ([
            'approveUser',
            'rejectUser',
            'deactivateUser',
            'resetUserPassword',
            'NotificationCommunicationTools',
            'subscriptions.upgrade',
            'subscriptions.downgrade',
        ] as $retiredAction) {
            $this->assertStringNotContainsString($retiredAction, $actions, $retiredAction);
        }

        foreach ([
            'admin.subscriptions.upgrade',
            'admin.subscriptions.downgrade',
        ] as $name) {
            $this->assertNull(Route::getRoutes()->getByName($name), $name);
        }
    }

    public function test_data_report_compatibility_routes_are_read_only_redirects(): void
    {
        foreach ([
            '/admin/data-reports',
            '/superAdmin/data-report-access',
        ] as $uri) {
            $routes = collect(Route::getRoutes()->getRoutes())
                ->filter(fn ($route): bool => '/'.$route->uri() === $uri);

            $this->assertNotEmpty($routes, $uri);
            foreach ($routes as $route) {
                $this->assertSame(['GET', 'HEAD'], $route->methods(), $uri);
                $this->assertStringNotContainsString('DataReportAccess', $route->getActionName(), $uri);
                $this->assertStringNotContainsString('export', strtolower($route->getActionName()), $uri);
            }
        }
    }
}
