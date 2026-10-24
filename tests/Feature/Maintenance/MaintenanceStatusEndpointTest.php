<?php

namespace Tests\Feature\Maintenance;

use App\Models\MaintenanceWindow;
use App\Services\MaintenanceStateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MaintenanceStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::forget(config('platform_maintenance.cache_key', 'platform_maintenance.snapshot'));
        parent::tearDown();
    }

    public function test_active_status_returns_only_the_safe_projection(): void
    {
        $now = Carbon::parse('2026-09-14 10:00:00', 'UTC');
        Carbon::setTestNow($now);
        MaintenanceWindow::factory()->active()->create([
            'title' => 'Database upgrade',
            'public_message' => 'We are applying an update.',
            'internal_note' => 'Do not expose this note.',
            'progress_stage' => 'Maintenance in Progress',
            'public_update_message' => 'The migration is running.',
            'public_update_updated_at' => $now->copy()->subMinute(),
            'starts_at' => $now->copy()->subMinutes(5),
            'ends_at' => $now->copy()->addMinutes(25),
        ]);

        $response = $this->getJson(route('system.maintenance-status'));

        $response->assertOk()
            ->assertJsonPath('state', 'active')
            ->assertJsonPath('title', 'Database upgrade')
            ->assertJsonPath('message', 'We are applying an update.')
            ->assertJsonStructure([
                'id',
                'state',
                'title',
                'message',
                'progress_stage',
                'update_message',
                'update_message_updated_at',
                'starts_at',
                'ends_at',
                'notify_before_minutes',
                'transaction_freeze_minutes',
                'server_time',
            ])
            ->assertJsonMissingPath('internal_note')
            ->assertJsonMissingPath('created_by')
            ->assertJsonMissingPath('version');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_warned_scheduled_status_is_public_but_future_windows_remain_operational(): void
    {
        $now = Carbon::parse('2026-09-14 10:00:00', 'UTC');
        Carbon::setTestNow($now);
        MaintenanceWindow::factory()->scheduled()->create([
            'starts_at' => $now->copy()->addMinutes(10),
            'ends_at' => $now->copy()->addMinutes(40),
            'notify_before_minutes' => 15,
        ]);

        $this->getJson(route('system.maintenance-status'))
            ->assertOk()
            ->assertJsonPath('state', 'scheduled');

        Cache::forget(config('platform_maintenance.cache_key'));
        MaintenanceWindow::query()->delete();
        MaintenanceWindow::factory()->scheduled()->create([
            'starts_at' => $now->copy()->addHour(),
            'ends_at' => $now->copy()->addHours(2),
        ]);

        $this->getJson(route('system.maintenance-status'))
            ->assertOk()
            ->assertJson(['state' => 'operational'])
            ->assertJsonMissingPath('starts_at');
    }

    public function test_unavailable_resolution_returns_safe_503_without_claiming_restoration(): void
    {
        $this->mock(MaintenanceStateService::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->andThrow(new \RuntimeException('database unavailable'));
        });

        $response = $this->getJson(route('system.maintenance-status'))
            ->assertStatus(503)
            ->assertJson(['state' => 'unavailable'])
            ->assertJsonMissing(['state' => 'operational']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
