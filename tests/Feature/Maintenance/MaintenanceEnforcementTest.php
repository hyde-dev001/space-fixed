<?php

namespace Tests\Feature\Maintenance;

use App\Models\MaintenanceWindow;
use App\Services\MaintenanceStateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MaintenanceEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/maintenance-test/protected', fn () => response('allowed'))
            ->name('maintenance.test.protected');
        Route::middleware('web')->get('/maintenance-test/admin-bypass', fn () => response('admin allowed'))
            ->name('admin.test.bypass');
        Route::middleware('web')->post('/maintenance-test/frozen', fn () => response('created'))
            ->name('maintenance.test.frozen');
        Route::middleware('web')->post('/maintenance-test/frozen/extra', fn () => response('created'))
            ->name('maintenance.test.frozen.extra');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::forget(config('platform_maintenance.cache_key', 'platform_maintenance.snapshot'));
        parent::tearDown();
    }

    public function test_active_browser_navigation_receives_maintenance_response(): void
    {
        $now = Carbon::parse('2026-09-14 10:00:00', 'UTC');
        Carbon::setTestNow($now);
        MaintenanceWindow::factory()->active()->create([
            'starts_at' => $now->copy()->subMinute(),
            'ends_at' => $now->copy()->addMinutes(20),
        ]);

        $this->get('/maintenance-test/protected')
            ->assertStatus(503)
            ->assertHeader('X-SoleSpace-Maintenance', 'active')
            ->assertSee('Maintenance');
    }

    public function test_active_json_request_receives_safe_503_metadata(): void
    {
        $now = Carbon::parse('2026-09-14 10:00:00', 'UTC');
        Carbon::setTestNow($now);
        MaintenanceWindow::factory()->active()->create([
            'starts_at' => $now->copy()->subMinute(),
            'ends_at' => $now->copy()->addMinutes(20),
        ]);

        $this->withHeaders(['Accept' => 'application/json'])
            ->get('/maintenance-test/protected')
            ->assertStatus(503)
            ->assertHeader('X-SoleSpace-Maintenance', 'active')
            ->assertHeader('Retry-After', '1200')
            ->assertJsonPath('code', 'MAINTENANCE_ACTIVE')
            ->assertJsonPath('state', 'active');
    }

    public function test_only_the_exact_freeze_route_is_blocked(): void
    {
        config(['platform_maintenance.critical_route_names' => ['maintenance.test.frozen']]);
        $now = Carbon::parse('2026-09-14 10:00:00', 'UTC');
        Carbon::setTestNow($now);
        MaintenanceWindow::factory()->scheduled()->create([
            'starts_at' => $now->copy()->addMinutes(4),
            'ends_at' => $now->copy()->addMinutes(34),
            'transaction_freeze_minutes' => 5,
        ]);

        $snapshot = app(MaintenanceStateService::class)->resolve($now);
        $this->assertTrue(app(MaintenanceStateService::class)->isFreezeActive($snapshot, $now));

        $this->post('/maintenance-test/frozen')
            ->assertStatus(409)
            ->assertJsonPath('code', 'MAINTENANCE_FREEZE_ACTIVE');
        $this->post('/maintenance-test/frozen/extra')->assertOk();
    }

    public function test_admin_bypass_remains_reachable_during_active_maintenance(): void
    {
        $now = Carbon::parse('2026-09-14 10:00:00', 'UTC');
        Carbon::setTestNow($now);
        MaintenanceWindow::factory()->active()->create([
            'starts_at' => $now->copy()->subMinute(),
            'ends_at' => $now->copy()->addMinutes(20),
        ]);

        $this->get('/maintenance-test/admin-bypass')->assertOk()->assertSee('admin allowed');
    }

    public function test_state_resolution_failure_fails_closed(): void
    {
        $this->mock(\App\Services\MaintenanceStateService::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->andThrow(new \RuntimeException('database unavailable'));
        });

        $this->get('/maintenance-test/protected')
            ->assertStatus(503)
            ->assertJsonPath('code', 'MAINTENANCE_UNAVAILABLE')
            ->assertJsonPath('state', 'unavailable');
    }
}
