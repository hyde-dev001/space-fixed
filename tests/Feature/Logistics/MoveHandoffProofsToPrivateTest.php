<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\HandoffProof;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class MoveHandoffProofsToPrivateTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_moves_public_proofs_without_changing_bytes_or_paths_and_can_run_twice(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $path = 'logistics-proof/10/original.png';
        $proof = HandoffProof::factory()->create(['file_path' => $path]);
        Storage::disk('public')->put($path, 'original-proof-bytes');

        $this->artisan('logistics:move-handoff-proofs-private')
            ->expectsOutputToContain('Moved: 1')
            ->assertSuccessful();

        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame('original-proof-bytes', Storage::disk('local')->get($path));
        $this->assertSame($path, $proof->fresh()->file_path);

        $this->artisan('logistics:move-handoff-proofs-private')
            ->expectsOutputToContain('Already private: 1')
            ->assertSuccessful();
    }

    public function test_command_removes_only_matching_public_duplicates_and_reports_conflicts_and_missing_files(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $matching = 'logistics-proof/20/matching.png';
        $conflict = 'logistics-proof/21/conflict.png';
        $missing = 'logistics-proof/22/missing.png';
        HandoffProof::factory()->create(['file_path' => $matching]);
        HandoffProof::factory()->create(['file_path' => $conflict]);
        HandoffProof::factory()->create(['file_path' => $missing]);
        Storage::disk('local')->put($matching, 'same');
        Storage::disk('public')->put($matching, 'same');
        Storage::disk('local')->put($conflict, 'private');
        Storage::disk('public')->put($conflict, 'public');

        $this->artisan('logistics:move-handoff-proofs-private')
            ->expectsOutputToContain('Duplicates removed: 1')
            ->expectsOutputToContain('Conflicts: 1')
            ->expectsOutputToContain('Missing: 1')
            ->assertFailed();

        Storage::disk('public')->assertMissing($matching);
        $this->assertSame('private', Storage::disk('local')->get($conflict));
        $this->assertSame('public', Storage::disk('public')->get($conflict));
        Storage::disk('local')->assertMissing($missing);
        Storage::disk('public')->assertMissing($missing);
    }

    public function test_command_keeps_public_original_when_private_verification_fails(): void
    {
        $path = 'logistics-proof/30/original.png';
        HandoffProof::factory()->create(['file_path' => $path]);
        $public = Mockery::mock();
        $private = Mockery::mock();

        Storage::shouldReceive('disk')->with('public')->andReturn($public);
        Storage::shouldReceive('disk')->with('local')->andReturn($private);
        $public->shouldReceive('exists')->with($path)->andReturnTrue();
        $private->shouldReceive('exists')->with($path)->andReturnFalse();
        $public->shouldReceive('get')->with($path)->andReturn('original');
        $private->shouldReceive('put')->with($path, 'original')->andReturnTrue();
        $private->shouldReceive('get')->with($path)->andReturn('corrupted');
        $private->shouldReceive('delete')->with($path)->once()->andReturnTrue();
        $public->shouldNotReceive('delete');

        $this->artisan('logistics:move-handoff-proofs-private')
            ->expectsOutputToContain('Failed: 1')
            ->assertFailed();
    }

    public function test_restore_public_verifies_bytes_keeps_private_and_refuses_conflicts(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $path = 'logistics-proof/40/original.png';
        HandoffProof::factory()->create(['file_path' => $path]);
        Storage::disk('local')->put($path, 'private-original');

        $this->artisan('logistics:move-handoff-proofs-private', ['--restore-public' => true])
            ->expectsOutputToContain('Restored: 1')
            ->assertSuccessful();

        $this->assertSame('private-original', Storage::disk('local')->get($path));
        $this->assertSame('private-original', Storage::disk('public')->get($path));

        Storage::disk('public')->put($path, 'different-public-copy');
        $this->artisan('logistics:move-handoff-proofs-private', ['--restore-public' => true])
            ->expectsOutputToContain('Conflicts: 1')
            ->assertFailed();

        $this->assertSame('private-original', Storage::disk('local')->get($path));
        $this->assertSame('different-public-copy', Storage::disk('public')->get($path));
    }
}
