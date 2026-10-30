<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryIncident;
use App\Models\User;
use App\Services\Logistics\BatchDispatchService;
use App\Services\Logistics\ShipmentCustodyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BatchInterruptionFixture;
use Tests\TestCase;

class BatchInterruptionRecoveryTest extends TestCase
{
    use RefreshDatabase, BatchInterruptionFixture;

    protected function decide(array $f, string $resolution, array $extra = [])
    {
        $incident = DeliveryIncident::where('delivery_batch_id', $f['batch']->id)->latest('id')->firstOrFail();
        return $this->actingAs($f['dispatcher'], 'user')->postJson("/api/logistics/incidents/{$incident->id}/resolve", [
            'resolution' => $resolution, 'note' => 'Authorized synthetic recovery.',
            'expected_revision' => $incident->revision, 'decision_key' => (string) Str::uuid(), ...$extra,
        ]);
    }

    public function test_same_rider_resume_preserves_stage_custody_assignments_and_attempts(): void
    {
        $f = $this->interruptionFixture();
        $before = $f['legs']->map->fresh()->map->getAttributes()->all();
        $this->reportInterruption($f)->assertCreated();
        $this->decide($f, 'resume')->assertOk();
        $this->assertSame('in_progress', $f['batch']->fresh()->status);
        $this->assertNull($f['batch']->fresh()->active_incident_id);
        $this->assertSame($before, $f['legs']->map->fresh()->map->getAttributes()->all());
        $this->assertDatabaseCount('delivery_assignments', 3);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertSame(0, DeliveryIncident::sole()->affectedStops()->where('held', true)->count());
        $this->reportInterruption($f)->assertCreated();
        $this->assertDatabaseCount('delivery_incidents', 2);
    }

    public static function routes(): array { return [['direct'], ['via_shop']]; }

    #[DataProvider('routes')]
    public function test_replacement_waits_for_all_parcels_and_keeps_original_history(string $route): void
    {
        $f = $this->interruptionFixture();
        $originalSnapshot = $f['batch']->stop_snapshot;
        $this->reportInterruption($f)->assertCreated();
        $this->decide($f, 'reassign', ['rider_profile_id' => $f['b']->id, 'delivery_date' => now()->toDateString(),
            'delivery_window' => 'morning', 'handoff_route' => $route])->assertOk();
        $incident = DeliveryIncident::sole();
        $replacement = $incident->recoveryBatch;
        $this->assertNotNull($replacement);
        $this->assertSame('offered', $replacement->status);
        app(BatchDispatchService::class)->accept($replacement, $f['b']);
        Storage::disk('local')->put('logistics-proof/synthetic.jpg', 'synthetic test evidence');
        $transfers = $f['legs']->map(fn ($leg) => $leg->custodyTransfers()->where('status', 'requested')->sole());
        foreach ($transfers as $index => $transfer) {
            $custody = app(ShipmentCustodyService::class);
            $custody->release($transfer, User::findOrFail($f['a']->linked_id), 'logistics-proof/synthetic.jpg');
            if ($route === 'via_shop') {
                $custody->receive($transfer, $f['dispatcher'], 'logistics-proof/synthetic.jpg');
                $next = $transfer->leg->custodyTransfers()->where('status', 'requested')->sole();
                $custody->release($next, $f['dispatcher'], 'logistics-proof/synthetic.jpg');
                $custody->receive($next, User::findOrFail($f['b']->linked_id), 'logistics-proof/synthetic.jpg');
            } else {
                $custody->receive($transfer, User::findOrFail($f['b']->linked_id), 'logistics-proof/synthetic.jpg');
            }
            if ($index < $transfers->count() - 1) {
                try { app(BatchDispatchService::class)->start($replacement, $f['b']); $this->fail('Partial handoff started a batch.'); }
                catch (ValidationException $exception) { $this->assertArrayHasKey('custody', $exception->errors()); }
            }
        }
        $started = app(BatchDispatchService::class)->start($replacement, $f['b']);
        $this->assertSame('in_progress', $started->status);
        $this->assertSame('recovered', $f['batch']->fresh()->status);
        $this->assertSame($originalSnapshot, $f['batch']->fresh()->stop_snapshot);
        $this->assertSame([1, 2, 3], $replacement->legs()->pluck('stop_sequence')->all());
        $this->assertSame([$f['b']->id], $replacement->legs()->pluck('custody_rider_profile_id')->unique()->values()->all());
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertSame(['in_transit', 'picked_up'], $replacement->legs()->get()->pluck('status.value')->unique()->values()->all());
        $this->actingAs(User::findOrFail($f['b']->linked_id), 'user')->postJson("/api/logistics/batches/{$replacement->id}/incidents", [
            'request_key' => (string) Str::uuid(), 'notes' => 'A later independent vehicle interruption.',
            'photo_files' => [\Illuminate\Http\UploadedFile::fake()->image('second-vehicle.jpg')],
        ])->assertCreated();
        $this->assertSame('interrupted', $replacement->fresh()->status);
        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertDatabaseCount('delivery_incidents', 2);
    }

