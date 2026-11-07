<?php

namespace App\Services\Logistics;

use App\Models\Logistics\DeliveryIncident;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryIncidentService
{
    public const RESOLUTIONS = [
        'dismissed',
        'retry',
        'return_required',
        'loss_confirmed',
    ];

    public function __construct(
        private LogisticsMovementEligibility $movements,
        private DeliveryEventService $events,
        private ShipmentLegService $legs,
    ) {}

    public function report(ShipmentLeg $leg, RiderProfile $rider, array $data): DeliveryIncident
    {
        $photoPaths = $data['photo_paths'] ?? [];
        if (!in_array($data['type'] ?? null, ['damaged', 'lost', 'vehicle_problem', 'customer_dispute', 'other'], true)
            || !filled($data['notes'] ?? null)
            || !is_array($photoPaths)
            || (($data['type'] ?? null) !== 'lost' && empty($photoPaths))
            || count(array_filter($photoPaths, fn ($path) => $this->isSafeEvidencePath($path))) !== count($photoPaths)) {
            throw ValidationException::withMessages(['incident' => 'Incident type, notes, and evidence are required.']);
        }
        return DB::transaction(function () use ($leg, $rider, $data, $photoPaths) {
            $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            if (!$leg->assignments()->where('rider_profile_id', $rider->id)->whereIn('status', ['assigned', 'accepted'])->exists()
                || $rider->shop_owner_id !== $leg->shipment->shop_owner_id) abort(403);
            $existing = $leg->incidents()
                ->whereIn('status', ['reported', 'under_review'])
                ->whereNull('decided_at')
                ->latest('id')
                ->first();
            if ($existing) {
                if ($existing->type === $data['type']) return $existing;

                throw ValidationException::withMessages([
                    'incident' => 'This delivery already has an incident awaiting dispatcher review.',
                ]);
            }
            $incident = $leg->incidents()->create([
                'shop_owner_id' => $leg->shipment->shop_owner_id, 'reporting_rider_profile_id' => $rider->id,
                'type' => $data['type'], 'notes' => $data['notes'], 'photo_paths' => array_values($photoPaths),
                'interrupted_state' => [
                    'status' => $leg->status->value,
                    'assignment_id' => $leg->assignments()->whereIn('status', ['assigned', 'accepted'])->latest('id')->value('id'),
                    'resolution_type' => $leg->resolution_type,
                ],
            ]);
            if ($incident->type === 'lost') {
                $incident->forceFill(['recovery_context' => ['loss' => ['stage' => 'reported']]])->save();
            }
            foreach ($leg->incidents()->whereKeyNot($incident->id)->whereNotNull('decided_at')->where('status', 'under_review')->lockForUpdate()->get() as $prior) {
                if (! data_get($prior->recovery_context, 'execution_superseded_by_incident_id')) {
                    $prior->forceFill(['recovery_context' => [...($prior->recovery_context ?? []), 'execution_superseded_by_incident_id' => $incident->id]])->save();
                }
            }
            $leg->update(['status' => 'needs_resolution']);
            $this->events->record($leg->shipment, $leg, ['event_type' => 'delivery_incident_reported', 'message' => 'A delivery incident requires review.', 'metadata' => ['incident_id' => $incident->id, 'type' => $incident->type]]);
            $this->events->record($leg->shipment, $leg, ['event_type' => 'delivery_incident_reported', 'visibility' => 'customer',
                'message' => 'A delivery issue is being reviewed by the shop.', 'metadata' => ['incident_id' => $incident->id]]);
            return $incident;
        }, 3);
    }

    public function lossAction(DeliveryIncident $incident, \App\Models\User $actor, string $action, array $data): DeliveryIncident
    {
        if (! in_array($action, ['acknowledge', 'confirm', 'approve'], true)) {
            throw ValidationException::withMessages(['action' => 'Choose a supported loss action.']);
        }
        validator($data, ['request_key' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:0'],
            'notes' => ['required', 'string', 'max:1000']])->validate();
        app(LogisticsActorPolicy::class)->assertLossActor($actor, $incident, $action === 'confirm');

        return DB::transaction(function () use ($incident, $actor, $action, $data) {
            $shop = ShopOwner::whereKey($incident->shop_owner_id)->lockForUpdate()->firstOrFail();
            $incident = DeliveryIncident::with('leg.shipment')->lockForUpdate()->findOrFail($incident->id);
            abort_unless($incident->type === 'lost' && $incident->leg
                && (int) $incident->leg->shipment->shop_owner_id === (int) $shop->id, 403);
            $hash = $this->factsHash([$action, $actor->id, $incident->id, $data['expected_revision'], $data['notes']]);
            $prior = $incident->leg->events()->where('event_type', 'parcel_loss_stage')
                ->where('metadata->incident_id', $incident->id)->where('metadata->action_key', $data['request_key'])->first();
            if ($prior) {
                if (data_get($prior->metadata, 'facts_hash') === $hash) return $incident;
                throw ValidationException::withMessages(['request_key' => 'This loss action has different facts.']);
            }
            $expectedStage = ['acknowledge' => 'reported', 'confirm' => 'acknowledged', 'approve' => 'confirmation_requested'][$action];
            if ((int) $incident->revision !== (int) $data['expected_revision'] || $incident->status === 'resolved'
                || $incident->lossStage() !== $expectedStage) {
                throw ValidationException::withMessages(['incident' => 'The loss stage changed. Refresh before continuing.']);
            }
            if ($action === 'approve') {
                if (DeliveryIncident::where('shop_owner_id', $shop->id)->where('recovery_context->recovered_loss->parent_id', $incident->id)
                    ->where('status', '!=', 'resolved')->exists()) {
                    throw ValidationException::withMessages(['recovery' => 'Review the reported physical recovery before approving loss.']);
                }
                $incident = $this->resolve($incident, $shop, 'loss_confirmed', $data['notes'], [], $actor, [], null,
                    ['request_key' => $data['request_key'], 'expected_revision' => $incident->revision, 'findings' => [], 'loss_stage_approval' => true]);
            } else {
                $incident->forceFill(['status' => 'under_review', 'revision' => $incident->revision + 1])->save();
            }
            $loss = data_get($incident->recovery_context, 'loss', []);
            $loss['stage'] = ['acknowledge' => 'acknowledged', 'confirm' => 'confirmation_requested', 'approve' => 'confirmed'][$action];
            $loss[$action] = ['actor_id' => $actor->id, 'at' => now()->toISOString(), 'notes' => $data['notes']];
            $incident->forceFill(['recovery_context' => [...($incident->recovery_context ?? []), 'loss' => $loss]])->save();
            $this->events->record($incident->leg->shipment, $incident->leg, ['event_type' => 'parcel_loss_stage',
                'message' => ['acknowledge' => 'Loss report acknowledged; Rider confirmation is required.',
                    'confirm' => 'Rider requests final loss approval.', 'approve' => 'Parcel loss confirmed - final. Financial processing is separate.'][$action],
                'created_by_type' => $actor::class, 'created_by_id' => $actor->id,
                'metadata' => ['incident_id' => $incident->id, 'stage' => $loss['stage'], 'revision' => $incident->revision,
                    'action_key' => $data['request_key'], 'facts_hash' => $hash]]);
            return $incident->fresh();
        }, 3);
    }

    public function reportRecovery(DeliveryIncident $parent, \App\Models\User $actor, array $data): DeliveryIncident
    {
        app(LogisticsActorPolicy::class)->assertLossActor($actor, $parent, true);
        validator($data, ['request_key' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:0'],
            'notes' => ['required', 'string', 'max:1000'], 'condition' => ['required', 'in:safe,damaged,unsafe,partial'],
            'confirmed' => ['required', 'accepted'], 'photo_paths' => ['required', 'array', 'min:1', 'max:5']])->validate();
        if (count(array_filter($data['photo_paths'], fn ($path) => $this->isSafeEvidencePath($path))) !== count($data['photo_paths'])) {
            throw ValidationException::withMessages(['photos' => 'Recovery photographs must use private incident evidence.']);
        }
        return DB::transaction(function () use ($parent, $actor, $data) {
            ShopOwner::whereKey($parent->shop_owner_id)->lockForUpdate()->firstOrFail();
            $parent = DeliveryIncident::with('leg.shipment')->lockForUpdate()->findOrFail($parent->id);
            abort_unless($parent->type === 'lost' && $parent->leg && (int) $parent->leg->shipment->shop_owner_id === (int) $parent->shop_owner_id, 422);
            $hash = $this->factsHash([$actor->id, $data['notes'], $data['condition'], (bool) $data['confirmed'],
                $data['expected_revision'], $data['evidence_digests'] ?? $data['photo_paths']]);
            $old = DeliveryIncident::where('shop_owner_id', $parent->shop_owner_id)
                ->where('recovery_context->recovered_loss->parent_id', $parent->id)->where('request_key', $data['request_key'])->first();
            if ($old) {
                if ($old->request_facts_hash === $hash) return $old;
                throw ValidationException::withMessages(['request_key' => 'This recovery report has different facts.']);
            }
            if ((int) $parent->revision !== (int) $data['expected_revision']
                || ($parent->status === 'resolved' && $parent->resolution !== 'loss_confirmed')
                || DeliveryIncident::where('shop_owner_id', $parent->shop_owner_id)
                    ->where('recovery_context->recovered_loss->parent_id', $parent->id)->exists()) {
                throw ValidationException::withMessages(['incident' => 'The loss or its recovery changed. Refresh before reporting.']);
            }
            $holder = RiderProfile::where('shop_owner_id', $parent->shop_owner_id)->where('active', true)
                ->where('linked_type', $actor::class)->where('linked_id', $actor->id)->findOrFail($parent->reporting_rider_profile_id);
            $recovery = new DeliveryIncident(['shop_owner_id' => $parent->shop_owner_id, 'shipment_leg_id' => $parent->shipment_leg_id,
                'reporting_rider_profile_id' => $parent->reporting_rider_profile_id, 'type' => 'other', 'notes' => $data['notes'],
                'photo_paths' => $data['photo_paths'], 'interrupted_state' => $parent->interrupted_state]);
            $recovery->forceFill(['request_key' => $data['request_key'], 'request_facts_hash' => $hash,
                'recovery_context' => ['recovered_loss' => ['parent_id' => $parent->id, 'after_final' => $parent->resolution === 'loss_confirmed',
                    'holder_id' => $holder->id, 'condition' => $data['condition'], 'stage' => 'reported',
                    'reported_by_user_id' => $actor->id, 'reported_at' => now()->toISOString(), 'confirmed' => true]]])->save();
            $this->events->record($parent->leg->shipment, $parent->leg, ['event_type' => 'parcel_recovery_reported',
                'message' => 'Recovered parcel reported; Dispatcher verification is required.', 'created_by_type' => $actor::class, 'created_by_id' => $actor->id,
                'metadata' => ['incident_id' => $recovery->id, 'parent_incident_id' => $parent->id, 'action_key' => $data['request_key']]]);
            return $recovery;
        }, 3);
    }

    public function confirmRecoveryReport(DeliveryIncident $incident, \App\Models\User $actor, array $data): DeliveryIncident
    {
        app(LogisticsActorPolicy::class)->assertLossActor($actor, $incident, true);
        validator($data, ['request_key' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:0'],
            'confirmed' => ['required', 'accepted'], 'notes' => ['required', 'string', 'max:1000']])->validate();
        return DB::transaction(function () use ($incident, $actor, $data) {
            ShopOwner::whereKey($incident->shop_owner_id)->lockForUpdate()->firstOrFail();
            $incident = DeliveryIncident::with('leg.shipment')->lockForUpdate()->findOrFail($incident->id);
            $context = data_get($incident->recovery_context, 'recovered_loss');
            abort_unless($context && $incident->evidencePaths(), 422);
            $parent = DeliveryIncident::where('shop_owner_id', $incident->shop_owner_id)
                ->where('shipment_leg_id', $incident->shipment_leg_id)->where('type', 'lost')->lockForUpdate()->find($context['parent_id']);
            if (! $parent || DeliveryIncident::where('shop_owner_id', $incident->shop_owner_id)
                ->where('recovery_context->recovered_loss->parent_id', $parent->id)->where('status', '!=', 'resolved')->count() !== 1) {
                throw ValidationException::withMessages(['incident' => 'Historical recovery links require Dispatcher review before confirmation.']);
            }

            $hash = $this->factsHash([$actor->id, $data]);
            $prior = $incident->leg->events()->where('event_type', 'parcel_recovery_confirmed')
                ->where('metadata->incident_id', $incident->id)->where('metadata->action_key', $data['request_key'])->first();
            if ($prior) {
                if (data_get($prior->metadata, 'facts_hash') === $hash) return $incident;
                throw ValidationException::withMessages(['request_key' => 'This confirmation has different facts.']);
            }
            if ($incident->decided_at || ($context['confirmed'] ?? false) || (int) $incident->revision !== (int) $data['expected_revision']) {
                throw ValidationException::withMessages(['incident' => 'The recovery confirmation changed. Refresh before acting.']);
            }
            $incident->forceFill(['revision' => $incident->revision + 1,
                'recovery_context' => [...$incident->recovery_context, 'recovered_loss' => [...$context, 'confirmed' => true,
                    'confirmed_by_user_id' => $actor->id, 'confirmed_at' => now()->toISOString(), 'confirmation_notes' => $data['notes']]]])->save();
            $this->events->record($incident->leg->shipment, $incident->leg, ['event_type' => 'parcel_recovery_confirmed',
                'message' => 'Reporting Rider confirmed the recovery evidence. Dispatcher review is available; custody is unchanged.',
                'created_by_type' => $actor::class, 'created_by_id' => $actor->id,
                'metadata' => ['incident_id' => $incident->id, 'parent_incident_id' => $context['parent_id'],
                    'action_key' => $data['request_key'], 'facts_hash' => $hash, 'revision' => $incident->revision]]);
            return $incident->fresh();
        }, 3);
    }

    public function reviewRecovery(DeliveryIncident $incident, \App\Models\User $actor, array $data): DeliveryIncident
    {
        app(LogisticsActorPolicy::class)->assertLossActor($actor, $incident, false);
        validator($data, ['request_key' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:0'],
            'notes' => ['required', 'string', 'max:1000'], 'action' => ['required', 'in:return,continue'],
            'transport_notes' => ['sometimes', 'string', 'max:1000'], 'delivery_date' => ['required', 'date_format:Y-m-d'],
            'delivery_window' => ['required', 'in:morning,afternoon'],
            'customer_agreement' => ['required_if:action,continue', 'nullable', 'string', 'max:1000']])->validate();
        return DB::transaction(function () use ($incident, $actor, $data) {
            $shop = ShopOwner::whereKey($incident->shop_owner_id)->lockForUpdate()->firstOrFail();
            $incident = DeliveryIncident::with('leg.shipment')->lockForUpdate()->findOrFail($incident->id);
            $context = data_get($incident->recovery_context, 'recovered_loss');
            abort_unless($context, 422);
            if (! ($context['confirmed'] ?? false)) {
                throw ValidationException::withMessages(['confirmation' => 'The reporting Rider must use Confirm Recovery in Delivery History. No acknowledgement or custody is inferred.']);
            }
            if ($data['action'] === 'return' && $context['condition'] === 'unsafe' && ! filled($data['transport_notes'] ?? null)) {
                throw ValidationException::withMessages(['transport_notes' => 'Record safe handling and transport arrangements for the unsafe parcel.']);
            }
            $hash = $this->factsHash([$actor->id, $data]);
            if ($incident->decided_at) {
                if ($incident->decision_key === $data['request_key'] && data_get($incident->recovery_context, 'review_facts_hash') === $hash) return $incident;
                throw ValidationException::withMessages(['incident' => 'Recovery already reviewed.']);
            }
            if ((int) $incident->revision !== (int) $data['expected_revision']) throw ValidationException::withMessages(['expected_revision' => 'Refresh the recovery.']);
            $parent = DeliveryIncident::where('shop_owner_id', $shop->id)->lockForUpdate()->findOrFail($context['parent_id']);
            abort_unless($parent->type === 'lost' && (int) $parent->shipment_leg_id === (int) $incident->shipment_leg_id, 422);
            if (DeliveryIncident::where('shop_owner_id', $shop->id)->where('recovery_context->recovered_loss->parent_id', $parent->id)->where('status', '!=', 'resolved')->count() > 1) {
                throw ValidationException::withMessages(['recovery' => 'Multiple active historical recoveries require Dispatcher reconciliation review.']);
            }
            if ($data['action'] === 'continue') {
                if ($parent->resolution === 'loss_confirmed' || $context['condition'] !== 'safe') {
                    throw ValidationException::withMessages(['action' => 'Final loss recovery and unsafe goods must return to the original shop.']);
                }
                $shipment = $incident->leg->shipment;
                if ($shipment->source_type === 'order') {
                    $order = \App\Models\Order::where('shop_owner_id', $shop->id)->lockForUpdate()->findOrFail($shipment->source_id);
                    if ($order->payment_status === 'refunded' || $order->refunds()->whereNotIn('status', ['rejected', 'cancelled'])->exists()) {
                        throw ValidationException::withMessages(['refund' => 'Resolve the existing financial obligation before delivery.']);
                    }
                }
            }
            if ($parent->resolution !== 'loss_confirmed') {
                $parent->forceFill(['status' => 'resolved', 'resolution' => 'dismissed', 'resolved_at' => now(), 'decided_at' => now(),
                    'revision' => $parent->revision + 1, 'resolution_note' => 'Physically recovered before final loss approval.'])->save();
            }
            if ($data['action'] === 'continue') {
                $incident = $this->resolve($incident, $shop, 'retry', $data['notes'], [], $actor,
                    [...$data, 'rider_profile_id' => $context['holder_id'], 'handoff_route' => 'direct'], null,
                    ['linked_recovery_review' => true, 'request_key' => $data['request_key'], 'expected_revision' => $incident->revision,
                        'findings' => ['customer_agreement' => $data['customer_agreement'] ?? null]]);
            } else {
                $return = $this->legs->returnRecoveredParcel($incident->leg, $actor, RiderProfile::findOrFail($context['holder_id']), $data);
                $incident->forceFill(['status' => 'under_review', 'resolution' => 'return_required', 'decided_at' => now(),
                    'decided_by_user_id' => $actor->id, 'resolution_note' => $data['notes'], 'decision_key' => $data['request_key'], 'revision' => $incident->revision + 1,
                    'recovery_context' => [...$incident->recovery_context, 'safekeeping_return_leg_id' => $return->id]])->save();
            }
            $incident->forceFill(['recovery_context' => [...$incident->recovery_context, 'review_facts_hash' => $hash,
                'recovered_loss' => [...$context, 'stage' => 'authorized', 'action' => $data['action'],
                    'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now()->toISOString(), 'transport_notes' => $data['transport_notes'] ?? null]]])->save();
            $this->events->record($incident->leg->shipment, $incident->leg, ['event_type' => 'parcel_recovery_authorized',
                'message' => 'Linked recovery movement authorized. Rider handoff, actual shop receipt and item handling remain separate.',
                'created_by_type' => $actor::class, 'created_by_id' => $actor->id,
                'metadata' => ['incident_id' => $incident->id, 'parent_incident_id' => $parent->id, 'revision' => $incident->revision,
                    'action_key' => $data['request_key'], 'resolution' => $incident->resolution]]);
            return $incident->fresh();
        }, 3);
    }

    public function resolve(DeliveryIncident $incident, ShopOwner $shop, string $resolution, string $note, array $evidence = [], ?Authenticatable $actor = null, array $retryOptions = [], ?string $responsibleParty = null, array $decision = []): DeliveryIncident
    {
        if (! in_array($resolution, self::RESOLUTIONS, true)) {
            throw ValidationException::withMessages(['resolution' => 'Choose a supported incident resolution.']);
        }
        if ($responsibleParty !== null && ! in_array($responsibleParty, ['undetermined', 'customer', 'rider', 'shop', 'third_party'], true)) {
            throw ValidationException::withMessages(['responsible_party' => 'Choose an investigation finding.']);
        }
        if (! filled($note)) {
            throw ValidationException::withMessages(['note' => 'A resolution note is required.']);
        }
        if (count(array_filter($evidence, fn ($path) => $this->isSafeEvidencePath($path))) !== count($evidence)) {
            throw ValidationException::withMessages(['evidence' => 'Incident evidence must be stored by the logistics upload flow.']);
        }

        return DB::transaction(function () use ($incident, $shop, $resolution, $note, $evidence, $actor, $retryOptions, $responsibleParty, $decision) {
            ShopOwner::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            if ($batchId = $incident->leg?->delivery_batch_id) {
                \App\Models\Logistics\DeliveryBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();
            }
            $incident = DeliveryIncident::query()->with('leg.shipment')->lockForUpdate()->findOrFail($incident->id);
            if ($incident->shop_owner_id !== $shop->id) abort(403);
            if ($incident->delivery_batch_id) abort(422);
            if ($actor && ($actor instanceof ShopOwner || (int) $actor->shop_owner_id !== (int) $shop->id)) abort(403);
            $findings = $decision['findings'] ?? $decision;
            $key = $decision['request_key'] ?? null;
            $hash = $this->factsHash([$resolution, $note, $decision['evidence_digests'] ?? $evidence, $retryOptions, $responsibleParty, $findings, $actor?->getAuthIdentifier(), $actor ? $actor::class : null]);
            if ($key && ($recorded = $incident->leg->events()->where('event_type', 'parcel_recovery_decided')
                ->where('visibility', 'internal')->where('metadata->incident_id', $incident->id)
                ->where('metadata->action_key', $key)->first())) {
                if (data_get($recorded->metadata, 'facts_hash') === $hash) return $incident;
                throw ValidationException::withMessages(['request_key' => 'This decision request has different facts.']);
            }
            if ($incident->decided_at || $incident->status === 'resolved') {
                if ($incident->decision_facts_hash === $hash && (! $key || $incident->decision_key === $key)) return $incident;
                $canReauthorize = $incident->status === 'under_review' && $incident->resolution === 'retry' && $resolution === 'retry'
                    && $actor instanceof \App\Models\User && $actor->can('resolve-logistics-exceptions')
                    && $key && $key !== $incident->decision_key && isset($decision['expected_revision'])
                    && ! data_get($incident->recovery_context, 'execution_superseded_by_incident_id')
                    && ! app(DeliveryStartEligibility::class)->hasActivated($incident->leg);
                if (! $canReauthorize) {
                    throw ValidationException::withMessages(['resolution' => 'This incident decision is already recorded. Report a new occurrence for a new recovery decision.']);
                }
            }
            if (isset($decision['expected_revision']) && (int) $decision['expected_revision'] !== (int) $incident->revision) {
                throw ValidationException::withMessages(['expected_revision' => 'The incident changed. Refresh before deciding.']);
            }
            $stagedLoss = $resolution === 'loss_confirmed' && $incident->lossStage() !== null;
            if (data_get($incident->recovery_context, 'recovered_loss') && empty($decision['linked_recovery_review'])) {
                throw ValidationException::withMessages(['resolution' => 'Use the linked recovery review with current custody and source safeguards.']);
            }
            if ($incident->lossStage() && $resolution !== 'loss_confirmed') {
                throw ValidationException::withMessages(['resolution' => 'Report a separate physical recovery before authorizing movement for a missing parcel.']);
            }
            if ($stagedLoss && (data_get($incident->recovery_context, 'loss.stage') !== 'confirmation_requested' || empty($decision['loss_stage_approval']))) {
                throw ValidationException::withMessages(['resolution' => 'Acknowledge the report and obtain the reporting Rider confirmation first.']);
            }
            if ($stagedLoss) app(LogisticsActorPolicy::class)->assertLossActor($actor, $incident, false);
            if ($resolution === 'loss_confirmed' && ($incident->type !== 'lost' || !filled($note) || (! $stagedLoss && !$evidence))) {
                throw ValidationException::withMessages(['resolution' => 'Confirmed loss requires a lost incident, investigation note, and evidence.']);
            }
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($incident->shipment_leg_id);
            $eligibility = $this->eligibility($incident, $findings)[$resolution];
            if (! $eligibility['allowed']) {
                throw ValidationException::withMessages(['eligibility' => $eligibility['reason']]);
            }
            if ($resolution === 'dismissed') {
                $snapshot = $incident->interrupted_state;
                $assignmentId = $leg->assignments()->whereIn('status', ['assigned', 'accepted'])->latest('id')->value('id');
                if (! $snapshot || $leg->status->value !== 'needs_resolution'
                    || $leg->resolution_type !== ($snapshot['resolution_type'] ?? null)
                    || (int) $assignmentId !== (int) ($snapshot['assignment_id'] ?? 0)
                    || ! in_array($snapshot['status'] ?? null, ['assigned', 'pickup_scheduled', 'picked_up', 'in_transit', 'delivery_attempted'], true)) {
                    throw ValidationException::withMessages(['incident' => 'The interrupted delivery changed or is unknown. Resolve its current state explicitly.']);
                }
                $leg->update(['status' => $snapshot['status']]);
            }
            $incident->forceFill(['status' => $resolution === 'dismissed' || $stagedLoss ? 'resolved' : 'under_review',
                'resolution' => $resolution, 'resolution_note' => $note, 'responsible_party' => $responsibleParty ?? 'undetermined',
                'resolved_at' => $resolution === 'dismissed' || $stagedLoss ? now() : null, 'decided_at' => now(),
                'decided_by_user_id' => $actor?->getAuthIdentifier(), 'decision_key' => $key ?? (string) \Illuminate\Support\Str::uuid(),
                'decision_facts_hash' => $hash, 'revision' => $incident->revision + 1,
                'recovery_context' => [...($incident->recovery_context ?? []), 'findings' => $findings,
                    'evidence_paths' => array_values(array_unique([...data_get($incident->recovery_context, 'evidence_paths', []), ...$evidence])),
                    'execution_assignment_id' => $leg->assignments()->latest('id')->value('id')],
            ])->save();
            $retryOptions = [...$retryOptions, 'incident_id' => $incident->id, 'incident_revision' => $incident->revision];
            if ($resolution === 'retry' && $leg->delivery_batch_id) {
                $leg = app(BatchDispatchService::class)->detachIncidentStopForRecovery($leg);
            }
            if ($resolution === 'return_required' && $leg->resolution_type === 'retry') $leg->update(['resolution_type' => null]);
            match ($resolution) {
                'loss_confirmed' => $this->legs->confirmLoss($leg, $note),
                'retry' => $this->legs->resolveRetry($leg, $note, $retryOptions, $actor ?? $shop),
                'return_required' => $this->legs->requireReturn($leg, $note, $actor ?? $shop),
                default => null,
            };
            $incident->forceFill(['recovery_context' => [...$incident->recovery_context,
                'execution_assignment_id' => $leg->assignments()->latest('id')->value('id')]])->save();
            $this->events->record($leg->shipment, $leg, [
                    'event_type' => 'parcel_recovery_decided',
                    'message' => 'Recovery decision recorded; follow-up may remain pending.',
                    'metadata' => ['resolution' => $resolution, 'incident_id' => $incident->id, 'revision' => $incident->revision,
                        'action_key' => $incident->decision_key, 'facts_hash' => $hash, 'evidence_paths' => $evidence, 'operational_final' => $stagedLoss,
                        'findings' => $findings, 'resolution_note' => $note, 'responsible_party' => $responsibleParty ?? 'undetermined'],
                    'created_by_type' => ($actor ?? $shop)::class,
                    'created_by_id' => ($actor ?? $shop)->getAuthIdentifier(),
                ]);
            $this->events->record($leg->shipment, $leg, ['event_type' => 'parcel_recovery_decided', 'visibility' => 'customer',
                'message' => 'The shop has recorded a recovery decision. Required handoffs and follow-up remain separate.',
                'metadata' => ['incident_id' => $incident->id, 'revision' => $incident->revision, 'resolution' => $resolution]]);
            return $incident->fresh();
        }, 3);
    }

    /** @return array<string, array{allowed: bool, conditional: bool, label: string, reason: ?string}> */
    public function eligibility(DeliveryIncident $incident, array $findings = []): array
    {
        $leg = $incident->leg;
        $present = $leg && in_array($leg->custody_state, ['rider', 'shop', 'customer'], true) && $leg->custody_confirmed_at;
        $recovered = $incident->type !== 'lost' || (($findings['recovered_verified'] ?? false) === true && $present);
        $priorLoss = $leg?->incidents()->where('resolution', 'loss_confirmed')->exists();
        $inspection = data_get($incident->recovery_context, 'inspection');
        $safe = $incident->type !== 'damaged' || ($inspection && ! ($inspection['quarantined'] ?? true));
        $linkedCondition = data_get($incident->recovery_context, 'recovered_loss.condition');
        $ready = match ($incident->type) {
            'vehicle_problem' => filled($findings['mitigation_plan'] ?? null),
            'customer_dispute' => filled($findings['customer_agreement'] ?? null),
            'other' => $linkedCondition === 'safe' || filled($findings['mitigation_plan'] ?? null),
            default => true,
        };
        $canDeliver = $present && $recovered && ! $priorLoss && $safe && $ready;
        $unsafe = $linkedCondition === 'unsafe' || collect($inspection['items'] ?? [])->contains(fn ($item) => ($item['condition'] ?? null) === 'unsafe');
        $canReturn = $present && in_array($leg->custody_state, ['rider', 'shop'], true)
            && $recovered && ! $priorLoss && (! $unsafe || filled($findings['transport_notes'] ?? null));
        $result = [];
        foreach (self::RESOLUTIONS as $resolution) {
            $allowed = match ($resolution) {
                'dismissed' => $canDeliver,
                'retry' => $canDeliver || ($present && $recovered && ! $priorLoss && $safe && $incident->type === 'vehicle_problem' && filled($findings['mitigation_plan'] ?? null)),
                'return_required' => $canReturn,
                'loss_confirmed' => $incident->type === 'lost',
            };
            $labels = ['dismissed' => 'Issue resolved — continue delivery', 'retry' => 'Arrange another delivery attempt',
                'return_required' => 'Return parcel to shop', 'loss_confirmed' => 'Confirm parcel lost'];
            $conditional = $resolution !== 'loss_confirmed' && $present && ! $priorLoss
                && ($incident->type !== 'lost' || $recovered);
            $result[$resolution] = ['allowed' => (bool) $allowed, 'conditional' => (bool) $conditional, 'label' => $labels[$resolution], 'reason' => $allowed ? null
                : 'Use confirmed custody, actual shop inspection for damaged goods, and document the resolved obstacle or customer arrangement. Unsafe returns require a safe handling plan; final loss uses its linked recovery.'];
        }
        return $result;
    }

    private function factsHash(array $facts): string
    {
        $normalize = function (array $values) use (&$normalize): array {
            if (! array_is_list($values)) ksort($values);
            return array_map(fn ($value) => is_array($value) ? $normalize($value) : $value, $values);
        };
        return hash('sha256', json_encode($normalize($facts), JSON_THROW_ON_ERROR));
    }

    public function closeRecovery(DeliveryIncident $incident, \App\Models\User $actor, string $outcome, string $note, ShipmentLeg $executionLeg): DeliveryIncident
    {
        abort_unless((int) $actor->shop_owner_id === (int) $incident->shop_owner_id && $actor->can('resolve-logistics-exceptions'), 403);
        $source = $incident->leg->shipment;
        $leg = ShipmentLeg::whereKey($executionLeg->id)->whereHas('shipment', fn ($sources) => $sources
            ->where('shop_owner_id', $incident->shop_owner_id)->where('source_type', $source->source_type)
            ->where('source_id', $source->source_id)->where('purpose', $source->purpose))->firstOrFail();
        $verified = match ($outcome) {
            'confirmed_loss' => $incident->resolution === 'loss_confirmed' && $leg->status->value === 'cancelled'
                && $leg->custody_state === 'lost' && count($incident->evidencePaths()) > 0,
            'delivery_completed' => $leg->status->value === 'delivered' && $leg->proofs()->where('handoff_type', 'delivery')->where('review_status', 'approved')
                ->where('delivery_assignment_id', $leg->assignments()->latest('id')->value('id'))->exists(),
            'shop_received' => $leg->custody_state === 'shop' && $leg->custody_confirmed_at
                && data_get($incident->recovery_context, 'receipt') && data_get($incident->recovery_context, 'inspection')
                && in_array(data_get($incident->recovery_context, 'disposition.action'), ['refund_review', 'repair_recovery'], true),
            'safekeeping_received' => $incident->resolution === 'loss_confirmed' && $leg->custody_state === 'shop'
                && data_get($incident->recovery_context, 'inspection')
                && (int) data_get($incident->recovery_context, 'safekeeping_return_leg_id') === (int) $leg->returnLeg?->id
                && $leg->returnLeg?->status->value === 'delivered'
                && $leg->returnLeg->proofs()->where('handoff_type', 'receive')->where('review_status', 'approved')->exists(),
            default => false,
        };
        if (! $verified || ! filled($note)) {
            throw ValidationException::withMessages(['outcome' => 'A verified operational outcome is required. Creating a retry, receipt or escalation alone cannot close recovery.']);
        }
        $incident->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
        return $incident;
    }

    private function isSafeEvidencePath(mixed $path): bool
    {
        return is_string($path)
            && str_starts_with($path, 'incident-evidence/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');
    }
}
