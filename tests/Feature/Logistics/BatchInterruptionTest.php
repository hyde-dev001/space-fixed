<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryIncident;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BatchInterruptionFixture;
use Tests\TestCase;

class BatchInterruptionTest extends TestCase
{
    use RefreshDatabase, BatchInterruptionFixture;

    public static function stages(): array
    {
        return ['before first stop' => ['accepted', 3], 'mid batch' => ['in_progress', 3], 'one remaining' => ['in_progress', 1]];
    }

    #[DataProvider('stages')]
    public function test_report_holds_remaining_stops_without_creating_customer_attempts(string $state, int $count): void
    {
        $f = $this->interruptionFixture($state, $count);
        $before = $f['legs']->map->fresh()->map->getAttributes()->all();
        $snapshot = $f['batch']->stop_snapshot;
        $result = $this->reportInterruption($f)->assertCreated();
        $incident = DeliveryIncident::findOrFail($result->json('incident.id'));
        $this->assertSame('interrupted', $f['batch']->fresh()->status);
        $this->assertSame($incident->id, $f['batch']->fresh()->active_incident_id);
        $this->assertNull($incident->shipment_leg_id);
        $this->assertSame($count, $incident->affectedStops()->count());
        $this->assertSame($snapshot, $f['batch']->fresh()->stop_snapshot);
        $this->assertSame($before, $f['legs']->map->fresh()->map->getAttributes()->all());
        $this->assertDatabaseCount('delivery_attempts', 0);
    }

    public function test_completed_and_proof_review_stops_are_preserved_and_excluded(): void
    {
        $f = $this->interruptionFixture('in_progress', 4);
        $f['legs'][0]->update(['status' => 'delivered', 'rider_progress_state' => 'rider_released']);
        $f['legs'][1]->update(['status' => 'awaiting_proof_approval', 'rider_progress_state' => 'proof_submitted']);
        $f['legs'][2]->update(['status' => 'needs_resolution', 'resolution_type' => 'pickup_failed']);
        $proof = \App\Models\Logistics\HandoffProof::factory()->create(['shipment_leg_id' => $f['legs'][1]->id,
            'delivery_assignment_id' => $f['legs'][1]->latestAssignment->id]);
        $attempt = \App\Models\Logistics\DeliveryAttempt::create(['shipment_leg_id' => $f['legs'][2]->id,
            'delivery_assignment_id' => $f['legs'][2]->latestAssignment->id, 'attempt_type' => 'delivery',
            'status' => 'failed', 'reason_code' => 'other', 'attempt_number' => 1, 'attempted_at' => now()]);
        $history = [$proof->fresh()->getAttributes(), $attempt->fresh()->getAttributes()];
        $this->reportInterruption($f)->assertCreated();
        $incident = DeliveryIncident::whereNotNull('delivery_batch_id')->sole();
        $this->assertSame([$f['legs'][3]->id], $incident->affectedStops()->pluck('shipment_leg_id')->all());
        $this->assertSame(['delivered', 'awaiting_proof_approval', 'needs_resolution', 'picked_up'], $f['legs']->map->fresh()->pluck('status.value')->all());
        $this->assertSame($history, [$proof->fresh()->getAttributes(), $attempt->fresh()->getAttributes()]);
    }

