<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\ShipmentLeg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MoveLogisticsAttemptEvidenceToPrivateTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_moves_attempt_evidence_without_changing_paths_and_is_idempotent(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $leg = ShipmentLeg::factory()->create();
        $path = "logistics-attempt/{$leg->id}/original.jpg";
        $attempt = $leg->attempts()->create([
            'attempt_type' => 'delivery',
            'status' => 'failed',
            'reason_code' => 'recipient_unavailable',
            'file_path' => $path,
            'attempted_at' => now(),
        ]);
        Storage::disk('public')->put($path, 'attempt-evidence');

        $this->artisan('logistics:migrate-attempt-evidence')
            ->expectsOutputToContain('Migrated: 1')
            ->assertSuccessful();

        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame('attempt-evidence', Storage::disk('local')->get($path));
        $this->assertSame($path, $attempt->fresh()->file_path);

        $this->artisan('logistics:migrate-attempt-evidence')
            ->expectsOutputToContain('Already private: 1')
            ->assertSuccessful();
    }

    public function test_dry_run_reports_public_attempts_without_mutating_storage(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $leg = ShipmentLeg::factory()->create();
        $path = "logistics-attempt/{$leg->id}/dry-run.jpg";
        $leg->attempts()->create([
            'attempt_type' => 'delivery',
            'status' => 'failed',
            'reason_code' => 'recipient_unavailable',
            'file_path' => $path,
            'attempted_at' => now(),
        ]);
        Storage::disk('public')->put($path, 'dry-run-evidence');

        $this->artisan('logistics:migrate-attempt-evidence', ['--dry-run' => true])
            ->expectsOutputToContain('Would migrate: 1')
            ->assertSuccessful();

        Storage::disk('public')->assertExists($path);
        Storage::disk('local')->assertMissing($path);
    }
}
