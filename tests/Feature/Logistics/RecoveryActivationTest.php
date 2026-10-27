<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryIncident;
use App\Models\User;
use App\Services\Logistics\BatchDispatchService;
use App\Services\Logistics\ShipmentCustodyService;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Tests\Support\BatchInterruptionFixture;
use Tests\TestCase;

class RecoveryActivationTest extends TestCase
{
    use RefreshDatabase, BatchInterruptionFixture;

    public function test_replacement_batch_requires_its_current_stop_start_and_then_continues_after_expiry(): void
    {
        $f = $this->interruptionFixture('in_progress', 1, shopLocalTime: true);
        $leg = $f['legs'][0];
        $leg->update(['customer_appointment_date' => now()->toDateString(), 'customer_appointment_window' => 'morning', 'out_for_delivery_at' => now()->subMinutes(20)]);
        $this->reportInterruption($f)->assertCreated();
        $incident = DeliveryIncident::sole();
        $this->actingAs($f['dispatcher'], 'user')->postJson("/api/logistics/incidents/{$incident->id}/resolve", [
            'resolution' => 'reassign', 'note' => 'Authorized recovery', 'expected_revision' => 0, 'decision_key' => (string) Str::uuid(),
            'rider_profile_id' => $f['b']->id, 'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning', 'handoff_route' => 'direct',
        ])->assertOk();
        $replacement = $incident->fresh()->recoveryBatch;
        app(BatchDispatchService::class)->accept($replacement, $f['b']);
        $transfer = $leg->custodyTransfers()->where('status', 'requested')->sole();
        Storage::disk('local')->put('logistics-proof/recovery.jpg', 'actual handoff evidence');
        $custody = app(ShipmentCustodyService::class);
        $custody->release($transfer, User::findOrFail($f['a']->linked_id), 'logistics-proof/recovery.jpg');
        $custody->receive($transfer->fresh(), User::findOrFail($f['b']->linked_id), 'logistics-proof/recovery.jpg');
        $this->travelTo(now(config('app.shop_timezone', 'Asia/Manila'))->setTime(11, 59));
        app(BatchDispatchService::class)->start($replacement->fresh(), $f['b']);
        $rider = User::findOrFail($f['b']->linked_id);
        $this->actingAs($rider, 'user')->postJson("/api/logistics/legs/{$leg->id}/arrivals", ['arrival_type' => 'dropoff', 'exception_reason' => 'pin_incorrect'])
            ->assertUnprocessable()->assertJsonValidationErrors('activation');
        app(ShipmentLegService::class)->markOutForDelivery($leg->fresh(), $f['b']);
        $this->travelTo(now()->addMinutes(3));
        $this->postJson("/api/logistics/legs/{$leg->id}/arrivals", ['arrival_type' => 'dropoff', 'exception_reason' => 'pin_incorrect'])->assertCreated();
        $f['dispatcher']->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('approve-proof-of-delivery', 'user'));
        $first = $this->post("/api/logistics/legs/{$leg->id}/proof", ['handoff_type' => 'delivery', 'proof_type' => 'photo',
            'idempotency_key' => (string) Str::uuid(), 'proof_file' => UploadedFile::fake()->image('first.jpg')], ['Accept' => 'application/json'])->assertCreated()->json('proof.id');
        $this->actingAs($f['dispatcher'], 'user')->postJson("/api/logistics/proofs/{$first}/reject", ['rejection_reason' => 'Unreadable image'])->assertOk();
        $second = $this->actingAs($rider, 'user')->post("/api/logistics/legs/{$leg->id}/proof", ['handoff_type' => 'delivery', 'proof_type' => 'photo',
            'idempotency_key' => (string) Str::uuid(), 'replaces_proof_id' => $first, 'proof_file' => UploadedFile::fake()->image('corrected.jpg')], ['Accept' => 'application/json'])->assertCreated()->json('proof.id');
        $this->actingAs($f['dispatcher'], 'user')->postJson("/api/logistics/proofs/{$second}/approve")->assertOk();
        $this->assertSame('delivered', $leg->fresh()->status->value);
        $this->assertSame(1, $leg->events()->where('event_type', 'out_for_delivery')
            ->where('metadata->delivery_assignment_id', $leg->assignments()->latest('id')->value('id'))->count());
    }

    public function test_unstarted_replacement_can_be_reauthorized_after_immediate_expiry_with_fresh_acceptance(): void
    {
        $f = $this->interruptionFixture('in_progress', 1, shopLocalTime: true);
        $this->reportInterruption($f)->assertCreated();
        $incident = DeliveryIncident::sole();
        $data = ['resolution' => 'reassign', 'note' => 'Verified bounded recovery', 'expected_revision' => 0, 'decision_key' => (string) Str::uuid(),
            'rider_profile_id' => $f['a']->id, 'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning', 'handoff_route' => 'direct',
            'immediate_recovery' => true, 'effective_start' => now()->toISOString(), 'effective_end' => now()->addMinutes(5)->toISOString(), 'immediate_reason' => 'Safe vehicle and customer arrangement'];
        $this->actingAs($f['dispatcher'], 'user')->postJson("/api/logistics/incidents/{$incident->id}/resolve", $data)->assertOk();
        $old = $incident->fresh()->recoveryBatch;
        app(BatchDispatchService::class)->accept($old, $f['a']);
        $this->travelTo(now()->addMinutes(5));
        try {
            app(BatchDispatchService::class)->start($old->fresh(), $f['a']);
            $this->fail('Expired replacement authority started.');
        } catch (\Illuminate\Validation\ValidationException $exception) { $this->assertArrayHasKey('customer_appointment', $exception->errors()); }
        $this->postJson("/api/logistics/incidents/{$incident->id}/resolve", [...$data, 'expected_revision' => 1,
            'decision_key' => (string) Str::uuid(), 'effective_start' => now()->toISOString(), 'effective_end' => now()->addMinutes(10)->toISOString()])->assertOk();
        $new = $incident->fresh()->recoveryBatch;
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame('offered', $new->status);
        app(BatchDispatchService::class)->accept($new, $f['a']);
        $this->assertSame('in_progress', app(BatchDispatchService::class)->start($new->fresh(), $f['a'])->status);
    }
}