    public function test_parcel_takeover_preserves_collected_cod_and_the_original_riders_remittance(): void
    {
        $f = $this->interruptionFixture('in_progress', 1);
        $order = \App\Models\Order::findOrFail($f['legs'][0]->shipment->source_id);
        $order->update(['payment_method' => 'cod', 'payment_status' => 'pending']);
        $a = User::findOrFail($f['a']->linked_id);
        $cash = app(\App\Services\CodCollectionService::class);
        $collection = $cash->cashCollected($order->fresh(), $a, ['amount' => $cash->expectedAmount($order->fresh())]);
        $before = $collection->fresh()->getAttributes();
        $this->reportInterruption($f)->assertCreated();
        $this->decide($f, 'reassign', ['rider_profile_id' => $f['b']->id, 'delivery_date' => now()->toDateString(),
            'delivery_window' => 'morning', 'handoff_route' => 'direct'])->assertOk();
        Storage::disk('local')->put('logistics-proof/cod.jpg', 'Synthetic parcel evidence');
        app(BatchDispatchService::class)->accept(DeliveryIncident::sole()->recoveryBatch, $f['b']);
        $transfer = $f['legs'][0]->custodyTransfers()->where('status', 'requested')->sole();
        $custody = app(ShipmentCustodyService::class);
        $custody->release($transfer, $a, 'logistics-proof/cod.jpg');
        $custody->receive($transfer, User::findOrFail($f['b']->linked_id), 'logistics-proof/cod.jpg');
        $this->assertSame($before, $collection->fresh()->getAttributes());
        app(\App\Services\CodRemittanceService::class)->submit($a, ['collection_ids' => [$collection->id], 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame($a->id, $collection->fresh()->rider_user_id);
        $this->assertDatabaseCount('cod_collections', 1);
        $this->assertDatabaseCount('cod_remittances', 1);
    }

    public function test_unstarted_same_rider_resume_does_not_auto_start(): void
    {
        $f = $this->interruptionFixture('accepted');
        $this->reportInterruption($f)->assertCreated();
        $this->decide($f, 'resume')->assertOk();
        $this->assertSame('accepted', $f['batch']->fresh()->status);
        $this->assertNull($f['batch']->fresh()->started_at);
    }

    public function test_unknown_started_custody_blocks_takeover_without_partial_assignment_changes(): void
    {
        $f = $this->interruptionFixture();
        $f['legs'][1]->update(['custody_state' => 'unknown', 'custody_rider_profile_id' => null, 'custody_confirmed_at' => null]);
        $this->reportInterruption($f)->assertCreated();
        $this->decide($f, 'reassign', ['rider_profile_id' => $f['b']->id, 'delivery_date' => now()->toDateString(),
            'delivery_window' => 'morning', 'handoff_route' => 'direct'])->assertUnprocessable()->assertJsonValidationErrors('custody');
        $this->assertDatabaseCount('delivery_batches', 1);
        $this->assertDatabaseCount('delivery_assignments', 3);
        $this->assertSame('interrupted', $f['batch']->fresh()->status);
    }

    public function test_return_to_shop_is_safekeeping_without_cancellation_refund_or_outbound_follow_on(): void
    {
        $f = $this->interruptionFixture();
        $this->reportInterruption($f)->assertCreated();
        $this->decide($f, 'return_to_shop')->assertOk();
        Storage::disk('local')->put('logistics-proof/safe.jpg', 'synthetic evidence');
        foreach ($f['legs'] as $leg) {
            $transfer = $leg->custodyTransfers()->where('status', 'requested')->sole();
            $custody = app(ShipmentCustodyService::class);
            $custody->release($transfer, User::findOrFail($f['a']->linked_id), 'logistics-proof/safe.jpg');
            $custody->receive($transfer, $f['dispatcher'], 'logistics-proof/safe.jpg');
            $this->assertSame('shop', $leg->fresh()->custody_state);
            $this->assertSame(0, $leg->custodyTransfers()->where('status', 'requested')->count());
        }
        $this->assertSame('interrupted', $f['batch']->fresh()->status);
        $this->assertDatabaseCount('order_refunds', 0);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertSame(0, $f['legs']->filter(fn ($leg) => $leg->fresh()->status->value === 'cancelled')->count());
    }

    public function test_in_transit_without_pickup_timestamp_is_not_uncollected_work(): void
    {
        $f = $this->interruptionFixture();
        $f['legs'][0]->update(['picked_up_at' => null, 'custody_state' => 'unknown', 'custody_rider_profile_id' => null, 'custody_confirmed_at' => null]);
        $this->reportInterruption($f)->assertCreated();
        $this->decide($f, 'reassign', ['rider_profile_id' => $f['b']->id, 'delivery_date' => now()->toDateString(),
            'delivery_window' => 'morning', 'handoff_route' => 'direct'])->assertUnprocessable()->assertJsonValidationErrors('custody');
    }

    public function test_reassignment_requires_each_changed_customer_appointment_agreement(): void
    {
        $f = $this->interruptionFixture('accepted', 1);
        $leg = $f['legs'][0];
        $leg->update(['customer_appointment_date' => now()->toDateString(), 'customer_appointment_window' => 'morning']);
        $this->reportInterruption($f)->assertCreated();
        $options = ['rider_profile_id' => $f['b']->id, 'delivery_date' => now()->addDay()->toDateString(),
            'delivery_window' => 'afternoon', 'handoff_route' => 'direct'];
        $this->decide($f, 'reassign', $options)->assertUnprocessable()->assertJsonValidationErrors('appointment_confirmed');
        $this->decide($f, 'reassign', [...$options, 'appointment_agreements' => [$leg->id => 'Customer agreed through normal contact.']])->assertOk();
        $this->assertSame($f['dispatcher']->id, $leg->fresh()->appointment_agreed_by_user_id);
        $this->assertSame($options['delivery_date'], $leg->fresh()->customer_appointment_date->toDateString());
        $this->assertSame(1, DeliveryIncident::sole()->recoveryBatch->assigned_stop_count);
    }

    public function test_resumed_stop_requires_activation_but_can_arrive_before_appointment(): void
    {
        $f = $this->interruptionFixture();
        $f['legs'][0]->update(['customer_appointment_date' => now()->addDay()->toDateString(), 'customer_appointment_window' => 'morning']);
        $this->reportInterruption($f)->assertCreated();
        $this->decide($f, 'resume')->assertOk();
        try {
            app(\App\Services\Logistics\ArrivalService::class)->record($f['legs'][0]->fresh(), User::findOrFail($f['a']->linked_id), ['arrival_type' => 'dropoff']);
            $this->fail('Arrival without current-assignment activation was allowed.');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('activation', $exception->errors()); }
        app(\App\Services\Logistics\ShipmentLegService::class)->markOutForDelivery($f['legs'][0]->fresh(), $f['a']);
        $arrival = app(\App\Services\Logistics\ArrivalService::class)->record($f['legs'][0]->fresh(), User::findOrFail($f['a']->linked_id), ['arrival_type' => 'dropoff', 'exception_reason' => 'pin_incorrect']);
        $this->assertSame('dropoff_arrived', $arrival->event_type);
    }

    public function test_off_mode_blocks_resume_but_allows_safe_reporting_of_started_work(): void
    {
        $f = $this->interruptionFixture();
        app(\App\Actions\ShopOwner\ToggleShopOwnerModule::class)->handle($f['shop'], 'logistics', false);
        $this->reportInterruption($f)->assertCreated();
        $this->decide($f, 'resume')->assertUnprocessable()->assertJsonValidationErrors('logistics');
        $this->decide($f, 'escalate')->assertOk();
        $this->assertSame('interrupted', $f['batch']->fresh()->status);
    }

    public function test_rejected_replacement_offer_retains_hold_and_can_be_reoffered_to_an_eligible_rider(): void
    {
        $f = $this->interruptionFixture('accepted');
        $this->reportInterruption($f)->assertCreated();
        $options = ['rider_profile_id' => $f['b']->id, 'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning', 'handoff_route' => 'direct'];
        $this->decide($f, 'reassign', $options)->assertOk();
        $oldBatch = DeliveryIncident::sole()->recoveryBatch;
        app(BatchDispatchService::class)->reject($oldBatch, $f['b'], 'Cannot safely accept this route.');
        $this->decide($f, 'reassign', [...$options, 'rider_profile_id' => $f['a']->id])->assertOk();
        $newBatch = DeliveryIncident::sole()->recoveryBatch;
        $this->assertNotSame($oldBatch->id, $newBatch->id);
        $this->assertSame('cancelled', $oldBatch->fresh()->status);
        $this->assertSame('interrupted', $f['batch']->fresh()->status);
        $this->assertSame(0, $f['legs']->map->fresh()->filter(fn ($leg) => $leg->status->value !== 'assigned')->count());
    }

    public function test_off_on_requires_explicit_reauthorization_even_when_clock_did_not_advance(): void
    {
        $f = $this->interruptionFixture('accepted', 1);
        $this->reportInterruption($f)->assertCreated();
        $this->decide($f, 'reassign', ['rider_profile_id' => $f['b']->id, 'delivery_date' => now()->toDateString(),
            'delivery_window' => 'morning', 'handoff_route' => 'direct'])->assertOk();
        $replacement = DeliveryIncident::sole()->recoveryBatch;
        app(BatchDispatchService::class)->accept($replacement, $f['b']);
        $toggle = app(\App\Actions\ShopOwner\ToggleShopOwnerModule::class);
        $toggle->handle($f['shop'], 'logistics', false);
        $toggle->handle($f['shop'], 'logistics', true);
        try { app(BatchDispatchService::class)->start($replacement, $f['b']); $this->fail('Re-enabling silently resumed the route.'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('batch_interruption', $exception->errors()); }
        $this->decide($f, 'authorize_replacement')->assertOk();
        $this->assertSame('in_progress', app(BatchDispatchService::class)->start($replacement, $f['b'])->status);
    }

    public function test_stale_decision_and_same_key_with_changed_facts_do_not_overwrite_recovery(): void
    {
        $f = $this->interruptionFixture();
        $id = $this->reportInterruption($f)->assertCreated()->json('incident.id');
        $payload = ['resolution' => 'escalate', 'note' => 'Investigation required.', 'expected_revision' => 0, 'decision_key' => (string) Str::uuid()];
        $this->actingAs($f['dispatcher'], 'user')->postJson("/api/logistics/incidents/{$id}/resolve", $payload)->assertOk();
        $this->postJson("/api/logistics/incidents/{$id}/resolve", $payload)->assertOk();
        $this->postJson("/api/logistics/incidents/{$id}/resolve", [...$payload, 'resolution' => 'resume'])->assertUnprocessable()->assertJsonValidationErrors('decision_key');
        $this->postJson("/api/logistics/incidents/{$id}/resolve", [...$payload, 'decision_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->assertSame(1, DeliveryIncident::findOrFail($id)->revision);
        $this->assertSame('interrupted', $f['batch']->fresh()->status);
    }

    public function test_changed_batch_membership_cannot_be_silently_resumed(): void
    {
        $f = $this->interruptionFixture();
        $this->reportInterruption($f)->assertCreated();
        $f['legs'][0]->update(['delivery_batch_id' => null]);
        $this->decide($f, 'resume')->assertUnprocessable()->assertJsonValidationErrors('batch');
    }

    public function test_ordinary_cancel_cannot_reset_a_held_replacement_batch_to_pending(): void
    {
        $f = $this->interruptionFixture();
        $this->reportInterruption($f)->assertCreated();
        $this->decide($f, 'reassign', ['rider_profile_id' => $f['b']->id, 'delivery_date' => now()->toDateString(),
            'delivery_window' => 'morning', 'handoff_route' => 'direct'])->assertOk();
        try { app(BatchDispatchService::class)->cancel(DeliveryIncident::sole()->recoveryBatch, 'Ordinary cancellation shortcut.'); $this->fail('A held route was reset.'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('batch_interruption', $exception->errors()); }
        $this->assertSame(['in_transit', 'picked_up'], $f['legs']->map->fresh()->pluck('status.value')->unique()->values()->all());
    }

    public function test_shop_receipt_while_off_requires_explicit_outbound_reauthorization(): void
    {
        $f = $this->interruptionFixture('in_progress', 1);
        $this->reportInterruption($f)->assertCreated();
        $this->decide($f, 'reassign', ['rider_profile_id' => $f['b']->id, 'delivery_date' => now()->toDateString(),
            'delivery_window' => 'morning', 'handoff_route' => 'via_shop'])->assertOk();
        $replacement = DeliveryIncident::sole()->recoveryBatch;
        app(BatchDispatchService::class)->accept($replacement, $f['b']);
        Storage::disk('local')->put('logistics-proof/safe.jpg', 'synthetic evidence');
        $transfer = $f['legs'][0]->custodyTransfers()->where('status', 'requested')->sole();
        $custody = app(ShipmentCustodyService::class);
        $custody->release($transfer, User::findOrFail($f['a']->linked_id), 'logistics-proof/safe.jpg');
        $toggle = app(\App\Actions\ShopOwner\ToggleShopOwnerModule::class);
        $toggle->handle($f['shop'], 'logistics', false);
        $custody->receive($transfer, $f['dispatcher'], 'logistics-proof/safe.jpg');
        $this->assertSame(0, $f['legs'][0]->custodyTransfers()->where('status', 'requested')->count());
        $toggle->handle($f['shop'], 'logistics', true);
        $this->decide($f, 'authorize_replacement')->assertOk();
        $this->assertSame(1, $f['legs'][0]->custodyTransfers()->where('status', 'requested')->count());
    }

    public function test_already_released_parcel_receipt_can_close_while_off_without_an_old_pickup_timestamp(): void
    {
        $f = $this->interruptionFixture('accepted', 1);
        $f['legs'][0]->update(['custody_state' => 'shop', 'custody_confirmed_at' => now()]);
        $this->reportInterruption($f)->assertCreated();
        $this->decide($f, 'reassign', ['rider_profile_id' => $f['b']->id, 'delivery_date' => now()->toDateString(),
            'delivery_window' => 'morning', 'handoff_route' => 'direct'])->assertOk();
        app(BatchDispatchService::class)->accept(DeliveryIncident::sole()->recoveryBatch, $f['b']);
        Storage::disk('local')->put('logistics-proof/safe.jpg', 'synthetic evidence');
        $transfer = $f['legs'][0]->custodyTransfers()->where('status', 'requested')->sole();
        app(ShipmentCustodyService::class)->release($transfer, $f['dispatcher'], 'logistics-proof/safe.jpg');
        app(\App\Actions\ShopOwner\ToggleShopOwnerModule::class)->handle($f['shop'], 'logistics', false);
        $this->actingAs(User::findOrFail($f['b']->linked_id), 'user')->postJson("/api/logistics/custody-transfers/{$transfer->id}/receive", [
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg'),
        ])->assertOk();
        $this->assertSame($f['b']->id, $f['legs'][0]->fresh()->custody_rider_profile_id);
    }
}
