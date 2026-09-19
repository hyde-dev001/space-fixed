<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\MaintenanceWindow;
use App\Models\SuperAdmin;
use App\Services\MaintenanceCommandLock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

class PlatformMaintenanceManagementTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(MaintenanceCommandLock::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->andReturnUsing(static fn (callable $callback): mixed => $callback());
        });
    }

    public function test_admin_can_view_maintenance_but_cannot_mutate_it(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.maintenance.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can_manage', false));

        $this->postJson(route('admin.maintenance.store'), [
            'title' => 'Maintenance',
            ])->assertForbidden();
    }

    public function test_maintenance_summary_and_filters_use_effective_state(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $now = Carbon::parse('2026-09-14 10:00:00', 'UTC');
        Carbon::setTestNow($now);

        MaintenanceWindow::factory()->active()->create([
            'starts_at' => $now->copy()->subHour(),
            'ends_at' => $now->copy()->subMinute(),
            'activated_at' => $now->copy()->subHour(),
        ]);

        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.maintenance.index', ['status' => 'ended']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('status_counts.operational', 1)
                ->where('status_counts.active', 0)
                ->where('status_counts.ended', 1)
                ->where('pagination.total', 1)
                ->where('history.0.state', 'ended')
                ->where('history.0.status', 'active'));

        Carbon::setTestNow();
    }

    public function test_super_admin_can_create_a_draft_and_schedule_it(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $this->actingAsCompletedPrivileged($admin);

        $this->postJson(route('admin.maintenance.store'), [
            'title' => 'Platform update',
            'public_message' => 'A short update is planned.',
        ])->assertOk()->assertJsonPath('success', true);

        $window = MaintenanceWindow::query()->firstOrFail();
        $startsAt = Carbon::parse('2026-09-15 10:00:00', 'UTC');

        $this->postJson(route('admin.maintenance.schedule', $window), [
            'starts_at' => $startsAt->toISOString(),
            'ends_at' => $startsAt->copy()->addHour()->toISOString(),
            'version' => $window->version,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('maintenance_windows', [
            'id' => $window->id,
            'status' => 'scheduled',
            'version' => 2,
        ]);
    }

    public function test_super_admin_can_create_a_scheduled_window_for_advance_user_notifications(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $now = Carbon::parse('2026-09-14 10:00:00', 'UTC');
        Carbon::setTestNow($now);

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.maintenance.store'), [
                'title' => 'Planned platform update',
                'public_message' => 'The platform will be briefly unavailable.',
                'starts_at' => $now->copy()->addHour()->toISOString(),
                'ends_at' => $now->copy()->addHours(2)->toISOString(),
                'notify_before_minutes' => 15,
                'transaction_freeze_minutes' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('window.status', 'scheduled');

        $this->assertDatabaseHas('maintenance_windows', [
            'title' => 'Planned platform update',
            'status' => 'scheduled',
            'starts_at' => $now->copy()->addHour()->toDateTimeString(),
            'ends_at' => $now->copy()->addHours(2)->toDateTimeString(),
        ]);
    }

    public function test_start_now_requires_recent_reauthentication(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson(route('admin.maintenance.start-now'), [
                'title' => 'Emergency maintenance',
                'public_message' => 'We are applying an urgent fix.',
                'ends_at' => now()->addHour()->toISOString(),
            ])->assertStatus(423);
    }

    public function test_admin_routes_remain_reachable_during_active_maintenance(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $now = Carbon::parse('2026-09-14 10:00:00', 'UTC');
        Carbon::setTestNow($now);
        MaintenanceWindow::factory()->active()->create([
            'starts_at' => $now->copy()->subMinute(),
            'ends_at' => $now->copy()->addMinutes(20),
        ]);

        $this->actingAsCompletedPrivileged($admin)
            ->get(route('admin.maintenance.index'))
            ->assertOk();

        Carbon::setTestNow();
    }
}
