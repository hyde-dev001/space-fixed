<?php

namespace Tests\Feature\Maintenance;

use App\Models\MaintenanceWindow;
use App\Services\MaintenanceCommandLock;
use App\Services\MaintenanceStateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ReconcilePlatformMaintenanceTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::forget(config('platform_maintenance.cache_key', 'platform_maintenance.snapshot'));
        parent::tearDown();
    }

    public function test_reconciles_due_windows_at_canonical_boundaries_and_is_idempotent(): void
    {
        $now = Carbon::parse('2026-09-14 12:00:00', 'UTC');
        Carbon::setTestNow($now);

        $delayed = MaintenanceWindow::factory()->scheduled()->create([
            'starts_at' => $now->copy()->subHour(),
            'ends_at' => $now->copy()->subMinute(),
        ]);
        $active = MaintenanceWindow::factory()->active()->create([
            'starts_at' => $now->copy()->subMinutes(30),
            'ends_at' => $now->copy()->subMinute(),
        ]);
        $future = MaintenanceWindow::factory()->scheduled()->create([
            'starts_at' => $now->copy()->addHour(),
            'ends_at' => $now->copy()->addHours(2),
        ]);

        app(MaintenanceStateService::class)->resolve($now);

        $this->assertSame(0, Artisan::call('maintenance:reconcile'));

        $delayed = $delayed->fresh();
        $active = $active->fresh();

        $this->assertSame('ended', $delayed->status->value);
        $this->assertTrue($delayed->activated_at->equalTo($delayed->starts_at));
        $this->assertTrue($delayed->ended_at->equalTo($delayed->ends_at));
        $this->assertSame(3, $delayed->version);
        $this->assertSame('ended', $active->status->value);
        $this->assertTrue($active->ended_at->equalTo($active->ends_at));
        $this->assertSame(2, $active->version);
        $this->assertSame('scheduled', $future->fresh()->status->value);

        $this->assertSame(1, Activity::query()->where('event', 'platform_maintenance_activated')->count());
        $this->assertSame(2, Activity::query()->where('event', 'platform_maintenance_ended')->count());
        $this->assertNull(Activity::query()->where('event', 'platform_maintenance_activated')->first()->causer_id);

        $this->assertSame(0, Artisan::call('maintenance:reconcile'));
        $this->assertSame(1, Activity::query()->where('event', 'platform_maintenance_activated')->count());
        $this->assertSame(2, Activity::query()->where('event', 'platform_maintenance_ended')->count());

        $resolved = app(MaintenanceStateService::class)->resolve($now);
        $this->assertSame('operational', $resolved['state']);
    }
}
