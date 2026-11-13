<?php

namespace App\Services\Logistics;

use App\Enums\Logistics\RiderProgressState;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\DeliveryIncident;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BatchInterruptionService
{
    public function __construct(
        private LogisticsActorPolicy $policy,
        private LogisticsMovementEligibility $movements,
        private DeliveryEventService $events,
        private BatchDispatchService $batches,
        private ShipmentCustodyService $custody,
        private DeliveryStartEligibility $starts,
        private AssignmentService $assignments,
    ) {}

    public function report(DeliveryBatch $batch, User $actor, array $data): DeliveryIncident
    {
        return DB::transaction(function () use ($batch, $actor, $data) {
            $shop = ShopOwner::whereKey($batch->shop_owner_id)->lockForUpdate()->firstOrFail();
            $batch = DeliveryBatch::lockForUpdate()->findOrFail($batch->id);
            $this->policy->assertBatchInterruption($actor, $shop, $batch);
            $paths = $data['photo_paths'] ?? [];
            if (! Str::isUuid($data['request_key'] ?? '') || ! filled($data['notes'] ?? null) || mb_strlen($data['notes']) > 1000
                || ! is_array($paths) || count($paths) < 1 || count($paths) > 5) {
                throw ValidationException::withMessages(['incident' => 'A request identity, note and private evidence are required.']);
            }
            $hashes = [];
            foreach ($paths as $path) {
                if (! is_string($path) || ! str_starts_with($path, 'incident-evidence/') || str_contains($path, '..')
                    || str_contains($path, '\\') || ! Storage::disk('local')->exists($path)) {
                    throw ValidationException::withMessages(['photo_files' => 'Private incident evidence is required.']);
                }
                $hashes[] = hash('sha256', Storage::disk('local')->get($path));
            }
            $facts = hash('sha256', json_encode([$batch->id, $actor->id, trim($data['notes']), $hashes], JSON_THROW_ON_ERROR));
            $existing = DeliveryIncident::where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->request_facts_hash !== $facts) {
                    throw ValidationException::withMessages(['request_key' => 'This report identity has different facts. Refresh the batch.']);
                }
                return $existing;
            }
            if ($batch->active_incident_id || ! in_array($batch->status, ['accepted', 'in_progress'], true)) {
                throw ValidationException::withMessages(['batch' => 'This batch changed or already has an interruption. Refresh before reporting.']);
            }
            $legs = $batch->legs()->with(['shipment', 'latestAssignment'])->orderBy('id')->lockForUpdate()->get();
            abort_if($legs->contains(fn ($leg) => (int) $leg->shipment->shop_owner_id !== (int) $shop->id), 403);
            $affected = $legs->filter(fn ($leg) => $leg->rider_progress_state === RiderProgressState::ACTIVE
                && in_array($leg->status->value, ['assigned', 'pickup_scheduled', 'picked_up', 'in_transit', 'delivery_attempted'], true)
                && (int) $leg->latestAssignment?->rider_profile_id === (int) $batch->rider_profile_id
                && $leg->latestAssignment?->status === 'accepted'
                && ! $leg->incidents()->whereIn('status', ['reported', 'under_review'])->exists());
            if ($affected->isEmpty()) {
                throw ValidationException::withMessages(['batch' => 'There are no remaining executable stops to interrupt.']);
            }
            $incident = new DeliveryIncident();
            $incident->forceFill(['shop_owner_id' => $shop->id, 'shipment_leg_id' => null, 'delivery_batch_id' => $batch->id,
                'reporting_rider_profile_id' => $batch->rider_profile_id, 'type' => 'vehicle_problem', 'status' => 'reported',
                'request_key' => $data['request_key'], 'request_facts_hash' => $facts, 'notes' => trim($data['notes']), 'photo_paths' => $paths,
                'interrupted_state' => ['batch_status' => $batch->status], 'revision' => 0])->save();
            foreach ($affected as $leg) {
                $this->movements->assertInternalLeg($leg);
                $incident->affectedStops()->create(['shipment_leg_id' => $leg->id, 'original_sequence' => $leg->stop_sequence,
                    'original_assignment_id' => $leg->latestAssignment->id, 'snapshot' => [
                        'status' => $leg->status->value, 'custody_state' => $leg->custody_state,
                        'shipment_id' => $leg->shipment_id, 'source_type' => $leg->shipment->source_type,
                        'source_id' => $leg->shipment->source_id, 'purpose' => $leg->shipment->purpose,
                        'custody_rider_profile_id' => $leg->custody_rider_profile_id,
                        'picked_up_at' => $leg->picked_up_at?->toISOString(),
                    ]]);
            }
            $batch->update(['status' => 'interrupted', 'active_incident_id' => $incident->id]);
            $this->publish($incident, 'batch_interruption_reported', 'Your delivery is delayed while the shop reviews a vehicle interruption.', $actor);
            return $incident;
        }, 3);
    }

    public function resolve(DeliveryIncident $incident, User $actor, array $data): DeliveryIncident
    {
        $data = \Illuminate\Support\Facades\Validator::make($data, \App\Http\Requests\Logistics\ResolveBatchInterruptionRequest::decisionRules())->validate();
        $data['expected_revision'] = (int) $data['expected_revision'];
        if (isset($data['rider_profile_id'])) $data['rider_profile_id'] = (int) $data['rider_profile_id'];
        return DB::transaction(function () use ($incident, $actor, $data) {
            $shop = ShopOwner::whereKey($incident->shop_owner_id)->lockForUpdate()->firstOrFail();
            $source = DeliveryBatch::whereKey($incident->delivery_batch_id)->lockForUpdate()->firstOrFail();
            $incident = DeliveryIncident::lockForUpdate()->findOrFail($incident->id);
            $this->policy->assertBatchInterruption($actor, $shop, $source, resolve: true);
            $facts = $data;
            if (isset($facts['appointment_agreements'])) ksort($facts['appointment_agreements']);
            ksort($facts);
            $hash = hash('sha256', json_encode([$incident->id, $actor->id, $facts], JSON_THROW_ON_ERROR));
            $replay = \App\Models\Logistics\DeliveryEvent::where('event_type', 'batch_interruption_decided')
                ->where('metadata->decision_key', $data['decision_key'])->lockForUpdate()->first();
            if ($replay) {
                if (($replay->metadata['facts_hash'] ?? null) !== $hash) {
                    throw ValidationException::withMessages(['decision_key' => 'This decision identity has different facts.']);
                }
                return $incident;
            }
            if ((int) $source->active_incident_id !== (int) $incident->id || $incident->status === 'resolved'
                || $incident->revision !== $data['expected_revision']) {
                throw ValidationException::withMessages(['expected_revision' => 'Another action changed this interruption. Refresh before deciding.']);
            }
            $stops = $incident->affectedStops()->with('leg.shipment.shopOwner.logisticsSetting')->lockForUpdate()->get();
            $legs = ShipmentLeg::whereIn('id', $stops->pluck('shipment_leg_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($stops as $stop) {
                $leg = $legs->get($stop->shipment_leg_id);
                if (! $leg || (int) $leg->shipment->shop_owner_id !== (int) $shop->id
                    || (int) $leg->delivery_batch_id !== (int) ($incident->recovery_batch_id ?? $source->id)
                    || (int) $leg->shipment_id !== (int) $stop->snapshot['shipment_id']
                    || $leg->shipment->source_type !== $stop->snapshot['source_type']
                    || (int) $leg->shipment->source_id !== (int) $stop->snapshot['source_id']
                    || $leg->shipment->purpose !== $stop->snapshot['purpose']
                    || $leg->status->value !== ($stop->snapshot['status'] ?? null)) {
                    throw ValidationException::withMessages(['batch' => 'An affected delivery changed. Keep the hold and investigate its current state.']);
                }
                $stop->setRelation('leg', $leg);
            }
            $choice = $data['resolution'];
            if (in_array($choice, ['resume', 'reassign', 'authorize_replacement'], true)) {
                $this->movements->assertCanStart($shop);
                $incident->forceFill(['resolution' => $choice === 'authorize_replacement' ? 'reassign' : $choice,
                    'interrupted_state' => [...$incident->interrupted_state, 'module_marker' => $this->moduleMarker($shop)]])->save();
            }
            if ($choice === 'resume') {
                if ($incident->recovery_batch_id) {
                    throw ValidationException::withMessages(['resolution' => 'A replacement offer exists. Resolve its handoffs before changing recovery.']);
                }
                $rider = RiderProfile::whereKey($source->rider_profile_id)->lockForUpdate()->firstOrFail();
                $this->assignments->assertEligibleForDate($rider, $shop, now(config('app.shop_timezone', 'Asia/Manila'))->toDateString(), checkCapacity: false);
                foreach ($stops as $stop) {
                    if ($stop->leg->custodyTransfers()->whereIn('status', ['requested', 'released'])->lockForUpdate()->first()) {
                        throw ValidationException::withMessages(['custody' => 'Finish or cancel the existing parcel handoff before resuming.']);
                    }
                    if ($this->isStarted($stop->leg)) $this->custody->assertRiderHasCustody($stop->leg, $rider);
                    if (! $stop->leg->assignments()->whereKey($stop->original_assignment_id)->where('status', 'accepted')->exists()) {
                        throw ValidationException::withMessages(['assignment' => 'The original accepted assignment changed.']);
                    }
                }
                $incident->affectedStops()->update(['held' => false, 'outcome' => 'same_rider']);
                $source->update(['active_incident_id' => null, 'status' => $incident->interrupted_state['batch_status']]);
            } elseif ($choice === 'reassign') {
                abort_unless($actor->can('assign-logistics-deliveries') || $actor->can('manage-logistics-batches'), 403);
                if ($incident->recovery_batch_id) {
                    $old = DeliveryBatch::lockForUpdate()->findOrFail($incident->recovery_batch_id);
                    if ((! in_array($old->status, ['offered', 'accepted'], true) && ($old->status !== 'draft' || ! $old->rejected_at))
                        || $old->started_at || $stops->contains(fn ($stop) => $this->starts->hasActivated($stop->leg))) {
                        throw ValidationException::withMessages(['resolution' => 'Resolve the existing replacement offer and its physical handoffs first.']);
                    }
                    foreach ($stops as $stop) {
                        if ($stop->leg->custodyTransfers()->where('status', 'released')->exists()) {
                            throw ValidationException::withMessages(['custody' => 'A physical handoff has begun. Finish receipt or investigate it before changing the rider.']);
                        }
                        foreach ($stop->leg->custodyTransfers()->where('status', 'requested')->get() as $transfer) {
                            $this->custody->cancel($transfer, $actor, $data['note']);
                        }
                    }
                    $old->update(['status' => 'cancelled', 'active_incident_id' => null, 'cancelled_at' => now(), 'cancellation_reason' => $data['note']]);
                    $incident->forceFill(['recovery_batch_id' => null])->save();
                }
                $this->starts->validateRecoveryDate($shop, $data['delivery_date'], $data['delivery_window']);
                $rider = RiderProfile::where('shop_owner_id', $shop->id)->lockForUpdate()->findOrFail($data['rider_profile_id']);
                foreach ($stops as $stop) {
                    $leg = $stop->leg;
                    $this->assertTransferState($leg);
                    $note = $data['appointment_agreements'][(string) $leg->id] ?? null;
                    $changes = $this->starts->appointmentChanges($leg, $data['delivery_date'], $data['delivery_window'], filled($note), $note, $actor);
                    if ($changes) $leg->update($changes);
                }
                $this->batches->createRecoveryOffer($incident, $actor, $rider, $stops, $data);
                foreach ($stops as $stop) {
                    $leg = $stop->leg->fresh();
                    if (in_array($leg->custody_state, ['rider', 'shop'], true)
                        && ($leg->custody_state === 'shop' || (int) $leg->custody_rider_profile_id !== $rider->id || $data['handoff_route'] === 'via_shop')) {
                        $viaShop = $data['handoff_route'] === 'via_shop' && $leg->custody_state === 'rider';
                        $this->custody->request($leg, $actor, $viaShop ? 'shop' : 'rider', $viaShop ? null : $rider->id, (string) Str::uuid());
                    }
                }
            } elseif ($choice === 'return_to_shop') {
                if ($incident->recovery_batch_id) throw ValidationException::withMessages(['resolution' => 'Resolve the replacement batch before changing the physical return.']);
                foreach ($stops as $stop) {
                    $this->assertTransferState($stop->leg);
                    if ($stop->leg->custody_state === 'rider') {
                        $this->custody->request($stop->leg, $actor, 'shop', null, (string) Str::uuid());
                    }
                }
            } elseif ($choice === 'authorize_replacement' && ! $incident->recovery_batch_id) {
                throw ValidationException::withMessages(['resolution' => 'There is no replacement batch to authorize.']);
            } elseif ($choice === 'authorize_replacement') {
                $replacement = DeliveryBatch::lockForUpdate()->findOrFail($incident->recovery_batch_id);
                if (! in_array($replacement->status, ['offered', 'accepted'], true) || ! $replacement->rider_profile_id) {
                    throw ValidationException::withMessages(['resolution' => 'Select an eligible replacement after a declined offer.']);
                }
                foreach ($stops as $stop) {
                    if ($stop->leg->custody_state === 'shop' && ! $stop->leg->custodyTransfers()->whereIn('status', ['requested', 'released'])->exists()) {
                        $this->custody->request($stop->leg, $actor, 'rider', $replacement->rider_profile_id, (string) Str::uuid());
                    }
                }
            }
            $state = [...$incident->interrupted_state, 'module_marker' => $this->moduleMarker($shop)];
            $incident->forceFill(['resolution' => $choice === 'authorize_replacement' ? 'reassign' : $choice,
                'resolution_note' => $data['note'], 'decision_key' => $data['decision_key'], 'decision_facts_hash' => $hash,
                'decided_by_user_id' => $actor->id, 'decided_at' => now(), 'revision' => $incident->revision + 1,
                'status' => $choice === 'resume' ? 'resolved' : 'under_review', 'resolved_at' => $choice === 'resume' ? now() : null,
                'interrupted_state' => $state])->save();
            $anchor = $stops->first()->leg;
            $this->events->record($anchor->shipment, $anchor, ['event_type' => 'batch_interruption_decided', 'message' => 'Dispatcher recorded a batch recovery decision.',
                'metadata' => ['incident_id' => $incident->id, 'delivery_batch_id' => $source->id, 'recovery_batch_id' => $incident->recovery_batch_id,
                    'revision' => $incident->revision, 'resolution' => $choice, 'note' => $data['note'], 'decision_key' => $data['decision_key'], 'facts_hash' => $hash],
                'created_by_type' => User::class, 'created_by_id' => $actor->id]);
            if ($choice === 'reassign') $this->publish($incident, 'batch_interruption_rescheduled', 'The shop confirmed a new delivery arrangement. Required parcel handoffs must finish before continuation.', $actor);
            if ($choice === 'resume' && $source->status === 'in_progress') $this->publish($incident, 'batch_interruption_continued', 'Your delivery route can continue after the vehicle interruption was resolved.', $actor);
            if (in_array($choice, ['return_to_shop', 'escalate'], true)) $this->publish($incident, 'batch_interruption_delayed', 'Your delivery remains delayed while the shop arranges safe recovery.', $actor);
            return $incident->fresh();
        }, 3);
    }

    public function activateReplacement(DeliveryBatch $batch, RiderProfile $rider): void
    {
        $shop = ShopOwner::whereKey($batch->shop_owner_id)->lockForUpdate()->firstOrFail();
        $incident = DeliveryIncident::lockForUpdate()->findOrFail($batch->active_incident_id);
        if ((int) $incident->recovery_batch_id !== (int) $batch->id || $incident->resolution !== 'reassign'
            || $incident->status !== 'under_review' || (int) $batch->rider_profile_id !== (int) $rider->id) {
            throw ValidationException::withMessages(['batch_interruption' => 'Dispatcher recovery authorization is required.']);
        }
        $this->movements->assertCanStart($shop);
        if (($incident->interrupted_state['module_marker'] ?? null) !== $this->moduleMarker($shop)) {
            throw ValidationException::withMessages(['batch_interruption' => 'Logistics availability changed. Ask dispatch to authorize continuation again.']);
        }
        $stops = $incident->affectedStops()->with('leg')->lockForUpdate()->get();
        foreach ($stops as $stop) {
            $leg = ShipmentLeg::lockForUpdate()->findOrFail($stop->shipment_leg_id);
            if ((int) $leg->delivery_batch_id !== (int) $batch->id || $leg->status->value !== $stop->snapshot['status']
                || ! $leg->assignments()->where('rider_profile_id', $rider->id)->where('status', 'accepted')->exists()) {
                throw ValidationException::withMessages(['assignment' => 'An affected delivery changed or its offer is not accepted.']);
            }
            if ($this->isStarted($leg) || $leg->custody_state !== 'unknown') {
                $this->custody->assertRiderHasCustody($leg, $rider);
            } elseif ($leg->custodyTransfers()->whereIn('status', ['requested', 'released'])->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['custody' => 'Complete all parcel handoffs before starting this batch.']);
            }
        }
        $incident->affectedStops()->update(['held' => false, 'outcome' => 'replacement_rider']);
        DeliveryBatch::whereKey($incident->delivery_batch_id)->update(['status' => 'recovered', 'active_incident_id' => null]);
        $batch->update(['active_incident_id' => null]);
        $incident->update(['status' => 'resolved', 'resolved_at' => now()]);
        $this->publish($incident, 'batch_interruption_continued', 'Your delivery route is continuing with its confirmed recovery rider.', User::findOrFail($rider->linked_id));
    }

    private function assertTransferState(ShipmentLeg $leg): void
    {
        if (($this->isStarted($leg) && ($leg->custody_state === 'unknown' || ! $leg->custody_confirmed_at))
            || $leg->custodyTransfers()->whereIn('status', ['requested', 'released'])->lockForUpdate()->first()) {
            throw ValidationException::withMessages(['custody' => 'Confirm current custody and finish any outstanding transfer before changing recovery.']);
        }
    }

    private function moduleMarker(ShopOwner $shop): array
    {
        $module = $shop->modules()->where('module_key', 'logistics')->lockForUpdate()->first();
        $lastToggle = $module ? \Spatie\Activitylog\Models\Activity::where('subject_type', $module->getMorphClass())
            ->where('subject_id', $module->id)->where('properties->action', 'shop_owner_module_toggled')->max('id') : null;
        return ['id' => $module?->id, 'updated_at' => $module?->updated_at?->toISOString(), 'toggle_id' => $lastToggle];
    }

    public function outboundRecoveryAuthorized(DeliveryIncident $incident): bool
    {
        $shop = ShopOwner::whereKey($incident->shop_owner_id)->lockForUpdate()->firstOrFail();
        return $incident->resolution === 'reassign' && $incident->status !== 'resolved' && $this->movements->canStart($shop, lockState: true)
            && ($incident->interrupted_state['module_marker'] ?? null) === $this->moduleMarker($shop);
    }

    private function isStarted(ShipmentLeg $leg): bool
    {
        return $leg->picked_up_at || in_array($leg->status->value, ['picked_up', 'in_transit', 'delivery_attempted'], true);
    }

    public function recordStartedContinuation(DeliveryBatch $batch, RiderProfile $rider): void
    {
        $incident = $batch->incidents()->where('status', 'resolved')->where('resolution', 'resume')->latest('id')->first();
        if ($incident) $this->publish($incident, 'batch_interruption_continued', 'Your delivery route is continuing after the vehicle interruption.', User::findOrFail($rider->linked_id));
    }

    public function forBatch(DeliveryBatch $batch, bool $includeEvidence = false): ?array
    {
        $incident = $batch->activeIncident ?? $batch->recoveryIncident ?? $batch->incidents()->latest('id')->first();
        if (! $incident) return null;
        $incident->loadMissing(['affectedStops.leg.custodyTransfers', 'recoveryBatch']);
        $targetRider = $incident->recoveryBatch?->rider_profile_id ?? $incident->reporting_rider_profile_id;
        $stops = $incident->affectedStops->map(function ($stop) use ($targetRider) {
            $leg = $stop->leg;
            $required = $this->isStarted($leg) || $leg->custody_state !== 'unknown';
            $outstanding = $leg->custodyTransfers->first(fn ($transfer) => in_array($transfer->status, ['requested', 'released'], true));
            return ['shipment_leg_id' => $leg->id, 'delivery_reference' => 'Delivery #'.($leg->delivery_number ?? $leg->id),
                'sequence' => $stop->original_sequence, 'status' => $leg->status->value, 'custody_state' => $leg->custody_state,
                'requires_handoff' => $required, 'handoff_complete' => $required && ! $outstanding && $leg->custody_confirmed_at
                    && $leg->custody_state === 'rider' && (int) $leg->custody_rider_profile_id === (int) $targetRider,
                'custody_rider_profile_id' => $leg->custody_rider_profile_id, 'transfer_status' => $outstanding?->status, 'customer_appointment_date' => $leg->customer_appointment_date?->toDateString(),
                'customer_appointment_window' => $leg->customer_appointment_window];
        })->values();
        return ['id' => $incident->id, 'revision' => $incident->revision, 'status' => $incident->status,
            'source_batch_id' => $incident->delivery_batch_id, 'recovery_batch_id' => $incident->recovery_batch_id,
            'recovery_batch_status' => $incident->recoveryBatch?->status, 'notes' => $incident->notes,
            'reported_at' => $incident->created_at?->toISOString(), 'resolution' => $incident->resolution,
            'resolution_note' => $incident->resolution_note, 'decided_at' => $incident->decided_at?->toISOString(),
            'decided_by_user_id' => $incident->decided_by_user_id, 'resolved_at' => $incident->resolved_at?->toISOString(),
            'required_parcels' => $stops->where('requires_handoff', true)->count(),
            'confirmed_parcels' => $stops->where('handoff_complete', true)->count(), 'stops' => $stops->all(),
            'evidence_urls' => $includeEvidence ? collect($incident->photo_paths ?? [])->map(fn ($path, $index) =>
                route('api.logistics.incidents.evidence', ['incident' => $incident, 'index' => $index]))->values()->all() : []];
    }

    private function publish(DeliveryIncident $incident, string $type, string $message, User $actor): void
    {
        $stops = $incident->affectedStops()->with('leg.shipment')->get();
        foreach ($stops as $index => $stop) {
            $leg = $stop->leg;
            $metadata = ['incident_id' => $incident->id, 'revision' => $incident->revision,
                'delivery_batch_id' => $incident->delivery_batch_id, 'recovery_batch_id' => $incident->recovery_batch_id,
                'delivery_date' => $leg->scheduled_delivery_date?->toDateString(), 'delivery_window' => $leg->delivery_window];
            $event = ['event_type' => $type, 'message' => $message, 'metadata' => $metadata,
                'created_by_type' => User::class, 'created_by_id' => $actor->id];
            if ($index === 0) $this->events->record($leg->shipment, $leg, $event);
            $this->events->record($leg->shipment, $leg, [...$event, 'visibility' => 'customer']);
        }
    }
}