    public function test_single_leg_cancellation_cannot_escape_a_batch_hold(): void
    {
        $f = $this->interruptionFixture();
        $f['legs'][0]->update(['status' => 'delivery_attempted', 'picked_up_at' => null]);
        $this->reportInterruption($f)->assertCreated();
        try { app(ShipmentLegService::class)->cancel($f['legs'][0], 'Stale single-leg cancellation'); $this->fail('Cancellation escaped the hold.'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('batch_interruption', $exception->errors()); }
        $this->assertSame('delivery_attempted', $f['legs'][0]->fresh()->status->value);
    }

    public function test_a_stale_in_transit_request_is_blocked_even_on_the_idempotent_path(): void
    {
        $f = $this->interruptionFixture();
        $stale = $f['legs'][0];
        $this->reportInterruption($f)->assertCreated();
        $this->expectException(ValidationException::class);
        app(ShipmentLegService::class)->markInTransit($stale, $f['a']);
    }

    public function test_duplicate_report_replays_once_and_changed_facts_are_rejected(): void
    {
        $f = $this->interruptionFixture();
        $key = (string) \Illuminate\Support\Str::uuid();
        $id = $this->reportInterruption($f, $key)->assertCreated()->json('incident.id');
        $this->assertSame($id, $this->reportInterruption($f, $key)->assertCreated()->json('incident.id'));
        $this->assertDatabaseCount('delivery_incidents', 1);
        $this->assertCount(1, \Illuminate\Support\Facades\Storage::disk('local')->allFiles('incident-evidence'));
        $this->postJson("/api/logistics/batches/{$f['batch']->id}/incidents", [
            'request_key' => $key, 'notes' => 'Different facts',
            'photo_files' => [\Illuminate\Http\UploadedFile::fake()->image('new.jpg')],
        ])->assertUnprocessable()->assertJsonValidationErrors('request_key');
    }

    public function test_report_requires_actual_rider_tenant_attendance_and_permissions(): void
    {
        $f = $this->interruptionFixture();
        $path = "/api/logistics/batches/{$f['batch']->id}/incidents";
        $payload = ['request_key' => (string) \Illuminate\Support\Str::uuid(), 'notes' => 'Synthetic breakdown',
            'photo_files' => [\Illuminate\Http\UploadedFile::fake()->image('vehicle.jpg')]];
        $this->actingAs(\App\Models\User::findOrFail($f['b']->linked_id), 'user')->postJson($path, $payload)->assertForbidden();
        $this->actingAs($f['dispatcher'], 'user')->postJson($path, $payload)->assertForbidden();
        $actor = \App\Models\User::findOrFail($f['a']->linked_id);
        \Illuminate\Support\Facades\DB::table('attendance_records')->delete();
        $this->actingAs($actor, 'user')->postJson($path, $payload)->assertStatus(423);
        $this->clockInEmployee($actor);
        $actor->revokePermissionTo('update-logistics-status');
        $this->postJson($path, $payload)->assertForbidden();
        $this->assertDatabaseCount('delivery_incidents', 0);
    }

    public function test_held_current_stop_cannot_advance_by_direct_service_requests(): void
    {
        $f = $this->interruptionFixture();
        $this->reportInterruption($f)->assertCreated();
        $actions = [
            fn () => app(ShipmentLegService::class)->markOutForDelivery($f['legs'][0], $f['a']),
            fn () => app(ShipmentLegService::class)->markPickedUp($f['legs'][1], $f['a']),
            fn () => app(\App\Services\Logistics\RiderActiveWorkGuard::class)->assertCanAdvanceLeg($f['a'], $f['legs'][0]),
            fn () => app(\App\Services\Logistics\ArrivalService::class)->record($f['legs'][0], \App\Models\User::findOrFail($f['a']->linked_id), ['arrival_type' => 'dropoff']),
            fn () => app(ShipmentLegService::class)->markDelivered($f['legs'][0], $f['a']),
        ];
        foreach ($actions as $action) {
            try { $action(); $this->fail('Held work advanced.'); }
            catch (ValidationException $exception) { $this->assertArrayHasKey('batch_interruption', $exception->errors()); }
        }
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('handoff_proofs', 0);
    }

    public function test_both_subjects_are_invalid_and_populated_migration_rollback_is_refused(): void
    {
        $f = $this->interruptionFixture();
        $this->reportInterruption($f)->assertCreated();
        $incident = DeliveryIncident::whereNotNull('delivery_batch_id')->sole();
        try {
            $incident->forceFill(['shipment_leg_id' => $f['legs'][0]->id])->save();
            $this->fail('Contradictory incident subject accepted.');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('incident', $exception->errors()); }
        foreach (['2026_10_23_132200_add_batch_incident_holds_and_stops.php', '2026_10_23_132125_add_batch_scope_to_delivery_incidents.php'] as $file) {
            try { (require database_path('migrations/'.$file))->down(); $this->fail('History was dropped.'); }
            catch (\RuntimeException $exception) { $this->assertStringContainsString('Retain', $exception->getMessage()); }
        }
        $this->assertDatabaseCount('delivery_incident_stops', 3);
    }

    public function test_rider_page_preserves_held_current_work_and_dispatcher_page_has_safe_incident_projection(): void
    {
        $f = $this->interruptionFixture();
        $this->reportInterruption($f)->assertCreated();
        $this->get('/erp/logistics/deliveries')->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('deliveryData.current.status', 'interrupted')->where('deliveryData.current.interruption.required_parcels', 3));
        $this->actingAs($f['dispatcher'], 'user')->get('/erp/logistics/batches')->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('batches.0.interruption.required_parcels', 3)->missing('batches.0.interruption.photo_paths')->missing('batches.0.interruption.interrupted_state'));
    }

    public function test_batch_evidence_and_resolution_never_cross_shop_boundaries(): void
    {
        $f = $this->interruptionFixture();
        $result = $this->reportInterruption($f)->assertCreated()->assertJsonMissingPath('incident.photo_paths');
        $id = $result->json('incident.id');
        $url = $result->json('incident.evidence_urls.0');
        $this->actingAs($f['dispatcher'], 'user')->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $foreign = \App\Models\User::factory()->create(['shop_owner_id' => \App\Models\ShopOwner::factory()->approved()->withLogistics()->create()->id]);
        foreach (['resolve-logistics-exceptions', 'operate-logistics-deliveries', 'update-logistics-status'] as $permission) {
            $foreign->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'user'));
        }
        $this->clockInEmployee($foreign);
        $this->actingAs($foreign, 'user')->get($url)->assertForbidden();
        $this->postJson("/api/logistics/incidents/{$id}/resolve", ['resolution' => 'resume', 'note' => 'Wrong shop.',
            'expected_revision' => 0, 'decision_key' => (string) \Illuminate\Support\Str::uuid()])->assertForbidden();
        $this->postJson("/api/logistics/batches/{$f['batch']->id}/incidents", ['notes' => 'Wrong tenant report.',
            'request_key' => (string) \Illuminate\Support\Str::uuid(),
            'photo_files' => [\Illuminate\Http\UploadedFile::fake()->image('foreign.jpg')]])->assertForbidden();
        $this->assertSame(0, DeliveryIncident::findOrFail($id)->revision);
    }
}
