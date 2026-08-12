<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\AuditLog;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class SystemMonitoringDashboardTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_monitoring_reads_recent_privileged_activity_not_legacy_audit_logs(): void
    {
        $viewer = SuperAdmin::factory()->superAdmin()->create();
        $user = User::factory()->create();

        AuditLog::query()->create([
            'action' => 'user_suspended',
            'target_type' => 'user',
            'target_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->activity('user_reactivated', $viewer, User::class, $user->id, [
            'prior_status' => 'suspended',
            'new_status' => 'active',
        ]);

        $this->actingAsCompletedPrivileged($viewer)
            ->get('/admin/system-monitoring')
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.recent_activity.0.activity', 'User reactivated')
                ->where('dashboard.recent_activity.0.status', 'Info')
            );
    }

    public function test_monitoring_controller_does_not_reference_the_legacy_audit_source(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/superAdmin/SystemMonitoringDashboardController.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('AuditLog', $source);
        $this->assertStringContainsString("where('log_name', 'privileged')", file_get_contents(app_path('Services/PrivilegedAuditVisibility.php')));
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function activity(
        string $event,
        SuperAdmin $actor,
        string $subjectType,
        int $subjectId,
        array $properties = [],
    ): Activity {
        $timestamp = '2026-08-12 12:00:00';
        $baseProperties = [
            'event' => $event,
            'source' => 'http',
            'correlation_id' => (string) Str::uuid(),
            'actor_type' => 'super_admin',
            'actor_guard' => 'super_admin',
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'target_type' => class_basename($subjectType),
            'target_id' => $subjectId,
            'ip_address' => '198.51.100.2',
        ];

        return Activity::query()->create([
            'log_name' => 'privileged',
            'description' => $event,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'causer_type' => SuperAdmin::class,
            'causer_id' => $actor->id,
            'properties' => array_merge($baseProperties, $properties),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
