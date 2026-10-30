<?php

namespace App\Services\Logistics;

use App\Models\Logistics\DeliveryIncident;
use App\Models\Logistics\HandoffProof;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ParcelIncidentRecoveryService
{
    public function __construct(
        private LogisticsActorPolicy $policy,
        private ShipmentLegService $legs,
        private ShipmentCustodyService $custody,
        private DeliveryEventService $events,
        private DeliveryIncidentService $incidents,
    ) {}

    public function pendingFor(User $actor): array
    {
        $incidents = DeliveryIncident::with(['leg.shipment.shopOwner.modules', 'leg.custodyRider', 'leg.returnLeg.proofs', 'leg.proofs', 'leg.custodyTransfers', 'leg.assignments', 'leg.events'])
            ->where('shop_owner_id', $actor->shop_owner_id)->whereNotNull('shipment_leg_id')
            ->whereNotNull('recovery_context')->where('resolution', '!=', 'dismissed')->orderBy('id')->get();
        $this->policy->loadParcelRecoverySources($incidents->pluck('leg.shipment'));
        $movements = \App\Models\Logistics\Shipment::with(['legs.assignments', 'legs.custodyRider'])->where('shop_owner_id', $actor->shop_owner_id)
            ->whereIn('id', $incidents->map(fn ($incident) => data_get($incident->recovery_context, 'source_outcome.shipment_id'))->filter())->get()->keyBy('id');
        $transfers = $this->custody->pendingFor($actor);
        return $incidents->filter(fn ($incident) => (data_get($incident->recovery_context, 'loss.stage') !== 'confirmed'
                || $incident->leg->shipment->source_type === 'repair_request')
                && $this->policy->decideParcelRecovery($actor, $incident->leg)['allowed'])
            ->map(function ($incident) use ($actor, $movements, $transfers) {
                $incident->setRelation('recoveryMovement', $movements->get(data_get($incident->recovery_context, 'source_outcome.shipment_id')));
                return $this->project($incident, $actor, $transfers);
            })->filter(fn ($item) => ! $item['complete'])->values()->all();
    }

    public function project(DeliveryIncident $incident, User $actor, ?array $transfers = null): array
    {
        abort_unless($incident->leg && $this->policy->decideParcelRecovery($actor, $incident->leg)['allowed'], 403);
        $leg = $incident->leg;
        $physicalLeg = $this->currentMovementLeg($incident);
        $context = $incident->recovery_context ?? [];
        $obligations = $this->obligations($incident);
        $closed = $incident->status === 'resolved';
        $inspected = data_get($context, 'inspection') !== null;
        $atShop = $physicalLeg->custody_state === 'shop' && $physicalLeg->custody_confirmed_at;
        $received = (bool) data_get($context, 'receipt') || ($atShop && ($physicalLeg->proofs->contains(fn ($proof) => $proof->handoff_type === 'receive' && $proof->review_status === 'approved' && $proof->reviewed_by_type === User::class && $proof->reviewed_by_id)
            || $physicalLeg->custodyTransfers->contains(fn ($transfer) => $transfer->to_type === 'shop' && $transfer->status === 'received' && $transfer->received_by_user_id)));
        $source = $leg->shipment;
        if (! $source->relationLoaded('parcelRecoverySource')) $this->policy->loadParcelRecoverySources(collect([$source]));
        $expectedItems = $this->expectedItems($source);
        $available = ! $closed && $atShop && $inspected && $this->supportedSource($source);
        $canReauthorize = ! $closed && $incident->resolution === 'retry' && $actor->can('resolve-logistics-exceptions')
            && $this->supportedSource($source) && ! data_get($context, 'execution_superseded_by_incident_id') && ! app(DeliveryStartEligibility::class)->hasActivated($leg);
        $handoffs = $transfers ?? $this->custody->pendingFor($actor);
        $assignment = $physicalLeg->assignments->sortByDesc('id')->first();
        $blocker = match (true) {
            ! $this->supportedSource($source) => 'Retain source identity and evidence; Dispatcher must own the unsupported-source investigation.',
            ! $atShop => 'Shop actions require actual shop custody. Continue the current movement or evidenced handoff.',
            ! $inspected => 'Record item identity, actual quantity and condition before source disposition.',
            (bool) data_get($context, 'inspection.quarantined', false) => 'Keep discrepant or unsafe goods quarantined and use owned investigation.',
            default => null,
        };
        $actions = [
            ['action' => 'verify_readiness', 'label' => 'Verify resolved condition and customer arrangement', 'allowed' => $available && $actor->can('resolve-logistics-exceptions')],
            ['action' => 'ready_for_retry', 'label' => 'Submit retry readiness to Dispatcher', 'allowed' => $available && $incident->resolution === 'retry' && ! data_get($context, 'inspection.quarantined', true)],
            ['action' => 'authorize_retry_handoff', 'label' => 'Arrange inspected outbound handoff', 'allowed' => ($available || ($closed && $atShop && $inspected && $source->source_type === 'repair_request' && $physicalLeg->id !== $leg->id))
                && ($incident->resolution === 'retry' || $physicalLeg->id !== $leg->id) && $assignment
                && $assignment->status === 'accepted' && $physicalLeg->scheduled_delivery_date
                && $actor->can('resolve-logistics-exceptions') && (bool) data_get($context, 'readiness.inspected_revision')
                && ! collect($handoffs)->contains(fn ($transfer) => (int) $transfer['shipment_leg_id'] === (int) $physicalLeg->id)],
            ['action' => 'redeliver_original', 'label' => 'Authorize original-parcel redelivery', 'allowed' => $available && $source->source_type === 'order'
                && $actor->can('resolve-logistics-exceptions') && ! data_get($context, 'inspection.quarantined', true) && ! data_get($context, 'source_outcome.shipment_id')],
            ['action' => 'refund_review', 'label' => 'Submit refund eligibility review', 'allowed' => ($available || ($closed && $atShop && $inspected && $incident->resolution === 'return_required')) && in_array($source->source_type, ['order', 'order_refund'], true) && ! data_get($context, 'source_outcome.shipment_id')],
            ['action' => 'repair_recovery', 'label' => 'Continue existing repair/customer arrangement', 'allowed' => $available && $source->source_type === 'repair_request'],
            ['action' => 'escalate', 'label' => 'Escalate for source investigation', 'allowed' => ! $closed],
        ];
        if ($incident->resolution === 'return_required' && $source->source_type === 'order' && ! data_get($context, 'recovered_loss')) {
            $actions[] = ['action' => 'inventory_disposition', 'label' => 'Complete inspected item handling',
                'allowed' => $available && (bool) data_get($context, 'receipt')];
        }
        if (data_get($context, 'recovered_loss')) {
            $actions = [['action' => $source->source_type === 'repair_request' ? 'repair_recovery' : 'inventory_disposition',
                'label' => $source->source_type === 'repair_request' ? 'Record customer-property disposition' : 'Apply inspected inventory disposition',
                'allowed' => $available && (bool) data_get($context, 'receipt')],
                ['action' => 'escalate', 'label' => 'Escalate unsupported disposition', 'allowed' => ! $closed]];
        }
        return ['incident_id' => $incident->id, 'revision' => $incident->revision, 'type' => $incident->type,
            'evidence_urls' => array_map(fn ($path) => route('logistics.api.parcel-recovery.evidence', [$incident, hash('sha256', $path)]), $this->evidencePaths($incident)),
            'can_reauthorize' => $canReauthorize, 'retry_findings' => $canReauthorize ? ($context['findings'] ?? []) : null,
            'can_investigate' => $incident->resolution === 'loss_confirmed' || data_get($context, 'disposition.action') === 'escalate',
            'can_supervise_recovery' => false,
            'safekeeping_pending' => (bool) data_get($context, 'safekeeping_return_leg_id') && ! $received,
            'resolution' => $incident->resolution, 'operational_status' => $incident->status,
            'source_type' => $leg->shipment->source_type, 'source_id' => $leg->shipment->source_id,
            'shipment_id' => $leg->shipment_id, 'delivery_id' => $leg->id,
            'current_delivery_id' => $physicalLeg->id, 'movement_status' => $physicalLeg->status->value,
            'can_inspect' => (bool) $atShop && (bool) $received && (! $closed || (! data_get($context, 'recovered_loss') && (bool) data_get($context, 'safekeeping_return_leg_id'))),
            'custody' => $physicalLeg->custody_state, 'received' => (bool) $received, 'inspected' => $inspected,
            'holder_label' => $physicalLeg->custody_state === 'rider'
                ? ((int) $physicalLeg->custodyRider?->shop_owner_id === (int) $incident->shop_owner_id ? $physicalLeg->custodyRider->name : 'Unverified Rider custody')
                : $physicalLeg->custody_state,
            'shop_action_blocker' => $blocker,
            'quarantined' => (bool) data_get($context, 'inspection.quarantined', false),
            'next_action' => $closed ? 'linked_obligations' : ($incident->resolution === 'loss_confirmed' ? 'investigation'
                : ($physicalLeg->id !== $leg->id ? 'current_delivery' : (! $received ? 'receipt' : (! $inspected ? 'inspection' : 'disposition')))),
            'obligations' => $obligations, 'complete' => $closed && ! collect($obligations)->contains(fn ($row) => ! $row['complete']),
            'return_leg_id' => $leg->returnLeg?->id,
            'retry_context' => [...$leg->only(['id', 'sequence', 'leg_type', 'status', 'custody_state', 'custody_rider_profile_id', 'customer_appointment_date', 'customer_appointment_window']),
                'assignments' => $leg->assignments->map(fn ($assignment) => $assignment->only(['id', 'status', 'rider_profile_id']))->all()],
            'expected_items' => $expectedItems ?: [['reference' => $source->source_type.':'.$source->source_id,
                'label' => 'Unidentified source parcel - retain actual quantities and escalate', 'expected_quantity' => 0]],
            'actions' => $actions, 'can_close' => ! $closed && ! data_get($context, 'recovered_loss') && $actor->can('resolve-logistics-exceptions'),
            'source_outcome' => array_intersect_key($context['source_outcome'] ?? [], array_flip(['shipment_id', 'leg_id', 'refund_id', 'repair_request_id'])),
            'can_receive' => ! data_get($context, 'receipt') && (($atShop && $received) || (bool) $leg->returnLeg?->proofs->where('handoff_type', 'receive')->where('review_status', 'rider_confirmed')->count()),
            'receipt_recorded' => (bool) data_get($context, 'receipt'),
            'return_proof_id' => $leg->returnLeg?->proofs->where('handoff_type', 'receive')->whereIn('review_status', ['rider_confirmed', 'approved'])->sortByDesc('id')->first()?->id,
            'transfers' => array_values(array_filter($handoffs, fn ($transfer) => in_array((int) $transfer['shipment_leg_id'], [(int) $leg->id, (int) $physicalLeg->id], true))),
        ];
    }

    public function obligations(DeliveryIncident $incident): array
    {
        $shipment = $incident->leg->shipment;
        if (! $shipment->relationLoaded('parcelRecoverySource')) $this->policy->loadParcelRecoverySources(collect([$shipment]));
        $source = $shipment->getRelation('parcelRecoverySource');
        $rows = [];
        if (! $this->supportedSource($shipment)) $rows[] = ['type' => 'source_review', 'id' => $incident->id, 'status' => 'unsupported_source', 'complete' => false,
            'next_actor' => 'dispatcher_and_source_owner', 'note' => 'Retain source identity and evidence. No source or financial outcome is inferred.'];
        $refunds = match ($shipment->source_type) {
            'order' => $source?->refunds ?? collect(),
            'order_refund' => $source ? collect([$source]) : collect(),
            default => collect(),
        };
        $rows = [...$rows, ...$refunds->map(fn ($refund) => ['type' => 'refund', 'id' => $refund->id, 'status' => $refund->status,
            'complete' => in_array($refund->status, ['succeeded', 'completed', 'rejected', 'cancelled'], true), 'next_actor' => 'existing_refund_workflow'])->all()];
        if ($shipment->source_type === 'order' && ($cash = $source?->codCollection) && $cash->collected_at) {
            $rows[] = ['type' => 'cod_cash', 'id' => $cash->id, 'status' => $cash->status,
                'complete' => $cash->status === \App\Models\CodCollection::STATUS_SETTLED, 'next_actor' => 'original_collector_and_cod_finance'];
        }
        if ($shipment->source_type === 'repair_request') {
            $repair = $source;
            $rows[] = ['type' => 'repair', 'id' => $shipment->source_id, 'status' => $repair?->status,
                'complete' => ($repair?->status === 'picked_up' && $repair->picked_up_at) || $repair?->status === 'cancelled', 'next_actor' => 'existing_repair_workflow'];
            if ($reconciliation = $repair?->logistics_payment_reconciliation) {
                $pending = data_get($reconciliation, 'status') !== 'resolved' || collect(data_get($reconciliation, 'entries', []))
                    ->contains(fn ($entry) => in_array(data_get($entry, 'status', 'pending'), ['pending', 'processing', 'failed'], true));
                $rows[] = ['type' => 'delivery_fee_reconciliation', 'id' => $repair->id, 'status' => $pending ? 'pending' : 'resolved',
                    'complete' => ! $pending, 'next_actor' => 'existing_repair_finance_workflow'];
            }
        }
        if ($shipmentId = data_get($incident->recovery_context, 'source_outcome.shipment_id')) {
            $movement = $incident->relationLoaded('recoveryMovement') ? $incident->getRelation('recoveryMovement')
                : \App\Models\Logistics\Shipment::where('shop_owner_id', $shipment->shop_owner_id)->find($shipmentId);
            $rows[] = ['type' => 'redelivery', 'id' => $shipmentId, 'status' => $movement?->status?->value,
                'complete' => $movement?->status?->value === 'completed', 'next_actor' => 'dispatcher_and_current_rider'];
        }
        if ($incident->resolution === 'return_required' && $shipment->source_type === 'order'
            && $source?->payment_status === 'paid' && $refunds->isEmpty() && ! data_get($incident->recovery_context, 'source_outcome.shipment_id')) {
            $rows[] = ['type' => 'return_financial_review', 'id' => $shipment->source_id, 'status' => 'review_required',
                'complete' => false, 'next_actor' => 'staff_and_existing_refund_workflow'];
        }
        if (($incident->resolution === 'loss_confirmed' && (data_get($incident->recovery_context, 'loss.stage') !== 'confirmed' || $shipment->source_type !== 'order'))
            || data_get($incident->recovery_context, 'disposition.action') === 'escalate') {
            $rows[] = ['type' => 'investigation', 'id' => $incident->id, 'status' => 'pending_review', 'complete' => false,
                'next_actor' => 'dispatcher_and_source_owner', 'note' => 'Investigation/escalation remains actionable; no property-value payout is authorized.'];
        }
        return $rows;
    }

    private function expectedItems(\App\Models\Logistics\Shipment $shipment): array
    {
        if (! $shipment->relationLoaded('parcelRecoverySource')) $this->policy->loadParcelRecoverySources(collect([$shipment]));
        $source = $shipment->getRelation('parcelRecoverySource');
        if (! $source) return [];
        if ($shipment->source_type === 'order') return $source->items->map(fn ($item) => [
            'reference' => 'order-item:'.$item->id, 'label' => $item->product_name, 'expected_quantity' => (int) $item->quantity])->all();
        if ($shipment->source_type === 'order_refund') return $source->items->map(fn ($item) => [
            'reference' => 'refund-item:'.$item->id, 'label' => 'Refund item #'.$item->id, 'expected_quantity' => (int) $item->requested_qty])->all();
        return [['reference' => $shipment->source_type.':'.$shipment->source_id, 'label' => 'Customer parcel', 'expected_quantity' => 1]];
    }

    private function supportedSource(\App\Models\Logistics\Shipment $shipment): bool
    {
        return $shipment->getRelation('parcelRecoverySource') && in_array($shipment->purpose, match ($shipment->source_type) {
            'order' => ['retail_delivery'], 'order_refund' => ['refund_return'], 'repair_request' => ['repair_pickup', 'repair_return'], default => [],
        }, true);
    }

    private function currentMovementLeg(DeliveryIncident $incident): \App\Models\Logistics\ShipmentLeg
    {
        $original = $incident->leg;
        if (data_get($incident->recovery_context, 'recovered_loss') && ($id = data_get($incident->recovery_context, 'safekeeping_return_leg_id'))) {
            return \App\Models\Logistics\ShipmentLeg::where('shipment_id', $original->shipment_id)
                ->where('return_for_leg_id', $original->id)->findOrFail($id);
        }
        $source = $original->shipment;
        if ($id = data_get($incident->recovery_context, 'source_outcome.leg_id')) {
            $movement = $incident->relationLoaded('recoveryMovement') ? $incident->getRelation('recoveryMovement')
                : \App\Models\Logistics\Shipment::with(['legs.assignments', 'legs.custodyRider'])->where('shop_owner_id', $source->shop_owner_id)
                    ->where('source_type', $source->source_type)->where('source_id', $source->source_id)->where('purpose', $source->purpose)
                    ->find(data_get($incident->recovery_context, 'source_outcome.shipment_id'));
            abort_unless($movement && $movement->source_type === $source->source_type && (int) $movement->source_id === (int) $source->source_id
                && $movement->purpose === $source->purpose, 422);
            return $movement->legs->where('id', $id)->firstOrFail();
        }
        if ($source->source_type === 'repair_request' && data_get($incident->recovery_context, 'source_outcome.repair_request_id')) {
            if (! $source->relationLoaded('parcelRecoverySource')) $this->policy->loadParcelRecoverySources(collect([$source]));
            $movement = $source->getRelation('parcelRecoverySource')->logisticsShipments->where('purpose', $source->purpose)->sortByDesc('id')->first();
            return $movement?->legs->whereNull('return_for_leg_id')->sortByDesc('id')->first() ?? $original;
        }
        return $original;
    }

    public function evidencePaths(DeliveryIncident $incident): array
    {
        $custody = $incident->leg->proofs->filter(fn ($proof) => (int) $proof->shop_owner_id === (int) $incident->shop_owner_id
            && in_array($proof->handoff_type, ['custody_release', 'custody_receipt'], true))->pluck('file_path');
        $returned = $incident->leg->returnLeg?->proofs->filter(fn ($proof) => (int) $proof->shop_owner_id === (int) $incident->shop_owner_id
            && $proof->handoff_type === 'receive')->pluck('file_path') ?? collect();
        return array_values(array_unique([...$incident->evidencePaths(), ...$custody->filter()->all(), ...$returned->filter()->all()]));
    }

    public function receive(DeliveryIncident $incident, User $actor, array $data): array
    {
        return $this->mutate($incident, $actor, 'received', $data, function ($locked) use ($actor, $data) {
            $leg = $locked->leg;
            if (! ($data['proof_id'] ?? null)) {
                $movement = $this->currentMovementLeg($locked);
                $transfer = $movement->custodyTransfers()->where('to_type', 'shop')->where('status', 'received')
                    ->where('received_by_user_id', $actor->id)->latest('id')->lockForUpdate()->first();
                if (! $transfer || $movement->custody_state !== 'shop' || ! $movement->custody_confirmed_at) {
                    throw ValidationException::withMessages(['receipt' => 'Complete the actual employee handoff or Rider return proof before confirming receipt.']);
                }
                if (data_get($locked->recovery_context, 'receipt')) {
                    throw ValidationException::withMessages(['receipt' => 'Shop receipt is already recorded.']);
                }
                if (! data_get($locked->recovery_context, 'recovered_loss.after_final')) $this->legs->completeIncidentShopReceipt($movement);
                return ['receipt' => ['return_leg_id' => $movement->id, 'transfer_id' => $transfer->id,
                    'actor_id' => $actor->id, 'received_at' => $transfer->received_at->toISOString(),
                    'evidence_path' => $data['evidence_path'], 'notes' => $data['notes']]];
            }
            $return = $leg->returnLeg()->firstOrFail();
            $proof = HandoffProof::where('shipment_leg_id', $return->id)->findOrFail($data['proof_id'] ?? null);
            if (data_get($locked->recovery_context, 'receipt')) {
                throw ValidationException::withMessages(['receipt' => 'Shop receipt is already recorded. Replay its original request identity; do not replace its receiver.']);
            }
            if ($proof->review_status === 'approved') {
                abort_unless($return->custody_state === 'shop' && $return->custody_confirmed_at
                    && $proof->reviewed_by_type === User::class && (int) $proof->reviewed_by_id === (int) $actor->id, 403);
            } else {
                $this->legs->confirmReturnReceipt($return, $proof, $leg->shipment->shopOwner, $actor);
                $proof->refresh();
            }
            $reference = ['return_leg_id' => $return->id, 'proof_id' => $proof->id];
            return ['receipt' => [...$reference, 'actor_id' => $actor->id, 'received_at' => $proof->reviewed_at->toISOString(),
                'evidence_path' => $data['evidence_path'], 'notes' => $data['notes']]];
        });
    }

    public function inspect(DeliveryIncident $incident, User $actor, array $data): array
    {
        return $this->mutate($incident, $actor, 'inspected', $data, function ($locked) use ($actor, $data) {
            $physicalLeg = $this->currentMovementLeg($locked);
            if ($physicalLeg->custody_state !== 'shop' || ! $physicalLeg->custody_confirmed_at || ! $this->project($locked, $actor)['received']) {
                throw ValidationException::withMessages(['receipt' => 'Confirm actual shop receipt before inspecting.']);
            }
            $quarantined = collect($data['items'])->contains(fn ($item) => $item['condition'] !== 'safe');
            $source = $locked->leg->shipment;
            $expected = collect($this->expectedItems($source));
            $actual = collect($data['items']);
            $quarantined = $quarantined || ! $this->supportedSource($source) || $expected->isEmpty() || $actual->count() !== $expected->count()
                || $expected->contains(fn ($item) => ! $actual->contains(fn ($row) => $row['reference'] === $item['reference']
                    && (int) $row['quantity'] === $item['expected_quantity']));
            return ['readiness' => null, 'inspection' => ['items' => $data['items'], 'notes' => $data['notes'], 'actor_id' => $actor->id, 'revision' => $locked->revision + 1,
                'inspected_at' => now()->toISOString(), 'quarantined' => $quarantined,
                'evidence_path' => $data['evidence_path'] ?? data_get($locked->recovery_context, 'receipt.evidence_path')]];
        });
    }

    public function close(DeliveryIncident $incident, User $actor, array $data): array
    {
        abort_unless($actor->can('resolve-logistics-exceptions'), 403);
        return $this->mutate($incident, $actor, 'closed', $data, function ($locked) use ($actor, $data) {
            $this->incidents->closeRecovery($locked, $actor, $data['outcome'], $data['notes'], $this->currentMovementLeg($locked));
            return ['operational_outcome' => ['outcome' => $data['outcome'], 'notes' => $data['notes'],
                'closed_by_user_id' => $actor->id, 'closed_at' => now()->toISOString()]];
        });
    }

    public function investigate(DeliveryIncident $incident, User $actor, array $data): array
    {
        return $this->mutate($incident, $actor, 'investigated', $data, function ($locked) use ($actor, $data) {
            if ($locked->resolution !== 'loss_confirmed' && data_get($locked->recovery_context, 'disposition.action') !== 'escalate') abort(422);
            if (! empty($data['recovered_rider_profile_id'])) {
                throw ValidationException::withMessages(['recovery' => 'The reporting Rider must use Report Recovery on the original incident. Dispatcher review then authorizes normal acceptance, pickup and shop receipt.']);
            }
            return ['investigation' => ['notes' => $data['notes'], 'actor_id' => $actor->id, 'recorded_at' => now()->toISOString(),
                'evidence_path' => $data['evidence_path'] ?? null, 'next_actor' => 'dispatcher_and_source_owner']];
        });
    }

    public function disposition(DeliveryIncident $incident, User $actor, array $data): array
    {
        return $this->mutate($incident, $actor, 'dispositioned', $data, function ($locked) use ($actor, $data) {
            $action = $data['action'];
            if (data_get($locked->recovery_context, 'recovered_loss')
                && ! in_array($action, ['inventory_disposition', 'repair_recovery', 'escalate'], true)) {
                throw ValidationException::withMessages(['action' => 'Use the linked recovery disposition; financial and source arrangements remain independent.']);
            }
            if (! in_array($action, ['verify_readiness', 'redeliver_original', 'ready_for_retry', 'authorize_retry_handoff',
                'refund_review', 'repair_recovery', 'escalate', 'inventory_disposition'], true)) {
                throw ValidationException::withMessages(['action' => 'Choose a supported source disposition.']);
            }
            if ($action !== 'escalate' && ! $this->supportedSource($locked->leg->shipment)) {
                throw ValidationException::withMessages(['source' => 'This source requires owned investigation; no fulfillment or financial disposition can be inferred.']);
            }
            $inspection = data_get($locked->recovery_context, 'inspection');
            if ($action !== 'escalate' && (! $inspection || $this->currentMovementLeg($locked)->custody_state !== 'shop')) {
                throw ValidationException::withMessages(['inspection' => 'Shop custody and inspection are required before source disposition.']);
            }
            $outcome = [];
            if ($action === 'inventory_disposition') {
                if ((! data_get($locked->recovery_context, 'recovered_loss') && $locked->resolution !== 'return_required') || ! data_get($locked->recovery_context, 'receipt')) {
                    throw ValidationException::withMessages(['receipt' => 'An authorized returned parcel and actual shop receipt are required.']);
                }
                $inventory = app(\App\Services\RefundInventoryDispositionService::class)->applyRecoveredParcel($locked, $actor);
                $outcome = ['inventory_disposition' => $inventory];
                $locked->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
            } elseif ($action === 'verify_readiness') {
                abort_unless($actor->can('resolve-logistics-exceptions'), 403);
                $findings = [...data_get($locked->recovery_context, 'findings', []), ...($data['findings'] ?? []),
                    'mitigation_plan' => $data['findings']['mitigation_plan'] ?? $data['notes'], 'items' => $inspection['items']];
                $eligible = $this->incidents->eligibility($locked, $findings)['retry'];
                if (($inspection['quarantined'] ?? true) || ! $eligible['allowed']) {
                    throw ValidationException::withMessages(['findings' => $eligible['reason'] ?? 'Goods remain quarantined. Verify actual condition and customer arrangement.']);
                }
                $outcome = ['findings' => $findings, 'readiness' => ['inspected_revision' => $inspection['revision'],
                    'actor_id' => $actor->id, 'ready_at' => now()->toISOString()]];
            } elseif ($action === 'redeliver_original') {
                $shipment = app(SourceShipmentService::class)->requestRetailRedelivery($locked, $actor, $data);
                $outcome = ['source_outcome' => ['shipment_id' => $shipment->id, 'leg_id' => $shipment->legs->first()->id,
                    'inspected_revision' => $inspection['revision']]];
            } elseif ($action === 'refund_review') {
                // The refund service remains authoritative for captured balances, approval and stock.
                $shipment = $locked->leg->shipment;
                if ($shipment->source_type === 'order_refund') {
                    $refund = OrderRefund::where('shop_owner_id', $locked->shop_owner_id)->findOrFail($shipment->source_id);
                } else {
                    if ($shipment->source_type !== 'order' || $shipment->purpose !== 'retail_delivery'
                        || data_get($locked->recovery_context, 'source_outcome.shipment_id')) abort(422);
                    $order = \App\Models\Order::with('items')->where('shop_owner_id', $locked->shop_owner_id)->lockForUpdate()->findOrFail($shipment->source_id);
                    $refund = $order->refunds()->whereNotIn('status', ['rejected', 'cancelled'])->lockForUpdate()->latest('id')->first();
                    if (! $refund) {
                        $cod = in_array(strtolower((string) $order->payment_method), ['cod', 'cash_on_delivery', 'cash on delivery', 'cash'], true);
                        if (! $cod && (! in_array($order->payment_status, ['paid', 'completed'], true) || ! $order->paymongo_payment_id)) {
                            throw ValidationException::withMessages(['payment' => 'No verified captured payment is available for refund review.']);
                        }
                        $reservation = app(\App\Services\OrderRefundService::class)->reserveOrderRefund($order, [
                            'flow_type' => 'request_approval', 'status' => 'requested', 'shop_owner_status' => 'pending', 'finance_status' => 'pending',
                            'return_status' => 'in_transit', 'return_source' => 'staff', 'staff_return_carrier' => 'Shop-owned logistics',
                            'payment_gateway' => $cod ? 'xendit' : 'paymongo', 'paymongo_payment_id' => $cod ? null : $order->paymongo_payment_id,
                            'currency' => 'PHP', 'requested_refund_method' => $cod ? 'customer_selected' : 'original_payment_method',
                            'refund_provider' => $cod ? 'xendit' : null, 'reason_code' => 'parcel_incident_recovery', 'reason_note' => $data['notes'],
                            'idempotency_key' => 'parcel-incident:'.$locked->id, 'requested_at' => now(),
                        ], $order->items->map(fn ($item) => ['order_item_id' => $item->id, 'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id, 'requested_qty' => $item->quantity,
                            'unit_price_snapshot' => $item->price, 'line_amount' => round((float) $item->price * (int) $item->quantity, 2),
                            'inspection_disposition' => 'pending', 'inventory_action' => 'pending'])->all());
                        $refund = $reservation['refund'] ?? null;
                        if (! $refund || ! in_array($reservation['result'], ['reserved', 'recovered', 'collision'], true)) {
                            throw ValidationException::withMessages(['refund' => $reservation['message'] ?? 'Refund eligibility requires existing Finance review.']);
                        }
                        if ($reservation['result'] === 'reserved') {
                            $refundId = $refund->id;
                            DB::afterCommit(fn () => app(\App\Services\OrderRefundService::class)->notifyRefundApprovalRequested(OrderRefund::findOrFail($refundId)));
                        }
                    }
                }
                $outcome = ['source_outcome' => ['refund_id' => $refund->id, 'next_actor' => 'existing_refund_workflow']];
            } elseif ($action === 'repair_recovery') {
                if ($locked->leg->shipment->source_type !== 'repair_request') abort(422);
                if (data_get($locked->recovery_context, 'recovered_loss') || $locked->resolution === 'return_required') {
                    $items = collect($inspection['items'] ?? []);
                    $reference = 'repair_request:'.$locked->leg->shipment->source_id;
                    if (! data_get($locked->recovery_context, 'receipt') || $items->count() !== 1
                        || $items->first()['reference'] !== $reference || (int) $items->first()['quantity'] !== 1
                        || ! in_array($items->first()['condition'], ['safe', 'damaged', 'unsafe'], true)) {
                        throw ValidationException::withMessages(['inspection' => 'Verify actual customer-property receipt and supported safe disposition first.']);
                    }
                    $locked->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
                }
                $outcome = ['source_outcome' => ['repair_request_id' => $locked->leg->shipment->source_id,
                    'next_actor' => 'customer_and_assigned_repairer', 'next_action' => 'existing_repair_arrangement']];
            } elseif ($action === 'authorize_retry_handoff') {
                $movement = $this->currentMovementLeg($locked);
                abort_unless($actor->can('resolve-logistics-exceptions') && ($locked->resolution === 'retry' || $movement->id !== $locked->leg->id), 403);
                if (! $movement->scheduled_delivery_date) throw ValidationException::withMessages(['schedule' => 'Dispatch must schedule and assign the customer-authorized source movement first.']);
                $assignment = $movement->assignments()->latest('id')->firstOrFail();
                if ($assignment->status !== 'accepted') throw ValidationException::withMessages(['assignment' => 'The target Rider must explicitly accept this source offer before outbound handoff is staged.']);
                app(AssignmentService::class)->assertEligibleForDate($assignment->riderProfile, $locked->leg->shipment->shopOwner,
                    $movement->scheduled_delivery_date->toDateString(), $movement->id, checkCapacity: false);
                $transfer = $this->custody->request($movement, $actor, 'rider', $assignment->rider_profile_id, (string) \Illuminate\Support\Str::uuid());
                $outcome = ['outbound_transfer_id' => $transfer->id];
            } elseif ($action === 'ready_for_retry') {
                if (($inspection['quarantined'] ?? true) || $locked->resolution !== 'retry') {
                    throw ValidationException::withMessages(['inspection' => 'Verify safe goods before requesting Dispatcher retry release.']);
                }
                $outcome = ['readiness' => ['inspected_revision' => $inspection['revision'], 'actor_id' => $actor->id, 'ready_at' => now()->toISOString()]];
            }
            return [...$outcome, 'disposition' => ['action' => $action, 'notes' => $data['notes'], 'actor_id' => $actor->id,
                'recorded_at' => now()->toISOString(), 'next_actor' => $action === 'escalate' ? 'dispatcher_and_source_owner' : 'existing_source_workflow']];
        });
    }

    private function mutate(DeliveryIncident $incident, User $actor, string $stage, array $data, callable $operation): array
    {
        return DB::transaction(function () use ($incident, $actor, $stage, $data, $operation) {
            ShopOwner::whereKey($incident->shop_owner_id)->lockForUpdate()->firstOrFail();
            $locked = DeliveryIncident::with('leg.shipment.shopOwner')->lockForUpdate()->findOrFail($incident->id);
            abort_unless($locked->leg && $this->policy->decideParcelRecovery($actor, $locked->leg)['allowed'], 403);
            $this->policy->assertParcelRecoveryAttendance($actor);
            $facts = $data;
            unset($facts['evidence_path']);
            $facts = \Illuminate\Support\Arr::sortRecursive($facts);
            $hash = hash('sha256', json_encode([$stage, $actor->id, $facts], JSON_THROW_ON_ERROR));
            $old = $locked->leg->events()->where('metadata->incident_id', $locked->id)
                ->where('metadata->action_key', $data['request_key'])->lockForUpdate()->first();
            if ($old) {
                if (data_get($old->metadata, 'facts_hash') !== $hash) throw ValidationException::withMessages(['request_key' => 'This recovery request has different facts.']);
                return $old->metadata['result'];
            }
            $closingSafekeeping = ! data_get($locked->recovery_context, 'recovered_loss')
                && data_get($locked->recovery_context, 'safekeeping_return_leg_id') && in_array($stage, ['received', 'inspected'], true);
            $repairHandoff = $stage === 'dispositioned' && ($data['action'] ?? null) === 'authorize_retry_handoff'
                && $locked->resolution === 'return_required' && $locked->leg->shipment->source_type === 'repair_request'
                && $this->currentMovementLeg($locked)->id !== $locked->leg->id;
            $financialFollowup = $stage === 'dispositioned' && ($data['action'] ?? null) === 'refund_review' && $locked->resolution === 'return_required';
            if ((int) $locked->revision !== (int) $data['expected_revision'] || ! $locked->decided_at || ($locked->status === 'resolved' && $stage !== 'investigated' && ! $closingSafekeeping && ! $financialFollowup && ! $repairHandoff)) {
                throw ValidationException::withMessages(['expected_revision' => 'The recovery changed or is closed. Refresh before acting.']);
            }
            $changes = $operation($locked);
            $locked->forceFill(['recovery_context' => [...($locked->recovery_context ?? []), ...$changes], 'revision' => $locked->revision + 1])->save();
            $result = $this->project($locked->fresh('leg.shipment'), $actor);
            $this->events->record($locked->leg->shipment, $locked->leg, ['event_type' => 'parcel_recovery_'.$stage,
                'message' => 'Parcel recovery '.$stage.'.', 'created_by_type' => User::class, 'created_by_id' => $actor->id,
                'metadata' => ['incident_id' => $locked->id, 'revision' => $locked->revision, 'action_key' => $data['request_key'],
                    'operational_final' => $locked->status === 'resolved',
                    'facts_hash' => $hash, 'result' => $result, 'evidence_path' => $data['evidence_path'] ?? null]]);
            return $result;
        }, 3);
    }
}
