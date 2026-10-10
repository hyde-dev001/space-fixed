<?php

namespace Tests\Feature\Maintenance;

use App\Enums\MaintenanceStatus;
use App\Exceptions\MaintenanceConflictException;
use App\Models\MaintenanceWindow;
use App\Models\SuperAdmin;
use App\Services\MaintenanceCommandLock;
use App\Services\MaintenanceLifecycleService;
use App\Services\PrivilegedAudit;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class MaintenanceLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(MaintenanceCommandLock::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->andReturnUsing(static fn (callable $callback): mixed => $callback());
        });
    }

    public function test_draft_can_be_scheduled_and_audited_with_a_single_version_bump(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $request = Request::create('/admin/maintenance', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);
        $service = app(MaintenanceLifecycleService::class);
        $startsAt = Carbon::parse('2026-09-15 10:00:00', 'UTC');
        $endsAt = $startsAt->copy()->addHour();
        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00', 'UTC'));

        $draft = $service->createDraft($admin, [
            'title' => 'Planned maintenance',
            'public_message' => 'The platform will be updated.',
        ], $request);

        $scheduled = $service->schedule($admin, $draft, $startsAt, $endsAt, 1, $request);

        $this->assertSame(MaintenanceStatus::Scheduled, $scheduled->status);
        $this->assertSame(2, $scheduled->version);
        $this->assertDatabaseHas('maintenance_windows', [
            'id' => $draft->id,
            'status' => MaintenanceStatus::Scheduled->value,
            'version' => 2,
        ]);
        $this->assertSame(1, Activity::query()->where('event', 'platform_maintenance_created')->count());
        $this->assertSame(1, Activity::query()->where('event', 'platform_maintenance_scheduled')->count());
    }

    public function test_scheduling_rejects_stale_version_and_overlapping_nonterminal_windows(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $service = app(MaintenanceLifecycleService::class);
        $now = Carbon::parse('2026-09-14 10:00:00', 'UTC');
        Carbon::setTestNow($now);
        $draft = MaintenanceWindow::factory()->draft()->create();

        $this->expectException(MaintenanceConflictException::class);
        $this->expectExceptionMessage('stale_version');
        $service->schedule(
            $admin,
            $draft,
            $now->copy()->addHour(),
            $now->copy()->addHours(2),
            99,
            Request::create('/admin/maintenance', 'POST'),
        );
    }

    public function test_scheduling_rejects_overlapping_nonterminal_windows(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $service = app(MaintenanceLifecycleService::class);
        $now = Carbon::parse('2026-09-14 10:00:00', 'UTC');
        Carbon::setTestNow($now);
        $startsAt = $now->copy()->addHour();
        $endsAt = $startsAt->copy()->addHour();
        MaintenanceWindow::factory()->scheduled()->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
        $draft = MaintenanceWindow::factory()->draft()->create();

        $this->expectException(MaintenanceConflictException::class);
        $this->expectExceptionMessage('overlap');
        $service->schedule($admin, $draft, $startsAt, $endsAt, 1, Request::create('/admin/maintenance', 'POST'));
    }

    public function test_start_now_uses_one_server_timestamp_for_activation(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $service = app(MaintenanceLifecycleService::class);
        $now = Carbon::parse('2026-09-14 10:15:00', 'UTC');
        Carbon::setTestNow($now);

        $window = $service->startNow($admin, [
            'title' => 'Emergency maintenance',
            'public_message' => 'We are restoring service.',
            'ends_at' => $now->copy()->addHour(),
        ], Request::create('/admin/maintenance/start-now', 'POST'));

        $this->assertSame(MaintenanceStatus::Active, $window->status);
        $this->assertTrue($window->starts_at->equalTo($now));
        $this->assertTrue($window->activated_at->equalTo($now));
        $this->assertSame($window->starts_at->toISOString(), $window->activated_at->toISOString());
        $this->assertSame(1, $window->version);
    }

    public function test_effectively_ended_scheduled_window_cannot_be_cancelled(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $service = app(MaintenanceLifecycleService::class);
        $now = Carbon::parse('2026-09-14 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        $window = MaintenanceWindow::factory()->scheduled()->create([
            'starts_at' => $now->copy()->subHour(),
            'ends_at' => $now->copy()->subMinute(),
        ]);

        $this->expectException(MaintenanceConflictException::class);
        $this->expectExceptionMessage('invalid_state');
        $service->cancel($admin, $window, 1, Request::create('/admin/maintenance', 'POST'));
    }

    public function test_repeated_end_is_idempotent_without_duplicate_audit(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $service = app(MaintenanceLifecycleService::class);
        $window = MaintenanceWindow::factory()->active()->create();
        $request = Request::create('/admin/maintenance/end', 'POST');

        $result = $service->end($admin, $window, 1, $request);
        $service->end($admin, $result, 2, $request);

        $this->assertSame(MaintenanceStatus::Ended, $result->status);
        $this->assertSame(1, Activity::query()->where('event', 'platform_maintenance_ended')->count());
    }

    public function test_audit_failure_rolls_back_the_lifecycle_mutation(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $window = MaintenanceWindow::factory()->draft()->create();
        $audit = Mockery::mock(PrivilegedAudit::class);
        $audit->shouldReceive('platformMaintenanceChanged')->andThrow(new \RuntimeException('audit unavailable'));
        $this->app->instance(PrivilegedAudit::class, $audit);

        try {
            app(MaintenanceLifecycleService::class)->schedule(
                $admin,
                $window,
                Carbon::parse('2026-09-15 10:00:00', 'UTC'),
                Carbon::parse('2026-09-15 11:00:00', 'UTC'),
                1,
                Request::create('/admin/maintenance', 'POST'),
            );
            $this->fail('Expected the audit failure to bubble out.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }

        $this->assertSame(MaintenanceStatus::Draft, $window->refresh()->status);
        $this->assertSame(1, $window->version);
    }
}
