<?php

namespace App\Services\Logistics;

use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentCustodyTransfer;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\RepairRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShipmentCustodyService
{
    public function __construct(
        private LogisticsMovementEligibility $movements,
        private DeliveryEventService $events,
    ) {}

    public function request(ShipmentLeg $leg, User $actor, string $toType, ?int $toRiderId, string $key): ShipmentCustodyTransfer
    {
        return DB::transaction(function () use ($leg, $actor, $toType, $toRiderId, $key) {
            $shop = $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::with('shipment')->lockForUpdate()->findOrFail($leg->id);
            $this->authorizeShopActor($leg, $actor);
            $this->movements->assertInternalLeg($leg);
            if (! in_array($toType, ['rider', 'shop'], true) || ! Str::isUuid($key)
                || ($toType === 'shop' && $toRiderId !== null)) {
                throw ValidationException::withMessages(['custody' => 'Choose a valid receiving custodian and request identity.']);
            }
            $existing = ShipmentCustodyTransfer::where('request_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->shipment_leg_id !== $leg->id || $existing->to_type !== $toType
                    || (int) $existing->to_rider_profile_id !== (int) $toRiderId) {
                    throw ValidationException::withMessages(['request_key' => 'This request identity is already in use.']);
                }
                return $existing;
            }
            $batchHold = $leg->incidentStops()->where('held', true)->lockForUpdate()->first();
            if ($batchHold && ! $actor->can('resolve-logistics-exceptions')) throw new AuthorizationException('Batch recovery requires exception resolution authority.');
            if ($batchHold && $toType === 'rider' && ! app(BatchInterruptionService::class)->outboundRecoveryAuthorized($batchHold->incident)) {
                throw ValidationException::withMessages(['batch_interruption' => 'Ask dispatch to authorize the outbound recovery handoff.']);
            }
            if ((! in_array($leg->status->value, ['needs_resolution', 'assigned', 'picked_up'], true)
                    && (! $batchHold || ! in_array($leg->status->value, ['pickup_scheduled', 'in_transit', 'delivery_attempted'], true)))
                || ! in_array($leg->custody_state, ['rider', 'shop'], true) || ! $leg->custody_confirmed_at) {
                throw ValidationException::withMessages(['custody' => 'Confirm the current physical custodian before arranging a transfer.']);
            }
            if ($leg->custodyTransfers()->whereIn('status', ['requested', 'released'])->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['custody' => 'Complete or cancel the outstanding transfer first.']);
            }
            $assignment = null;
            if ($toType === 'rider') {
                $this->movements->assertCanStart($shop);
                $rider = RiderProfile::where('shop_owner_id', $shop->id)->where('active', true)
                    ->where('availability_status', '!=', 'inactive')->lockForUpdate()->find($toRiderId);
                $assignment = $leg->assignments()->where('rider_profile_id', $toRiderId)
                    ->whereIn('status', ['assigned', 'accepted'])->latest('id')->lockForUpdate()->first();
                if (! $rider || ! $assignment || ($leg->custody_state === 'rider' && (int) $leg->custody_rider_profile_id === $rider->id)) {
                    throw ValidationException::withMessages(['custody' => 'The recipient must be a different eligible assigned rider.']);
                }
            } elseif ($leg->custody_state === 'shop') {
                throw ValidationException::withMessages(['custody' => 'The parcel is already at the shop.']);
            } elseif (! $this->movements->canStart($shop) && ! $this->movements->canContinue($shop, $leg)) {
                $this->movements->assertCanStart($shop);
            }
            $transfer = $leg->custodyTransfers()->create([
                'shop_owner_id' => $shop->id, 'delivery_assignment_id' => $assignment?->id, 'request_key' => $key,
                'from_type' => $leg->custody_state, 'from_rider_profile_id' => $leg->custody_rider_profile_id,
                'to_type' => $toType, 'to_rider_profile_id' => $toRiderId, 'requested_by_user_id' => $actor->id,
            ]);
            $this->event($leg, $transfer, $actor, 'custody_transfer_requested');
            return $transfer;
        }, 3);
    }

    public function release(ShipmentCustodyTransfer $transfer, User $actor, string $evidencePath): ShipmentCustodyTransfer
    {
        return $this->confirm($transfer, $actor, $evidencePath, false);
    }

    public function cancel(ShipmentCustodyTransfer $transfer, User $actor, string $reason): ShipmentCustodyTransfer
    {
        return DB::transaction(function () use ($transfer, $actor, $reason) {
            $this->movements->lockShopForLeg($transfer->leg);
            $leg = ShipmentLeg::with('shipment')->lockForUpdate()->findOrFail($transfer->shipment_leg_id);
            $transfer = ShipmentCustodyTransfer::lockForUpdate()->findOrFail($transfer->id);
            $this->authorizeShopActor($leg, $actor);
            if (! $actor->can('resolve-logistics-exceptions')) throw new AuthorizationException('Exception resolution authority is required to cancel this retry handoff.');
            if ($transfer->status === 'cancelled') return $transfer;
            if (in_array($leg->status->value, ['delivered', 'cancelled'], true)) {
                throw ValidationException::withMessages(['custody' => 'This delivery is already closed. Its transfer history cannot reopen it.']);
            }
            if ($transfer->status !== 'requested' || ! filled($reason)) {
                throw ValidationException::withMessages(['custody' => 'Only an unreleased handoff can be canceled, with a reason.']);
            }
            $transfer->update(['status' => 'cancelled']);
            $leg->assignments()->whereIn('status', ['assigned', 'accepted'])->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            if (! $leg->incidentStops()->where('held', true)->exists()) {
                $leg->update(['status' => 'needs_resolution', 'resolution_type' => null, 'resolution_reason' => $reason]);
            }
            $this->events->record($leg->shipment, $leg, ['event_type' => 'custody_transfer_cancelled', 'message' => 'Unreleased handoff canceled.',
                'created_by_type' => User::class, 'created_by_id' => $actor->id, 'metadata' => ['custody_transfer_id' => $transfer->id, 'reason' => $reason]]);
            return $transfer;
        }, 3);
    }

    public function receive(ShipmentCustodyTransfer $transfer, User $actor, string $evidencePath): ShipmentCustodyTransfer
    {
        return $this->confirm($transfer, $actor, $evidencePath, true);
    }

    private function confirm(ShipmentCustodyTransfer $transfer, User $actor, string $path, bool $receipt): ShipmentCustodyTransfer
    {
        return DB::transaction(function () use ($transfer, $actor, $path, $receipt) {
            $shop = $this->movements->lockShopForLeg($transfer->leg);
            $leg = ShipmentLeg::with('shipment')->lockForUpdate()->findOrFail($transfer->shipment_leg_id);
            $transfer = ShipmentCustodyTransfer::lockForUpdate()->findOrFail($transfer->id);
            $type = $receipt ? $transfer->to_type : $transfer->from_type;
            $riderId = $receipt ? $transfer->to_rider_profile_id : $transfer->from_rider_profile_id;
            if ($type === 'shop') {
                $this->authorizeShopActor($leg, $actor);
            } else {
                $rider = RiderProfile::whereKey($riderId)->where('shop_owner_id', $shop->id)
                    ->where('linked_type', User::class)->where('linked_id', $actor->id)
                    ->where('active', true)->where('availability_status', '!=', 'inactive')->first();
                if (! $rider || (int) $actor->shop_owner_id !== (int) $shop->id || ! $actor->can('update-logistics-status')) {
                    throw new AuthorizationException('Only the actual participant may confirm this handoff.');
                }
            }
            if ($receipt && (int) $transfer->released_by_user_id === $actor->id) {
                throw new AuthorizationException('Release and receipt require different people.');
            }
            if ((! $receipt && in_array($transfer->status, ['released', 'received'], true))
                || ($receipt && $transfer->status === 'received')) {
                return $transfer;
            }
            if ($transfer->status !== ($receipt ? 'released' : 'requested')
                || $leg->custody_state !== $transfer->from_type
                || (int) $leg->custody_rider_profile_id !== (int) $transfer->from_rider_profile_id
                || in_array($leg->status->value, ['delivered', 'cancelled'], true)) {
                throw ValidationException::withMessages(['custody' => 'The handoff changed. Refresh before confirming it.']);
            }
            if ($transfer->to_type === 'rider'
                && ! $leg->assignments()->whereKey($transfer->delivery_assignment_id)
                    ->where('rider_profile_id', $transfer->to_rider_profile_id)->where('status', 'accepted')->exists()) {
                throw ValidationException::withMessages(['custody' => 'Accept the current delivery offer before receiving its parcel.']);
            }
            if (! $receipt && $transfer->to_type === 'rider') {
                $this->movements->assertCanStart($shop);
                $hold = $leg->incidentStops()->where('held', true)->with('incident')->first();
                if ($hold && ! app(BatchInterruptionService::class)->outboundRecoveryAuthorized($hold->incident)) {
                    throw ValidationException::withMessages(['batch_interruption' => 'Dispatcher reauthorization is required before outbound release.']);
                }
            } elseif (! $receipt && ! $this->movements->canStart($shop) && ! $this->movements->canContinue($shop, $leg)) {
                $this->movements->assertCanStart($shop);
            }
            if (! str_starts_with($path, 'logistics-proof/') || str_contains($path, '..') || str_contains($path, '\\')
                || ! Storage::disk('local')->exists($path)) {
                throw ValidationException::withMessages(['photo' => 'Private handoff evidence is required.']);
            }
            $proof = $leg->proofs()->create([
                'shop_owner_id' => $shop->id, 'proof_number' => (int) HandoffProof::where('shop_owner_id', $shop->id)->max('proof_number') + 1,
                'delivery_assignment_id' => $transfer->delivery_assignment_id,
                'handoff_type' => $receipt ? 'custody_receipt' : 'custody_release', 'proof_type' => 'photo',
                'file_path' => $path, 'confirmed_by_type' => User::class, 'confirmed_by_id' => $actor->id,
                'review_status' => 'approved', 'reviewed_by_type' => User::class, 'reviewed_by_id' => $actor->id,
                'reviewed_at' => now(), 'recorded_at' => now(), 'metadata' => ['custody_transfer_id' => $transfer->id],
            ]);
            $transfer->update($receipt
                ? ['status' => 'received', 'received_by_user_id' => $actor->id, 'received_at' => now(), 'receipt_proof_id' => $proof->id]
                : ['status' => 'released', 'released_by_user_id' => $actor->id, 'released_at' => now(), 'release_proof_id' => $proof->id]);
            if ($receipt) {
                $leg->update(['custody_state' => $transfer->to_type, 'custody_rider_profile_id' => $transfer->to_rider_profile_id, 'custody_confirmed_at' => now()]);
            }
            $this->event($leg, $transfer, $actor, $receipt ? 'custody_transfer_received' : 'custody_transfer_released');
            // Receipt closes existing custody; an OFF module must not roll it back to arrange new movement.
            $hold = $leg->incidentStops()->where('held', true)->with('incident')->first();
            $allowOutbound = ! $hold || app(BatchInterruptionService::class)->outboundRecoveryAuthorized($hold->incident);
            if ($receipt && $transfer->to_type === 'shop' && $allowOutbound && $this->movements->canStart($shop, lockState: true)) {
                $next = $leg->assignments()->whereIn('status', ['assigned', 'accepted'])->latest('id')->first();
                if ($next) {
                    $this->request($leg, $actor, 'rider', (int) $next->rider_profile_id, (string) Str::uuid());
                }
            }
            return $transfer->fresh();
        }, 3);
    }

    public function assertRiderHasCustody(ShipmentLeg $leg, RiderProfile $rider): void
    {
        if ($leg->custody_state !== 'rider' || (int) $leg->custody_rider_profile_id !== $rider->id
            || ! $leg->custody_confirmed_at
            || $leg->custodyTransfers()->whereIn('status', ['requested', 'released'])->lockForUpdate()->first()) {
            throw ValidationException::withMessages(['custody' => 'Confirm the parcel handoff before starting this delivery.']);
        }
    }

    public function assertCanContinue(ShipmentLeg $leg, RiderProfile $rider): void
    {
        // Legacy in-flight work may close under existing proof rules; it never becomes confirmed custody by inference.
        if ($leg->custody_state !== 'unknown' || $leg->custodyTransfers()->whereIn('status', ['requested', 'released'])->lockForUpdate()->first()) {
            $this->assertRiderHasCustody($leg, $rider);
        }
    }

    public function pendingFor(User $actor): array
    {
        $dispatcher = $actor->can('resolve-logistics-exceptions') || $actor->can('assign-logistics-deliveries');
        $isParticipant = fn ($query) => $query->where('linked_type', User::class)->where('linked_id', $actor->id);
        $transfers = ShipmentCustodyTransfer::with(['leg.shipment', 'fromRider', 'toRider'])
            ->where('shop_owner_id', $actor->shop_owner_id)->whereIn('status', ['requested', 'released'])
            ->when(! $dispatcher, fn ($query) => $query->where(fn ($participants) => $participants
                ->whereHas('fromRider', $isParticipant)->orWhereHas('toRider', $isParticipant)))
            ->orderBy('id')->get();
        if ($transfers->isEmpty()) {
            return [];
        }

        $shipments = $transfers->pluck('leg.shipment');
        $orders = Order::where('shop_owner_id', $actor->shop_owner_id)
            ->whereIn('id', $shipments->where('source_type', 'order')->pluck('source_id'))->get(['id', 'order_number'])->keyBy('id');
        $returns = OrderRefund::with(['order' => fn ($query) => $query->where('shop_owner_id', $actor->shop_owner_id)->select('id', 'order_number')])->where('shop_owner_id', $actor->shop_owner_id)
            ->whereIn('id', $shipments->where('source_type', 'order_refund')->pluck('source_id'))->get()->keyBy('id');
        $repairs = RepairRequest::where('shop_owner_id', $actor->shop_owner_id)
            ->whereIn('id', $shipments->where('source_type', 'repair_request')->pluck('source_id'))->get(['id', 'request_id'])->keyBy('id');
        return $transfers->map(function ($transfer) use ($actor, $dispatcher, $orders, $returns, $repairs) {
                $shipment = $transfer->leg->shipment;
                $source = match ($shipment->source_type) {
                    'order' => 'Order '.($orders->get($shipment->source_id)?->order_number ?? $shipment->source_id),
                    'order_refund' => 'Return #'.$shipment->source_id.' / Order '.($returns->get($shipment->source_id)?->order?->order_number ?? 'unavailable'),
                    'repair_request' => 'Repair '.($repairs->get($shipment->source_id)?->request_id ?? $shipment->source_id),
                    default => 'Shipment #'.($shipment->shipment_number ?? $shipment->id),
                };
                $riderMayConfirm = $actor->can('update-logistics-status');
                $from = $transfer->from_type === 'shop' ? $dispatcher
                    : ($riderMayConfirm && $transfer->fromRider?->active && $transfer->fromRider?->linked_type === User::class && (int) $transfer->fromRider?->linked_id === $actor->id);
                $to = $transfer->to_type === 'shop' ? $dispatcher
                    : ($riderMayConfirm && $transfer->toRider?->active && $transfer->toRider?->linked_type === User::class && (int) $transfer->toRider?->linked_id === $actor->id);
                return [
                    'id' => $transfer->id, 'shipment_leg_id' => $transfer->shipment_leg_id,
                    'delivery_reference' => 'Delivery #'.($transfer->leg->delivery_number ?? $transfer->leg->id),
                    'source_reference' => $source,
                    'shipment_reference' => 'Shipment #'.($shipment->shipment_number ?? $shipment->id),
                    'stage' => $transfer->to_type === 'shop' ? 'Return to shop for safekeeping' : ($transfer->from_type === 'shop' ? 'Shop release to rider' : 'Direct rider transfer'),
                    'details_url' => $dispatcher ? '/erp/logistics/shipments?search='.($shipment->shipment_number ?? $shipment->id)
                        : '/erp/logistics/deliveries?tab=all&search='.$transfer->shipment_leg_id,
                    'status' => $transfer->status,
                    'from_label' => $transfer->from_type === 'shop' ? 'Shop' : ($transfer->fromRider?->name ?? 'Rider'),
                    'to_label' => $transfer->to_type === 'shop' ? 'Shop' : ($transfer->toRider?->name ?? 'Rider'),
                    'can_release' => $transfer->status === 'requested' && (bool) $from,
                    'can_receive' => $transfer->status === 'released' && (bool) $to && (int) $transfer->released_by_user_id !== $actor->id,
                    'can_cancel' => $actor->can('resolve-logistics-exceptions') && $transfer->status === 'requested',
                ];
            })->all();
    }

    private function authorizeShopActor(ShipmentLeg $leg, User $actor): void
    {
        if ((int) $actor->shop_owner_id !== (int) $leg->shipment->shop_owner_id
            || (! $actor->can('resolve-logistics-exceptions') && ! $actor->can('assign-logistics-deliveries'))) {
            throw new AuthorizationException('A dispatcher in this shop must arrange or confirm shop custody.');
        }
    }

    private function event(ShipmentLeg $leg, ShipmentCustodyTransfer $transfer, User $actor, string $type): void
    {
        $this->events->record($leg->shipment, $leg, [
            'event_type' => $type, 'visibility' => 'customer', 'message' => 'Parcel handoff '.str_replace('custody_transfer_', '', $type).'.',
            'created_by_type' => User::class, 'created_by_id' => $actor->id,
            'metadata' => ['custody_transfer_id' => $transfer->id, 'from_rider_profile_id' => $transfer->from_rider_profile_id, 'rider_profile_id' => $transfer->to_rider_profile_id],
        ]);
    }
}
