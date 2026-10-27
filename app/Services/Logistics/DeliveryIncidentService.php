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
            || empty($photoPaths)
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
            if ($resolution === 'loss_confirmed' && ($incident->type !== 'lost' || !filled($note) || !$evidence)) {
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
            $incident->forceFill(['status' => $resolution === 'dismissed' ? 'resolved' : 'under_review',
                'resolution' => $resolution, 'resolution_note' => $note, 'responsible_party' => $responsibleParty ?? 'undetermined',
                'resolved_at' => $resolution === 'dismissed' ? now() : null, 'decided_at' => now(),
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
                        'action_key' => $incident->decision_key, 'facts_hash' => $hash, 'evidence_paths' => $evidence,
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
        $safe = $incident->type !== 'damaged' || (($findings['condition_verified'] ?? false) === true
            && ($findings['safe_for_delivery'] ?? false) === true && ! empty($findings['items']));
        $ready = match ($incident->type) {
            'vehicle_problem' => ($findings['obstacle_resolved'] ?? false) === true,
            'customer_dispute' => ($findings['dispute_resolved'] ?? false) === true && filled($findings['customer_agreement'] ?? null),
            'other' => ($findings['operational_ready'] ?? false) === true,
            default => true,
        };
        $canDeliver = $present && $recovered && ! $priorLoss && $safe && $ready;
        $canReturn = $present && in_array($leg->custody_state, ['rider', 'shop'], true)
            && $recovered && ! $priorLoss && ($findings['safe_for_transport'] ?? false) === true;
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
                : 'Verify actual custody, parcel condition, resolved obstacle/dispute and required customer agreement. A confirmed loss requires supervised reconciliation.'];
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
