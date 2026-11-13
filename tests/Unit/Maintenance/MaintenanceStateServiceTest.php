<?php

namespace Tests\Unit\Maintenance;

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceWindow;
use App\Services\MaintenanceStateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MaintenanceStateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(config('platform_maintenance.cache_key', 'platform_maintenance.snapshot'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::forget(config('platform_maintenance.cache_key', 'platform_maintenance.snapshot'));
        parent::tearDown();
    }

    public function test_effective_state_uses_half_open_time_boundaries(): void
    {
        $service = app(MaintenanceStateService::class);
        $startsAt = Carbon::parse('2026-09-14 10:00:00', 'UTC');
        $endsAt = Carbon::parse('2026-09-14 11:00:00', 'UTC');
        $window = MaintenanceWindow::factory()->make([
            'status' => MaintenanceStatus::Scheduled,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        $this->assertSame('scheduled', $service->effectiveState($window, $startsAt->copy()->subSecond()));
        $this->assertSame('active', $service->effectiveState($window, $startsAt));
        $this->assertSame('active', $service->effectiveState($window, $endsAt->copy()->subSecond()));
        $this->assertSame('ended', $service->effectiveState($window, $endsAt));
    }

    public function test_terminal_status_overrides_timestamp_derived_state(): void
    {
        $service = app(MaintenanceStateService::class);
        $startsAt = Carbon::parse('2026-09-14 10:00:00', 'UTC');
        $endsAt = Carbon::parse('2026-09-14 11:00:00', 'UTC');

        $cancelled = MaintenanceWindow::factory()->make([
            'status' => MaintenanceStatus::Cancelled,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
        $ended = MaintenanceWindow::factory()->make([
            'status' => MaintenanceStatus::Ended,
            'starts_at' => $startsAt->copy()->addHour(),
            'ends_at' => $endsAt->copy()->addHour(),
        ]);

        $this->assertSame('cancelled', $service->effectiveState($cancelled, $startsAt));
        $this->assertSame('ended', $service->effectiveState($ended, $startsAt));
    }

    public function test_public_resolution_prefers_active_then_nearest_warned_schedule(): void
    {
        $now = Carbon::parse('2026-09-14 10:00:00', 'UTC');
        Carbon::setTestNow($now);

        $warned = MaintenanceWindow::factory()->scheduled()->create([
            'title' => 'Warned window',
            'starts_at' => $now->copy()->addMinutes(10),
            'ends_at' => $now->copy()->addMinutes(40),
            'notify_before_minutes' => 15,
        ]);
        MaintenanceWindow::factory()->scheduled()->create([
            'title' => 'Later warned window',
            'starts_at' => $now->copy()->addMinutes(20),
            'ends_at' => $now->copy()->addMinutes(50),
            'notify_before_minutes' => 15,
        ]);

        $service = app(MaintenanceStateService::class);
        $snapshot = $service->resolve($now);

        $this->assertSame('scheduled', $snapshot['state']);
        $this->assertSame($warned->id, $snapshot['window_id']);

        $active = MaintenanceWindow::factory()->active()->create([
            'starts_at' => $now->copy()->subMinute(),
            'ends_at' => $now->copy()->addMinutes(30),
        ]);
        Cache::forget(config('platform_maintenance.cache_key'));

        $snapshot = $service->resolve($now);

        $this->assertSame('active', $snapshot['state']);
        $this->assertSame($active->id, $snapshot['window_id']);
    }

    public function test_future_schedule_is_not_public_until_its_warning_threshold(): void
    {
        $now = Carbon::parse('2026-09-14 10:00:00', 'UTC');
        Carbon::setTestNow($now);
        MaintenanceWindow::factory()->scheduled()->create([
            'starts_at' => $now->copy()->addHour(),
            'ends_at' => $now->copy()->addHours(2),
            'notify_before_minutes' => 15,
        ]);

        $snapshot = app(MaintenanceStateService::class)->resolve($now);

        $this->assertSame('operational', $snapshot['state']);
        $this->assertNull($snapshot['window_id']);
    }

    public function test_snapshot_expiry_does_not_cross_the_next_freeze_boundary(): void
    {
        $now = Carbon::parse('2026-09-14 10:06:45', 'UTC');
        Carbon::setTestNow($now);
        MaintenanceWindow::factory()->scheduled()->create([
            'starts_at' => Carbon::parse('2026-09-14 10:10:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-09-14 10:40:00', 'UTC'),
            'notify_before_minutes' => 15,
            'transaction_freeze_minutes' => 3,
        ]);

        $snapshot = app(MaintenanceStateService::class)->resolve($now);

        $this->assertSame('2026-09-14T10:07:00.000000Z', $snapshot['next_transition_at']);
        $this->assertSame('2026-09-14T10:07:00.000000Z', $snapshot['expires_at']);
    }
}
