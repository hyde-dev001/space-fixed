<?php

namespace App\Services\Logistics;

use App\Enums\Logistics\RiderProgressState;
use App\Enums\OrderStatus;
use App\Models\CodCollection;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Services\CodCollectionService;
use App\Services\NotificationService;
use App\Services\OrderRefundService;
use App\Services\RepairDeliveryService;
use App\Services\RepairPosRefundService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShipmentLegService
{
    public const PHOTO_REQUIRED_REASONS = [
        'recipient_unavailable',
        'wrong_or_incomplete_address',
        'recipient_refused',
        'item_damaged',
    ];

    public const NOTES_REQUIRED_REASONS = [
        'unsafe_location',
        'vehicle_or_delivery_problem',
        'other',
    ];

    public const PICKUP_REASONS = [
        'customer_unavailable',
        'customer_requested_reschedule',
        'customer_refused_pickup',
        'item_not_ready',
        'wrong_address_or_pin',
        'unsafe_or_inaccessible_location',
        'vehicle_or_rider_problem',
        'other',
    ];

    private const CUSTOMER_CAUSED_PICKUP_REASONS = [
        'customer_unavailable',
        'customer_requested_reschedule',
        'customer_refused_pickup',
        'item_not_ready',
        'wrong_address_or_pin',
    ];

    private const OPERATIONS_CAUSED_PICKUP_REASONS = [
        'vehicle_or_rider_problem',
    ];

    public function __construct(
        private ProofService $proofs,
        private DeliveryEventService $events,
        private OrderRefundService $refunds,
        private RepairPosRefundService $repairRefunds,
        private RiderActiveWorkGuard $activeWork,
        private NotificationService $notifications,
        private RepairDeliveryService $repairDelivery,
        private ShipmentCustodyService $custody,
        private AssignmentService $assignments,
        private DeliveryStartEligibility $starts,
        private LogisticsMovementEligibility $movements,
        private ArrivalService $arrivals,
        private CodCollectionService $codCollections,
    ) {}

    public function markPickedUp(ShipmentLeg $leg, ?RiderProfile $rider = null): ShipmentLeg
    {
        return DB::transaction(function () use ($leg, $rider) {
            $shop = $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::query()
                ->with(['shipment', 'deliveryBatch'])
                ->lockForUpdate()
                ->findOrFail($leg->id);
            $this->activeWork->assertNotHeld($leg);

            if ($leg->status->value === 'picked_up') {
                if ($rider && ! $leg->assignments()->where('rider_profile_id', $rider->id)->whereIn('status', ['assigned', 'accepted'])->exists()) {
                    throw ValidationException::withMessages(['rider' => 'This delivery is not assigned to this rider.']);
                }

                return $leg;
            }

            $this->movements->assertInternalLeg($leg);
            $this->starts->assertCanStart($leg, $rider);
            if (! $this->movements->canContinue($shop, $leg)) {
                $this->movements->assertCanStart($shop);
            }
            $this->assertTransitionAllowed($leg, ['assigned', 'pickup_scheduled', 'delivery_attempted'], 'picked up');
            if ($leg->leg_type === 'outbound') $this->custody->assertRecoveryReady($leg);

            if ($leg->shipment->source_type === 'order_refund' && $leg->shipment->purpose === 'refund_return') {
                $refund = OrderRefund::query()->where('shop_owner_id', $leg->shipment->shop_owner_id)->find($leg->shipment->source_id);
                if (! $refund || $refund->shop_owner_status !== 'approved' || $refund->finance_status !== 'approved') {
                    throw ValidationException::withMessages([
                        'refund' => 'Finance and Staff approvals are required before return pickup.',
                    ]);
                }
            }

            if ($leg->delivery_batch_id && $leg->deliveryBatch?->status !== 'in_progress') {
                throw ValidationException::withMessages(['status' => 'This stop can only be picked up from an in-progress batch.']);
            }

            if (! $this->proofs->hasRequiredPickupProof($leg)) {
                throw ValidationException::withMessages(['proof' => 'Pickup proof is required before marking this leg picked up.']);
            }
            if ($rider && ($leg->picked_up_at || $leg->custody_state === 'shop'
                || $leg->custodyTransfers()->whereIn('status', ['requested', 'released'])->exists())) {
                $this->custody->assertRiderHasCustody($leg, $rider);
            }

            if (! $leg->delivery_batch_id) {
                if (! $rider || ! $leg->assignments()->where('rider_profile_id', $rider->id)->where('status', 'accepted')->exists()) {
                    throw ValidationException::withMessages(['rider' => 'Accept this delivery offer before starting it.']);
                }
                $this->activeWork->assertCanStartStandalone($rider, $leg);
            }
            if ($rider) {
                $this->activeWork->assertCanAdvanceLeg($rider, $leg);
                $assignment = $leg->assignments()
                    ->where('rider_profile_id', $rider->id)
                    ->whereIn('status', ['assigned', 'accepted'])
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();
                if (! $assignment) {
                    throw ValidationException::withMessages([
                        'rider' => 'This delivery is no longer assigned to this rider.',
                    ]);
                }
            }

            return $this->transition($leg, 'picked_up', [
                'picked_up_at' => now(),
                ...($rider ? ['custody_state' => 'rider', 'custody_rider_profile_id' => $rider->id, 'custody_confirmed_at' => now()] : []),
            ], 'picked_up', 'Shipment leg picked up.');
        }, 3);
    }

    public function confirmPickup(ShipmentLeg $leg, HandoffProof $proof, RiderProfile $rider): ShipmentLeg
    {
        return DB::transaction(function () use ($leg, $proof, $rider) {
            $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::query()->lockForUpdate()->findOrFail($leg->id);
            $this->activeWork->assertNotHeld($leg);
            $proof = HandoffProof::query()->lockForUpdate()->findOrFail($proof->id);
            if ($proof->shipment_leg_id !== $leg->id || $proof->handoff_type !== 'pickup'
                || ! $leg->assignments()->whereKey($proof->delivery_assignment_id)->where('rider_profile_id', $rider->id)->where('status', 'accepted')->exists()) {
                throw ValidationException::withMessages(['proof' => 'Accept this delivery offer before confirming pickup.']);
            }
            if ($leg->status->value === 'picked_up' && $proof->review_status === 'approved') {
                return $leg;
            }
            if ($proof->review_status !== 'pending') {
                throw ValidationException::withMessages([
                    'proof' => 'Only a pending pickup proof can be confirmed.',
                ]);
            }
            $this->activeWork->assertCanAdvanceLeg($rider, $leg);
            $proof->update(['review_status' => 'approved', 'reviewed_by_type' => RiderProfile::class, 'reviewed_by_id' => $rider->id, 'reviewed_at' => now()]);

            return $this->markPickedUp($leg, $rider);
        }, 3);
    }

    public function rejectPickup(ShipmentLeg $leg, HandoffProof $proof, RiderProfile $rider, string $reason): ShipmentLeg
    {
        if (! filled($reason)) {
            throw ValidationException::withMessages(['reason' => 'Rejection reason is required.']);
        }

        return DB::transaction(function () use ($leg, $proof, $rider, $reason) {
            $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::query()->lockForUpdate()->findOrFail($leg->id);
            $proof = HandoffProof::query()->lockForUpdate()->findOrFail($proof->id);
            if (! $leg->assignments()->where('rider_profile_id', $rider->id)->whereIn('status', ['assigned', 'accepted'])->exists()) {
                abort(403);
            }
            if ($proof->shipment_leg_id !== $leg->id || $proof->handoff_type !== 'pickup') {
                throw ValidationException::withMessages([
                    'proof' => 'This pickup proof does not belong to this delivery.',
                ]);
            }
            if ($proof->review_status !== 'pending') {
                throw ValidationException::withMessages([
                    'proof' => 'Only a pending pickup proof can be rejected.',
                ]);
            }
            $proof->update(['review_status' => 'rejected', 'rejection_reason' => $reason, 'reviewed_by_type' => RiderProfile::class, 'reviewed_by_id' => $rider->id, 'reviewed_at' => now()]);

            return $leg;
        }, 3);
    }

    public function markOutForDelivery(ShipmentLeg $leg, RiderProfile $rider): ShipmentLeg
    {
        return DB::transaction(function () use ($leg, $rider) {
            $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::query()->with(['shipment', 'deliveryBatch'])->lockForUpdate()->findOrFail($leg->id);
            $this->activeWork->assertNotHeld($leg);
            if ($leg->status->value === 'in_transit' && $this->starts->hasActivated($leg)) {
                return $leg;
            }
            $this->movements->assertCanStart($leg->shipment->shopOwner);
            if ($leg->leg_type === 'outbound') $this->custody->assertRecoveryReady($leg);
            $this->starts->assertCanStart($leg, $rider);
            $this->activeWork->assertCanAdvanceLeg($rider, $leg);
            $this->custody->assertRiderHasCustody($leg, $rider);
            if (! in_array($leg->status->value, ['picked_up', 'in_transit'], true) || $leg->deliveryBatch?->status !== 'in_progress'
                || ! $leg->assignments()->where('rider_profile_id', $rider->id)->where('status', 'accepted')->exists()) {
                throw ValidationException::withMessages(['status' => 'This stop cannot start delivery.']);
            }

            if ($leg->status->value === 'in_transit') {
                $leg->update(['out_for_delivery_at' => now()]);
                $this->events->record($leg->shipment, $leg, ['event_type' => 'out_for_delivery', 'visibility' => 'customer',
                    'message' => 'Your delivery is out for delivery under the current assignment.',
                    'metadata' => ['delivery_assignment_id' => $leg->assignments()->latest('id')->value('id'),
                        'delivery_batch_id' => $leg->delivery_batch_id, 'stop_sequence' => $leg->stop_sequence,
                        'schedule_facts' => $this->starts->scheduleFacts($leg)]]);
                return $leg->fresh();
            }

            return $this->transition($leg, 'in_transit', ['out_for_delivery_at' => now()], 'out_for_delivery', 'Your delivery is out for delivery.');
        }, 3);
    }

    public function markInTransit(ShipmentLeg $leg, ?RiderProfile $rider = null): ShipmentLeg
    {
        return DB::transaction(function () use ($leg, $rider) {
            $shop = $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::query()
                ->with('shipment.shopOwner')
                ->lockForUpdate()
                ->findOrFail($leg->id);
            $this->activeWork->assertNotHeld($leg);
            $leg->shipment->setRelation('shopOwner', $shop);
            if ($leg->status->value === 'in_transit' && $this->starts->hasActivated($leg)) {
                return $leg;
            }

            if ($rider) {
                $current = $leg->assignments()->latest('id')->lockForUpdate()->first();
                if (! $current || $current->status !== 'accepted' || (int) $current->rider_profile_id !== (int) $rider->id) {
                    throw ValidationException::withMessages(['assignment' => 'Explicitly accept the current delivery offer before starting.']);
                }
            }
            $this->starts->assertCanStart($leg, $rider);
            if ($leg->status->value === 'needs_resolution' && $leg->resolution_type === 'retry') {
                if ($leg->leg_type === 'outbound') $this->custody->assertRecoveryReady($leg);
                $this->movements->assertCanStart($leg->shipment->shopOwner);
                if (! $rider) {
                    throw ValidationException::withMessages([
                        'rider' => 'A rider must start a scheduled delivery retry.',
                    ]);
                }

                $assignment = $leg->assignments()
                    ->whereIn('status', ['assigned', 'accepted'])
                    ->lockForUpdate()
                    ->first();
                if (! $assignment) {
                    throw ValidationException::withMessages([
                        'custody' => 'An active rider assignment is required to start this retry.',
                    ]);
                }
                if ($rider && ((int) $assignment->rider_profile_id !== (int) $rider->id || $assignment->status !== 'accepted')) {
                    abort(403);
                }
                if ($rider) {
                    $this->activeWork->assertCanAdvanceLeg($rider, $leg);
                    $this->custody->assertRiderHasCustody($leg, $rider);
                }

                return $this->transition($leg, 'in_transit', [], 'in_transit', 'Shipment leg retry is in transit.');
            }

            if ($rider) {
                if ($leg->leg_type === 'outbound') $this->custody->assertRecoveryReady($leg);
                $this->activeWork->assertCanAdvanceLeg($rider, $leg);
                $this->custody->assertCanContinue($leg, $rider);
            }
            if ($leg->status->value === 'in_transit') {
                $this->movements->assertCanStart($shop);
                if (! $rider) throw ValidationException::withMessages(['rider' => 'The accepted current rider must activate this delivery.']);
                $this->custody->assertRiderHasCustody($leg, $rider);
                $this->events->record($leg->shipment, $leg, ['event_type' => 'in_transit', 'visibility' => 'customer', 'message' => 'Current delivery assignment started.',
                    'metadata' => ['delivery_assignment_id' => $leg->assignments()->latest('id')->value('id'), 'schedule_facts' => $this->starts->scheduleFacts($leg)]]);
                return $leg;
            }
            $this->assertTransitionAllowed($leg, ['picked_up'], 'in transit');

            return $this->transition($leg, 'in_transit', [], 'in_transit', 'Shipment leg is in transit.');
        }, 3);
    }

    public function updateThirdParty(
        ShipmentLeg $leg,
        ShopOwner $shop,
        string $action,
        array $data,
    ): ShipmentLeg {
        return DB::transaction(function () use ($leg, $shop, $action, $data): ShipmentLeg {
            $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::query()
                ->with('shipment')
                ->lockForUpdate()
                ->findOrFail($leg->id);
            $shipment = $leg->shipment;

            abort_unless(
                $shipment
                && (int) $shipment->shop_owner_id === (int) $shop->id
                && $shipment->source_type === 'order'
                && $shipment->purpose === 'retail_delivery',
                403,
            );

            $order = Order::query()
                ->whereKey($shipment->source_id)
                ->where('shop_owner_id', $shop->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($order->resolvedDeliveryMethod() === 'third_party', 403);

            $orderFields = ['carrier_company', 'carrier_name', 'carrier_phone', 'tracking_number', 'tracking_link'];
            $orderChanges = [];
            foreach ($orderFields as $field) {
                if (array_key_exists($field, $data) && filled($data[$field])) {
                    $orderChanges[$field] = $data[$field];
                }
            }
            if ($orderChanges !== []) {
                $order->forceFill($orderChanges + ['delivery_method' => 'third_party'])->save();
            }

            if ($action === 'update') {
                if ($orderChanges === [] && ! array_key_exists('provider_status', $data)) {
                    throw ValidationException::withMessages([
                        'tracking_number' => ['Provide courier or tracking details to update this delivery.'],
                    ]);
                }
                if ($leg->status->value === 'delivered' || $leg->status->value === 'cancelled') {
                    throw ValidationException::withMessages([
                        'status' => ['A completed or cancelled third-party delivery cannot be updated.'],
                    ]);
                }

                $leg->update([
                    'tracking_number' => $order->tracking_number,
                    'tracking_url' => $order->tracking_link,
                    'provider_status' => $data['provider_status'] ?? $leg->provider_status ?? 'handoff_pending',
                    'requires_delivery_proof' => false,
                ]);
                $this->events->record($shipment, $leg, [
                    'event_type' => 'third_party_tracking_updated',
                    'visibility' => 'customer',
                    'message' => 'Third-party courier tracking details updated.',
                ]);

                return $leg->fresh();
            }

            $status = $leg->status->value;
            if ($action === 'in_transit') {
                if ($status === 'in_transit') {
                    return $leg->fresh();
                }
                $this->assertTransitionAllowed($leg, ['pending'], 'in transit');
                $leg->update([
                    'status' => 'in_transit',
                    'picked_up_at' => $leg->picked_up_at ?? now(),
                    'provider_status' => $data['provider_status'] ?? 'in_transit',
                    'requires_delivery_proof' => false,
                    'rider_progress_state' => RiderProgressState::RIDER_RELEASED,
                ]);
                $this->syncShipmentStatus($leg);
                $this->events->record($shipment, $leg, [
                    'event_type' => 'in_transit',
                    'visibility' => 'customer',
                    'message' => 'Third-party courier has the shipment.',
                ]);

                return $leg->fresh();
            }

            if ($status === 'delivered') {
                return $leg->fresh();
            }
            $this->codCollections->assertCollectedBeforeDelivery($order);
            $this->assertTransitionAllowed($leg, ['in_transit'], 'delivered');
            $leg->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                'provider_status' => $data['provider_status'] ?? 'delivered',
                'requires_delivery_proof' => false,
                'rider_progress_state' => RiderProgressState::RIDER_RELEASED,
            ]);
            $this->syncShipmentStatus($leg);
            $this->events->record($shipment, $leg, [
                'event_type' => 'delivered',
                'visibility' => 'customer',
                'message' => 'Third-party courier marked the shipment delivered.',
            ]);
            $this->markOrderDeliveredIfShipped($order);

            return $leg->fresh();
        }, 3);
    }

    public function markDelivered(ShipmentLeg $leg, ?RiderProfile $rider = null): ShipmentLeg
    {
        return DB::transaction(function () use ($leg, $rider) {
            $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            $this->activeWork->assertNotHeld($leg);
            if ($leg->status->value === 'delivered') {
                if ($leg->rider_progress_state !== RiderProgressState::RIDER_RELEASED) {
                    $leg->update(['rider_progress_state' => RiderProgressState::RIDER_RELEASED]);
                }

                return $leg;
            }
            if ($rider) {
                $this->activeWork->assertCanAdvanceLeg($rider, $leg);
            }
            $awaitingProofApproval = $leg->status->value === 'awaiting_proof_approval';
            $this->assertTransitionAllowed(
                $leg,
                $leg->requires_delivery_proof || $awaitingProofApproval
                    ? ['awaiting_proof_approval']
                    : ['in_transit', 'delivery_attempted'],
                'delivered'
            );

            if (($leg->requires_delivery_proof || $awaitingProofApproval)
                && ! $this->proofs->hasApprovedDeliveryProof($leg)) {
                throw ValidationException::withMessages(['proof' => 'Delivery proof is required before marking this leg delivered.']);
            }

            if ($leg->shipment?->source_type === 'order' && $leg->shipment->purpose === 'retail_delivery') {
                $order = Order::query()->where('shop_owner_id', $leg->shipment->shop_owner_id)->find($leg->shipment->source_id);
                if ($order) {
                    $this->codCollections->assertCollectedBeforeDelivery($order);
                }
            }

            $delivered = $this->transition($leg, 'delivered', [
                'delivered_at' => now(),
                'custody_state' => ($leg->destination_snapshot['type'] ?? null) === 'shop' ? 'shop' : 'customer',
                'custody_rider_profile_id' => null,
                'custody_confirmed_at' => now(),
                'rider_progress_state' => RiderProgressState::RIDER_RELEASED,
            ], 'delivered', 'Shipment leg delivered.');
            $this->reconcileBatchState($delivered->delivery_batch_id);

            return $delivered;
        }, 3);
    }

    public function cancel(ShipmentLeg $leg, string $customerReason): ShipmentLeg
    {
        return DB::transaction(function () use ($leg, $customerReason) {
            $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::with('shipment')->lockForUpdate()->findOrFail($leg->id);
            $this->activeWork->assertNotHeld($leg);
            $this->assertTransitionAllowed($leg, ['delivery_attempted', 'needs_resolution'], 'cancelled');
            if ($leg->picked_up_at && ! in_array($leg->resolution_type, ['returned', 'loss_confirmed'], true)) {
                throw ValidationException::withMessages(['custody' => 'Post-pickup cancellation requires a confirmed return or loss resolution.']);
            }
            $leg->assignments()
                ->whereIn('status', ['assigned', 'accepted'])
                ->lockForUpdate()
                ->get()
                ->each(fn (DeliveryAssignment $assignment) => $assignment->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]));
            $leg->update([
                'status' => 'cancelled',
                'rider_progress_state' => RiderProgressState::RIDER_RELEASED,
            ]);
            $this->syncShipmentStatus($leg);
            $this->reconcileBatchState($leg->delivery_batch_id);

            $this->events->record($leg->shipment, $leg, [
                'event_type' => 'delivery_cancelled',
                'message' => 'Dispatcher cancelled the delivery.',
            ]);
            $this->events->record($leg->shipment, $leg, [
                'event_type' => 'delivery_cancelled',
                'visibility' => 'customer',
                'message' => "Delivery cancelled: {$customerReason}.",
            ]);

            return $leg->fresh();
        }, 3);
    }

    public function confirmLoss(ShipmentLeg $leg, string $reason): ShipmentLeg
    {
        if (! filled($reason)) {
            throw ValidationException::withMessages(['reason' => 'Loss investigation reason is required.']);
        }

        return DB::transaction(function () use ($leg, $reason) {
            $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            if ($leg->status->value === 'cancelled' && $leg->resolution_type === 'loss_confirmed') {
                return $leg;
            }
            if ($leg->status->value === 'delivered') {
                throw ValidationException::withMessages(['status' => 'A delivered leg cannot be confirmed as lost.']);
            }
            if (ShipmentLeg::query()->where('return_for_leg_id', $leg->id)->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'A return workflow already exists for this delivery.',
                ]);
            }

            $batchId = $leg->delivery_batch_id;
            $recovery = null;
            if ($leg->shipment->source_type === 'order') {
                $order = Order::query()->where('shop_owner_id', $leg->shipment->shop_owner_id)->find($leg->shipment->source_id);
                if ($order) {
                    $recovery = $this->refunds->reserveConfirmedLossRefund($order, $leg, $reason);

                    if ($this->codCollections->isCodOrder($order)
                        && in_array($recovery['result'] ?? null, ['reserved', 'recovered'], true)) {
                        CodCollection::query()
                            ->where('order_id', $order->id)
                            ->where('status', CodCollection::STATUS_CASH_COLLECTED)
                            ->lockForUpdate()
                            ->get()
                            ->each(fn (CodCollection $collection) => $collection->update([
                                'status' => CodCollection::STATUS_REFUND_PENDING,
                            ]));
                    }

                    if (!$this->codCollections->isCodOrder($order)
                        && ($recovery['result'] ?? null) === 'reserved') {
                        $refundId = (int) data_get($recovery, 'refund.id');
                        if ($refundId > 0) {
                            DB::afterCommit(function () use ($refundId): void {
                                try {
                                    $refund = OrderRefund::query()->find($refundId);
                                    if ($refund) {
                                        $this->refunds->notifyRefundApprovalRequested($refund);
                                    }
                                } catch (\Throwable $exception) {
                                    Log::warning('Loss refund notification failed after commit.', [
                                        'refund_id' => $refundId,
                                        'error' => $exception->getMessage(),
                                    ]);
                                }
                            }, 3);
                        }
                    }
                }
            }

            $leg->assignments()
                ->whereIn('status', ['assigned', 'accepted'])
                ->lockForUpdate()
                ->get()
                ->each(fn (DeliveryAssignment $assignment) => $assignment->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]));
            $leg->update([
                'status' => 'cancelled',
                'rider_progress_state' => RiderProgressState::RIDER_RELEASED,
                'failed_at' => $leg->failed_at ?? now(),
                'delivery_batch_id' => null,
                'stop_sequence' => null,
                'resolution_type' => 'loss_confirmed',
                'custody_state' => 'lost', 'custody_rider_profile_id' => null, 'custody_confirmed_at' => now(),
                'resolution_reason' => $reason,
            ]);
            $leg->custodyTransfers()->whereIn('status', ['requested', 'released'])->update(['status' => 'closed_loss']);
            $this->syncShipmentStatus($leg);
            $this->reconcileBatchState($batchId, 'Parcel loss was confirmed.');

            $metadata = [
                'resolution_reason' => $reason,
                'refund_result' => $recovery['result'] ?? 'not_required',
                'refund_id' => data_get($recovery, 'refund.id'),
            ];
            $this->events->record($leg->shipment, $leg, [
                'event_type' => 'loss_confirmed',
                'visibility' => 'internal',
                'message' => 'Parcel loss was confirmed after investigation.',
                'metadata' => $metadata,
            ]);
            $this->events->record($leg->shipment, $leg, [
                'event_type' => 'loss_confirmed',
                'visibility' => 'customer',
                'message' => 'We confirmed that the parcel was lost. Your refund or claim is being reviewed.',
            ]);

            $result = $leg->fresh();
            $result->setAttribute('loss_recovery', $recovery);

            return $result;
        }, 3);
    }

    public function resolveRetry(ShipmentLeg $leg, string $reason, array $options = [], ?Authenticatable $actor = null): ShipmentLeg
    {
        if (! filled($reason)) {
            throw ValidationException::withMessages(['reason' => 'Resolution reason is required.']);
        }
        if ($actor instanceof \App\Models\User && empty($options)) {
            throw ValidationException::withMessages(['delivery_date' => 'Select the retry date, window and rider.']);
        }
        return DB::transaction(function () use ($leg, $reason, $options, $actor) {
            $shop = $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::with('shipment')->lockForUpdate()->findOrFail($leg->id);
            $this->activeWork->assertNotHeld($leg);
            $this->movements->assertCanStart($shop);
            $this->movements->assertInternalLeg($leg);
            $actor ??= $shop;
            if ($actor instanceof \App\Models\User && (int) $actor->shop_owner_id !== (int) $shop->id) {
                abort(403);
            }
            $previous = $leg->assignments()->latest('id')->lockForUpdate()->first();
            $key = $options['request_key'] ?? null;
            if ($key && ($replay = DeliveryAssignment::where('recovery_key', $key)->lockForUpdate()->first())) {
                $event = $leg->events()->where('event_type', 'leg_assigned')->where('metadata->delivery_assignment_id', $replay->id)->lockForUpdate()->first();
                $facts = $event?->metadata ?? [];
                $confirmed = (bool) ($options['appointment_confirmed'] ?? false);
                if (! $event || (bool) ($facts['appointment_confirmed'] ?? false) !== $confirmed
                    || ($confirmed && ($facts['appointment_agreement_note'] ?? null) !== ($options['appointment_agreement_note'] ?? null))
                    || ($facts['handoff_route'] ?? 'direct') !== ($options['handoff_route'] ?? 'direct')
                    || ($facts['capacity_override_reason'] ?? null) !== ($options['capacity_override_reason'] ?? null)
                    || ($facts['immediate_recovery'] ?? null) !== $this->starts->immediateRecovery($shop, $options['delivery_date'] ?? '', $options['delivery_window'] ?? '', $options, false)
                    || (isset($options['expected_assignment_id']) && (int) ($facts['previous_assignment_id'] ?? 0) !== (int) $options['expected_assignment_id'])
                    || $replay->shipment_leg_id !== $leg->id || $previous?->id !== $replay->id
                    || (int) $replay->rider_profile_id !== (int) ($options['rider_profile_id'] ?? 0)
                    || $leg->scheduled_delivery_date?->toDateString() !== ($options['delivery_date'] ?? null)
                    || $leg->delivery_window !== ($options['delivery_window'] ?? null) || $leg->resolution_reason !== $reason
                    || $leg->resolution_type !== 'retry') {
                    throw ValidationException::withMessages(['request_key' => 'The retry request changed or is stale. Refresh the delivery.']);
                }
                return $leg;
            }
            $this->assertTransitionAllowed($leg, ['needs_resolution'], 'scheduled for retry');
            if (($options['handoff_route'] ?? 'direct') === 'via_shop'
                && ($leg->leg_type === 'inbound' || data_get($leg->destination_snapshot, 'type') === 'shop'
                    || in_array($leg->shipment->purpose, ['repair_pickup', 'refund_return'], true))) {
                throw ValidationException::withMessages(['handoff_route' => 'This parcel is already shop-bound. Continue the inbound collection directly, or arrange its shop receipt; do not create another outbound handoff.']);
            }
            if ($leg->resolution_type === 'retry' && ! $key) return $leg;
            if ($leg->custodyTransfers()->whereIn('status', ['requested', 'released'])->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['custody' => 'Complete or cancel the existing handoff before changing the retry.']);
            }
            if ($leg->returnLeg()->exists() || $leg->resolution_type === 'return_required') {
                throw ValidationException::withMessages(['resolution' => 'This delivery already has a return resolution.']);
            }
            if (isset($options['expected_assignment_id']) && (int) $options['expected_assignment_id'] !== (int) $previous?->id) {
                throw ValidationException::withMessages(['resolution' => 'Another dispatcher changed this delivery. Refresh before resolving it.']);
            }
            $openIncidents = $leg->incidents()->whereIn('status', ['reported', 'under_review'])
                ->whereNull('recovery_context->execution_superseded_by_incident_id')->lockForUpdate()->get();
            if ($openIncidents->contains(fn ($incident) => (int) $incident->id !== (int) ($options['incident_id'] ?? 0)
                || (int) $incident->revision !== (int) ($options['incident_revision'] ?? -1)
                || ! $incident->decided_at || $incident->resolution !== 'retry')) {
                throw ValidationException::withMessages(['incident' => 'Resolve the open incident before authorizing a retry.']);
            }
            $failedPickup = $leg->resolution_type === 'pickup_failed' || (! $leg->picked_up_at && $leg->custody_state === 'customer'
                && (($leg->shipment->source_type === 'repair_request' && $leg->shipment->purpose === 'repair_pickup')
                    || ($leg->shipment->source_type === 'order_refund' && $leg->shipment->purpose === 'refund_return')));
            if (! $failedPickup && $leg->custody_state === 'unknown') {
                throw ValidationException::withMessages(['custody' => 'Custody is unconfirmed. Complete an evidenced return-to-shop handoff before arranging another delivery.']);
            }
            $date = $options['delivery_date'] ?? $this->nextOperatingDate($leg);
            $window = $options['delivery_window'] ?? $leg->delivery_window ?? 'morning';
            $this->starts->validateRecoveryDate($shop, $date, $window);
            $immediate = $this->starts->immediateRecovery($shop, $date, $window, $options);
            $settings = $shop->logisticsSetting()->firstOrCreate([]);
            $rider = RiderProfile::where('shop_owner_id', $shop->id)->lockForUpdate()->find($options['rider_profile_id'] ?? $previous?->rider_profile_id);
            if (! $rider) throw ValidationException::withMessages(['rider_profile_id' => 'Select an eligible rider in this shop.']);
            $this->assignments->assertEligibleForDate($rider, $shop, $date, $leg->id, $options['capacity_override_reason'] ?? null);
            $confirmed = (bool) ($options['appointment_confirmed'] ?? false);
            $appointment = $this->starts->appointmentChanges($leg, $date, $window, $confirmed,
                $options['appointment_agreement_note'] ?? null, $actor instanceof \App\Models\User ? $actor : null);
            $previousSchedule = $this->starts->scheduleFacts($leg);
            $leg->assignments()->whereIn('status', ['assigned', 'accepted'])->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $assignment = $leg->assignments()->create([
                'assignment_type' => 'internal_rider', 'rider_profile_id' => $rider->id,
                'assigned_by_type' => $actor::class, 'assigned_by_id' => $actor->getAuthIdentifier(),
                'status' => 'assigned', 'assigned_at' => now(), 'recovery_key' => $key,
            ]);
            $leg->update([...$appointment, 'status' => $failedPickup ? 'assigned' : 'needs_resolution', 'resolution_type' => 'retry',
                'resolution_reason' => $reason, 'scheduled_delivery_date' => $date, 'delivery_window' => $window, 'schedule_status' => 'scheduled']);
            if (in_array($leg->custody_state, ['rider', 'shop'], true)
                && ($leg->custody_state === 'shop' || (int) $leg->custody_rider_profile_id !== $rider->id || ($options['handoff_route'] ?? 'direct') === 'via_shop')) {
                if (! $actor instanceof \App\Models\User) throw ValidationException::withMessages(['custody' => 'A dispatcher must arrange this handoff.']);
                $viaShop = ($options['handoff_route'] ?? 'direct') === 'via_shop' && $leg->custody_state === 'rider';
                $this->custody->request($leg, $actor, $viaShop ? 'shop' : 'rider', $viaShop ? null : $rider->id, (string) \Illuminate\Support\Str::uuid());
            }
            $metadata = ['delivery_assignment_id' => $assignment->id, 'rider_profile_id' => $rider->id,
                'previous_schedule_facts' => $previousSchedule,
                'previous_assignment_id' => $previous?->id, 'resolution_reason' => $reason, 'delivery_date' => $date, 'delivery_window' => $window,
                'appointment_confirmed' => $confirmed, 'appointment_agreement_note' => $confirmed ? ($options['appointment_agreement_note'] ?? null) : null,
                'handoff_route' => $options['handoff_route'] ?? 'direct', 'capacity_override_reason' => $options['capacity_override_reason'] ?? null,
                'immediate_recovery' => $immediate, 'incident_id' => $options['incident_id'] ?? null, 'incident_revision' => $options['incident_revision'] ?? null,
                'additional_attempt_authorized' => $leg->attempts()->count() >= $settings->max_delivery_attempts];
            $this->events->record($leg->shipment, $leg, ['event_type' => 'leg_assigned', 'message' => 'A new retry offer was assigned.',
                'metadata' => $metadata, 'created_by_type' => $actor::class, 'created_by_id' => $actor->getAuthIdentifier()]);
            if ($previous && (int) $previous->rider_profile_id !== $rider->id) {
                $this->events->record($leg->shipment, $leg, ['event_type' => 'rider_reassigned', 'visibility' => 'customer',
                    'message' => 'A different rider is assigned to your next visit. Required package handoffs must be confirmed first.',
                    'metadata' => ['delivery_assignment_id' => $assignment->id, 'rider_profile_id' => $rider->id, 'from_rider_profile_id' => $previous->rider_profile_id],
                    'created_by_type' => $actor::class, 'created_by_id' => $actor->getAuthIdentifier()]);
            }
            $eventType = $failedPickup ? 'pickup_rescheduled' : 'delivery_retry_authorized';
            $this->events->record($leg->shipment, $leg, ['event_type' => $eventType, 'message' => 'Dispatcher authorized another visit.',
                'metadata' => $metadata, 'created_by_type' => $actor::class, 'created_by_id' => $actor->getAuthIdentifier()]);
            $this->events->record($leg->shipment, $leg, ['event_type' => $eventType, 'visibility' => 'customer', 'message' => 'Another visit has been scheduled.',
                'metadata' => ['delivery_assignment_id' => $assignment->id, 'rider_profile_id' => $rider->id, 'delivery_date' => $date, 'delivery_window' => $window]]);
            return $leg->fresh();
        }, 3);
    }

    public function requireReturn(ShipmentLeg $leg, string $reason, ?Authenticatable $actor = null): ShipmentLeg
    {
        if (! filled($reason)) {
            throw ValidationException::withMessages(['reason' => 'Return reason is required.']);
        }

        return DB::transaction(function () use ($leg, $reason, $actor) {
            $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            $this->activeWork->assertNotHeld($leg);
            $this->assertTransitionAllowed($leg, ['needs_resolution'], 'return required');
            if ($leg->custodyTransfers()->whereIn('status', ['requested', 'released'])->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['custody' => 'Complete or cancel the outstanding parcel handoff before selecting return.']);
            }
            if ($leg->resolution_type === 'retry') {
                throw ValidationException::withMessages([
                    'resolution' => 'This delivery is already scheduled for retry.',
                ]);
            }

            $existingReturn = ShipmentLeg::query()
                ->where('return_for_leg_id', $leg->id)
                ->lockForUpdate()
                ->first();
            if ($existingReturn) {
                if ($leg->resolution_type !== 'return_required') {
                    throw ValidationException::withMessages([
                        'resolution' => 'This delivery already has a conflicting return resolution.',
                    ]);
                }

                return $leg->fresh();
            }

            $assignment = $leg->assignments()
                ->when($leg->custody_state === 'rider' && $leg->custody_confirmed_at,
                    fn ($query) => $query->where('rider_profile_id', $leg->custody_rider_profile_id),
                    fn ($query) => $query->whereIn('status', ['assigned', 'accepted']))
                ->latest('id')->lockForUpdate()->first();
            if (! $assignment) {
                throw ValidationException::withMessages([
                    'custody' => 'An active rider assignment is required before return can be selected.',
                ]);
            }

            $leg->update(['resolution_type' => 'return_required', 'resolution_reason' => $reason]);
            $this->events->record($leg->shipment, $leg, ['event_type' => 'return_required', 'visibility' => 'customer', 'message' => 'The parcel is awaiting return to the shop.', 'created_by_type' => $actor?->getMorphClass() ?? ShopOwner::class, 'created_by_id' => $actor?->getAuthIdentifier() ?? $leg->shipment->shop_owner_id]);
            $this->createReturnToShopLocked($leg, $assignment, $actor);

            return $leg->fresh();
        }, 3);
    }

    public function createReturnToShop(ShipmentLeg $leg): ShipmentLeg
    {
        return DB::transaction(function () use ($leg) {
            $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            $this->activeWork->assertNotHeld($leg);
            if (! in_array($leg->status->value, ['delivery_attempted', 'needs_resolution'], true) || $leg->resolution_type !== 'return_required') {
                throw ValidationException::withMessages(['status' => 'Return can only start from a return-required failed delivery.']);
            }
            $existing = ShipmentLeg::query()
                ->where('return_for_leg_id', $leg->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }
            $assignment = $leg->assignments()
                ->whereIn('status', ['assigned', 'accepted'])
                ->lockForUpdate()
                ->first();
            if (! $assignment) {
                throw ValidationException::withMessages([
                    'custody' => 'An active rider assignment is required before return can start.',
                ]);
            }

            return $this->createReturnToShopLocked($leg, $assignment);
        }, 3);
    }

    public function recoverConfirmedLossToShop(ShipmentLeg $leg, \App\Models\User $actor, RiderProfile $holder): ShipmentLeg
    {
        return DB::transaction(function () use ($leg, $actor, $holder) {
            $shop = $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::with('shipment')->lockForUpdate()->findOrFail($leg->id);
            abort_unless((int) $actor->shop_owner_id === (int) $shop->id && $actor->can('resolve-logistics-exceptions'), 403);
            if ($leg->status->value !== 'cancelled' || $leg->resolution_type !== 'loss_confirmed' || $leg->custody_state !== 'lost' || $leg->returnLeg()->exists()) {
                throw ValidationException::withMessages(['custody' => 'Only an evidenced recovered loss without an existing return can enter supervised safekeeping.']);
            }
            $this->assignments->assertEligibleForDate($holder, $shop, now(config('app.shop_timezone', 'Asia/Manila'))->toDateString(), $leg->id);
            $leg->update(['custody_state' => 'rider', 'custody_rider_profile_id' => $holder->id, 'custody_confirmed_at' => now()]);
            return $this->createReturnToShopLocked($leg, $leg->assignments()->latest('id')->firstOrFail(), $actor, $holder);
        }, 3);
    }

    private function createReturnToShopLocked(ShipmentLeg $leg, DeliveryAssignment $assignment, ?Authenticatable $actor = null, ?RiderProfile $recoveredHolder = null): ShipmentLeg
    {
        $existing = ShipmentLeg::query()
            ->where('return_for_leg_id', $leg->id)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            return $existing;
        }

        $shop = ShopOwner::query()->whereKey($leg->shipment->shop_owner_id)->lockForUpdate()->firstOrFail();
        $this->movements->assertCanStart($shop);

        $return = $leg->shipment->legs()->create([
            'sequence' => $leg->shipment->legs()->max('sequence') + 1,
            'shop_owner_id' => $leg->shipment->shop_owner_id,
            'delivery_number' => ShipmentLeg::nextDeliveryNumber((int) $leg->shipment->shop_owner_id),
            'leg_type' => 'return_to_shop',
            'status' => 'picked_up',
            'return_for_leg_id' => $leg->id,
            'origin_snapshot' => in_array($leg->shipment->purpose, ['repair_pickup', 'refund_return'], true) ? $leg->origin_snapshot : $leg->destination_snapshot,
            'destination_snapshot' => ['type' => 'shop', 'shop_owner_id' => $shop->id, 'name' => $shop->business_name,
                'address' => $shop->business_address, 'latitude' => $shop->shop_latitude, 'longitude' => $shop->shop_longitude],
            'requires_delivery_proof' => true,
            'custody_state' => $leg->custody_state,
            'custody_rider_profile_id' => $leg->custody_rider_profile_id,
            'custody_confirmed_at' => $leg->custody_confirmed_at,
        ]);
        $return->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $recoveredHolder?->id ?? $assignment->rider_profile_id,
            'assigned_by_type' => $actor?->getMorphClass() ?? $assignment->assigned_by_type,
            'assigned_by_id' => $actor?->getAuthIdentifier() ?? $assignment->assigned_by_id,
            'status' => 'accepted',
            'assigned_at' => now(),
            'accepted_at' => now(),
        ]);
        $leg->assignments()->whereIn('status', ['assigned', 'accepted'])->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        if ($leg->resolution_type !== 'loss_confirmed') $this->reserveFailedDeliveryRefundForReturn($leg);
        $this->events->record($leg->shipment, $return, [
            'event_type' => 'return_to_shop_started',
            'visibility' => 'customer',
            'message' => 'The parcel is being returned to the shop.',
            'created_by_type' => $actor?->getMorphClass() ?? $assignment->assigned_by_type, 'created_by_id' => $actor?->getAuthIdentifier() ?? $assignment->assigned_by_id,
            'metadata' => ['rider_profile_id' => $recoveredHolder?->id ?? $assignment->rider_profile_id, 'delivery_assignment_id' => $return->assignments()->value('id')],
        ]);

        return $return;
    }

    private function reserveFailedDeliveryRefundForReturn(ShipmentLeg $leg): void
    {
        if ($leg->shipment->source_type !== 'order' || $leg->shipment->purpose !== 'retail_delivery') {
            return;
        }

        $reasonCode = $leg->attempts()
            ->where('attempt_type', 'delivery')
            ->latest('id')
            ->value('reason_code');
        if (! $reasonCode) {
            return;
        }

        $order = Order::query()->where('shop_owner_id', $leg->shipment->shop_owner_id)->find($leg->shipment->source_id);
        if ($order && $this->isPaidOnlineOrder($order)) {
            $this->refunds->reserveFailedDeliveryRefund($order, $leg, (string) $reasonCode);
        }
    }

    public function confirmReturnHandoff(ShipmentLeg $return, HandoffProof $proof, RiderProfile $rider): ShipmentLeg
    {
        return DB::transaction(function () use ($return, $proof, $rider) {
            $this->movements->lockShopForLeg($return);
            $return = ShipmentLeg::query()->lockForUpdate()->findOrFail($return->id);
            $proof = HandoffProof::query()->lockForUpdate()->findOrFail($proof->id);
            if ($return->leg_type !== 'return_to_shop' || $proof->shipment_leg_id !== $return->id || $proof->handoff_type !== 'receive'
                || ! $return->assignments()->where('rider_profile_id', $rider->id)->where('status', 'accepted')->exists()) {
                abort(403);
            }
            if ($return->status->value === 'delivered' && $proof->review_status === 'approved') {
                return $return;
            }
            $this->activeWork->assertCanAdvanceLeg($rider, $return);
            if (! in_array($proof->review_status, ['pending', 'rider_confirmed'], true)) {
                throw ValidationException::withMessages(['proof' => 'This return handoff proof cannot be confirmed.']);
            }
            if ($proof->review_status === 'pending') {
                $proof->update(['review_status' => 'rider_confirmed', 'reviewed_by_type' => RiderProfile::class, 'reviewed_by_id' => $rider->id, 'reviewed_at' => now()]);
            }

            return $return;
        }, 3);
    }

    public function confirmReturnReceipt(ShipmentLeg $return, HandoffProof $proof, ShopOwner $shop, ?Authenticatable $actor = null): ShipmentLeg
    {
        return DB::transaction(function () use ($return, $proof, $shop, $actor) {
            $this->movements->lockShopForLeg($return);
            $return = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($return->id);
            $proof = HandoffProof::query()->lockForUpdate()->findOrFail($proof->id);
            if ($return->shipment->shop_owner_id !== $shop->id || $return->leg_type !== 'return_to_shop'
                || $proof->shipment_leg_id !== $return->id || $proof->handoff_type !== 'receive') {
                abort(403);
            }
            $isDirectRefundReturn = $return->shipment->source_type === 'order_refund'
                && $return->shipment->purpose === 'refund_return' && ! $return->return_for_leg_id;
            if (!$isDirectRefundReturn && !$return->return_for_leg_id) {
                abort(403);
            }
            $original = $isDirectRefundReturn
                ? null
                : ShipmentLeg::query()->lockForUpdate()->findOrFail($return->return_for_leg_id);
            if ($return->status->value === 'delivered' && $proof->review_status === 'approved') {
                if ($return->rider_progress_state !== RiderProgressState::RIDER_RELEASED) {
                    $return->update(['rider_progress_state' => RiderProgressState::RIDER_RELEASED]);
                }
                if ($original && $original->rider_progress_state !== RiderProgressState::RIDER_RELEASED) {
                    $original->update(['rider_progress_state' => RiderProgressState::RIDER_RELEASED]);
                }
                if ($isDirectRefundReturn) {
                    $this->completeDirectRefundReturn($return);
                } elseif ($original->resolution_type !== 'loss_confirmed') {
                    $this->stageFailedDeliveryReturnInspection($return, $original);
                    $this->completeRepairReturnRecovery($return);
                    $this->completeInboundIncidentReceipt($return);
                }

                return $return->fresh();
            }
            if ($proof->review_status !== 'rider_confirmed') {
                abort(403);
            }
            if ($actor && (($proof->confirmed_by_type === $actor::class && (int) $proof->confirmed_by_id === (int) $actor->getAuthIdentifier())
                || ($proof->reviewed_by_type === RiderProfile::class && RiderProfile::whereKey($proof->reviewed_by_id)->where('linked_type', $actor::class)->where('linked_id', $actor->getAuthIdentifier())->exists()))) {
                throw ValidationException::withMessages(['proof' => 'A different authorized receiver must confirm the parcel receipt.']);
            }
            $proof->update(['review_status' => 'approved', 'reviewed_by_type' => ($actor ?? $shop)::class, 'reviewed_by_id' => ($actor ?? $shop)->getAuthIdentifier(), 'reviewed_at' => now()]);
            $return->update([
                'status' => 'delivered',
                'custody_state' => 'shop', 'custody_rider_profile_id' => null, 'custody_confirmed_at' => now(),
                'rider_progress_state' => RiderProgressState::RIDER_RELEASED,
                'delivered_at' => now(),
            ]);
            if ($isDirectRefundReturn) {
                $this->completeDirectRefundReturn($return);
            } else {
                $original->update([
                    'status' => 'cancelled',
                    'custody_state' => 'shop', 'custody_rider_profile_id' => null, 'custody_confirmed_at' => now(),
                    'rider_progress_state' => RiderProgressState::RIDER_RELEASED,
                    'resolution_type' => $original->resolution_type === 'loss_confirmed' ? 'loss_confirmed' : 'returned',
                ]);
                if ($original->resolution_type !== 'loss_confirmed') {
                    $this->stageFailedDeliveryReturnInspection($return, $original);
                    $return->shipment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                    $this->completeRepairReturnRecovery($return);
                    $this->completeInboundIncidentReceipt($return);
                }
            }
            $this->events->record($return->shipment, $return, ['event_type' => 'return_received', 'visibility' => 'customer', 'message' => 'The returned parcel was received by the shop.', 'created_by_type' => ($actor ?? $shop)::class, 'created_by_id' => ($actor ?? $shop)->getAuthIdentifier(), 'metadata' => ['proof_id' => $proof->id]]);

            return $return->fresh();
        }, 3);
    }

    private function completeRepairReturnRecovery(ShipmentLeg $return): void
    {
        if ($return->shipment->source_type !== 'repair_request'
            || $return->shipment->purpose !== 'repair_return') {
            return;
        }

        $repair = RepairRequest::query()
            ->whereKey($return->shipment->source_id)
            ->where('shop_owner_id', $return->shipment->shop_owner_id)
            ->lockForUpdate()
            ->first();

        if (! $repair || (string) $repair->status !== 'shipped') {
            return;
        }

        $repair->update([
            'status' => 'ready_for_pickup',
            'shipped_at' => null,
            'pickup_enabled' => false,
            'pickup_enabled_at' => null,
            'pickup_enabled_by' => null,
            'return_logistics_locked_at' => null,
            'return_address_confirmed_at' => null,
            'return_address_confirmed_version' => null,
        ]);
        $this->notifications->notifyRepairReturnRecovery(
            $repair->fresh(),
            'awaiting_arrangement',
            "return-to-shop:{$return->id}",
        );
    }

    private function completeInboundIncidentReceipt(ShipmentLeg $return): void
    {
        $source = $return->shipment;
        if (($source->source_type === 'repair_request' && $source->purpose === 'repair_pickup')
            || ($source->source_type === 'order_refund' && $source->purpose === 'refund_return')) {
            $source->update(['status' => 'completed', 'completed_at' => now(), 'cancelled_at' => null]);
            $this->completeShopOwnedReturn($source);
        }
    }

    private function stageFailedDeliveryReturnInspection(ShipmentLeg $return, ShipmentLeg $original): void
    {
        if ($return->shipment->source_type !== 'order' || $return->shipment->purpose !== 'retail_delivery') {
            return;
        }

        $refund = OrderRefund::query()
            ->with('items')
            ->where('shop_owner_id', $return->shipment->shop_owner_id)
            ->where('order_id', $return->shipment->source_id)
            ->where('reason_code', 'delivery_attempts_exhausted')
            ->where('idempotency_key', "delivery-attempts-exhausted:{$return->shipment->source_id}:{$original->id}")
            ->latest('id')
            ->first();
        if (! $refund) {
            return;
        }

        // Receipt confirms location, not condition. Existing Staff inspection owns disposition and Finance readiness.
        if ($refund->return_status === 'pending_staff_pickup') {
            $refund->update(['return_status' => 'in_transit', 'staff_return_shipped_at' => $refund->staff_return_shipped_at ?? now()]);
        }
    }

    public function recordFailedAttempt(ShipmentLeg $leg, array $payload, bool $allowAssigned = false): DeliveryAttempt
    {
        $attemptType = (string) ($payload['attempt_type'] ?? 'delivery');
        $isPickup = $attemptType === 'pickup';
        if (! in_array($attemptType, ['pickup', 'delivery'], true)) {
            throw ValidationException::withMessages(['attempt_type' => 'Choose a valid attempt type.']);
        }
        if (empty($payload['reason_code'])) {
            throw ValidationException::withMessages(['reason_code' => 'Attempt reason is required.']);
        }
        if ($isPickup && ! in_array($payload['reason_code'], self::PICKUP_REASONS, true)) {
            throw ValidationException::withMessages(['reason_code' => 'Choose a valid failed pickup reason.']);
        }
        if (($isPickup || in_array($payload['reason_code'], self::PHOTO_REQUIRED_REASONS, true))
            && empty($payload['file_path'])) {
            throw ValidationException::withMessages([
                'proof_file' => [$isPickup ? 'A failed pickup photo is required.' : 'A photo is required for this reason.'],
            ]);
        }
        if (($isPickup
                ? $payload['reason_code'] === 'other'
                : in_array($payload['reason_code'], self::NOTES_REQUIRED_REASONS, true))
            && blank($payload['notes'] ?? null)) {
            throw ValidationException::withMessages([
                'notes' => [$isPickup ? 'Add a short note for Other.' : 'Add a short note for this reason.'],
            ]);
        }

        $batchId = $leg->delivery_batch_id;

        return DB::transaction(function () use ($leg, $payload, $allowAssigned, $batchId, $attemptType, $isPickup) {
            $this->movements->lockShopForLeg($leg);
            $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
            $assignmentId = (int) ($payload['delivery_assignment_id'] ?? 0);
            $assignment = $assignmentId > 0
                ? DeliveryAssignment::query()->lockForUpdate()->find($assignmentId)
                : null;
            if ($assignmentId > 0 && (! $assignment || (int) $assignment->shipment_leg_id !== (int) $leg->id)) {
                throw ValidationException::withMessages(['delivery_assignment_id' => 'This assignment does not belong to the delivery leg.']);
            }

            if ($assignment && ($payload['recorded_by_type'] ?? null) === \App\Models\User::class) {
                $ownsAssignment = $assignment->riderProfile()
                    ->where('linked_type', \App\Models\User::class)
                    ->where('linked_id', (int) ($payload['recorded_by_id'] ?? 0))
                    ->exists();
                abort_unless($ownsAssignment, 403);
            }

            if ($idempotencyKey !== '') {
                $existing = DeliveryAttempt::query()
                    ->where('shipment_leg_id', $leg->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }
            }

            if ($assignment) {
                $existing = DeliveryAttempt::query()
                    ->where('shipment_leg_id', $leg->id)
                    ->where('attempt_type', $attemptType)
                    ->where('delivery_assignment_id', $assignment->id)
                    ->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }
            }

            $batch = $batchId
                ? DeliveryBatch::query()->lockForUpdate()->find($batchId)
                : null;
            $leg = ShipmentLeg::query()->with('shipment.shopOwner.logisticsSetting')->lockForUpdate()->findOrFail($leg->id);
            $this->activeWork->assertNotHeld($leg);
            if ((int) $leg->delivery_batch_id !== (int) $batchId || ($batchId && ! $batch)) {
                throw ValidationException::withMessages(['leg' => 'This stop changed batches. Please try again.']);
            }
            $retailCollection = $leg->shipment->source_type === 'order_refund' && $leg->shipment->purpose === 'refund_return';
            if ($leg->leg_type === 'return_to_shop' && ! ($isPickup && $retailCollection && ! $leg->picked_up_at)) {
                throw ValidationException::withMessages(['leg' => 'Return-to-shop legs use the return handoff workflow.']);
            }
            if (! $assignment) {
                $assignment = $leg->assignments()->whereIn('status', ['assigned', 'accepted'])->lockForUpdate()->first();
            }
            if (! $assignment || ! in_array($assignment->status, ['assigned', 'accepted'], true)) {
                throw ValidationException::withMessages(['delivery_assignment_id' => 'An active delivery assignment is required.']);
            }
            if (($payload['recorded_by_type'] ?? null) === \App\Models\User::class) {
                $this->activeWork->assertCanAdvanceLeg($assignment->riderProfile, $leg);
            }
            $preserveCustody = false;
            if ($isPickup) {
                if (! $retailCollection && ($leg->shipment->source_type !== 'repair_request'
                    || $leg->shipment->purpose !== 'repair_pickup')) {
                    throw ValidationException::withMessages([
                        'attempt_type' => 'Failed pickup is available only for customer collection deliveries.',
                    ]);
                }
                $this->assertTransitionAllowed($leg, ['assigned', 'pickup_scheduled'], 'reported as a failed pickup');
                if ($leg->picked_up_at) {
                    throw ValidationException::withMessages(['status' => 'This pickup was already confirmed.']);
                }
                if (! $this->arrivals->eventForAssignment($leg, 'pickup_arrived', $assignment)) {
                    throw ValidationException::withMessages([
                        'arrival' => 'Record your arrival at the pickup location before reporting a failed pickup.',
                    ]);
                }
            } else {
                $this->assertTransitionAllowed(
                    $leg,
                    $allowAssigned ? ['assigned', 'picked_up', 'in_transit', 'delivery_attempted'] : ['in_transit', 'delivery_attempted'],
                    'delivery attempted',
                );
            }
            $attemptNumber = $leg->attempts()->where('attempt_type', $attemptType)->lockForUpdate()->get()->count() + 1;
            $maxAttempts = $leg->shipment->shopOwner->logisticsSetting?->max_delivery_attempts ?? 2;
            $terminalPickup = $isPickup && ! $retailCollection && $attemptNumber >= $maxAttempts;
            $attempt = $leg->attempts()->create([
                'delivery_assignment_id' => $assignment->id,
                'delivery_batch_id' => $batchId,
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                'attempt_type' => $attemptType,
                'status' => 'failed',
                'attempt_number' => $attemptNumber,
                'reason_code' => $payload['reason_code'],
                'notes' => $payload['notes'] ?? null,
                'file_path' => $payload['file_path'] ?? null,
                'attempted_at' => $payload['attempted_at'] ?? now(),
                'next_attempt_at' => $payload['next_attempt_at'] ?? null,
                'recorded_by_type' => $payload['recorded_by_type'] ?? null,
                'recorded_by_id' => $payload['recorded_by_id'] ?? null,
            ]);

            if ($isPickup) {
                $leg->update([
                    'status' => $terminalPickup ? 'cancelled' : 'needs_resolution',
                    'rider_progress_state' => $terminalPickup
                        ? RiderProgressState::RIDER_RELEASED
                        : RiderProgressState::ACTIVE,
                    'failed_at' => now(),
                    'delivery_batch_id' => null,
                    'stop_sequence' => null,
                    'resolution_type' => $terminalPickup ? 'pickup_attempts_exhausted' : 'pickup_failed',
                    'resolution_reason' => $terminalPickup
                        ? 'Maximum pickup attempts reached.'
                        : ($retailCollection && $attemptNumber >= $maxAttempts ? 'Collection attempts exhausted. Dispatcher investigation required.' : $payload['reason_code']),
                ]);
                if ($terminalPickup) {
                    $repair = RepairRequest::query()
                        ->whereKey($leg->shipment->source_id)
                        ->where('shop_owner_id', $leg->shipment->shop_owner_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $repair->update(['status' => 'cancelled']);
                    $this->repairDelivery->recordPickupRecovery(
                        $repair,
                        (int) $leg->shipment_id,
                        (int) $leg->id,
                    );
                    $this->requestExhaustedPickupRefund(
                        $repair,
                        (int) ($payload['recorded_by_id'] ?? 0),
                        (string) $payload['reason_code'],
                    );
                }
            } else {
                $needsResolution = $attemptNumber >= $maxAttempts;
                $preserveCustody = true;
                $leg->update([
                    'status' => 'needs_resolution',
                    'failed_at' => now(),
                    'attempt_number' => $attemptNumber + 1,
                    'scheduled_delivery_date' => $leg->scheduled_delivery_date,
                    'delivery_batch_id' => null,
                    'stop_sequence' => null,
                    'resolution_type' => null,
                    'resolution_reason' => $needsResolution ? 'Maximum delivery attempts reached.' : null,
                ]);
            }

            if (! $preserveCustody) {
                $assignment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            }
            if ($batch) {
                $this->reconcileBatchState(
                    $batch->id,
                    $isPickup
                        ? 'All stops were removed after failed pickup attempts.'
                        : 'All stops were removed after failed delivery attempts.',
                );
            }
            $leg->shipment->update($terminalPickup
                ? ['status' => 'cancelled', 'completed_at' => null, 'cancelled_at' => now()]
                : ['status' => 'active']);
            $attemptMetadata = ['delivery_attempt_id' => $attempt->id, 'delivery_assignment_id' => $assignment->id,
                'attempt_number' => $attemptNumber, 'reason_code' => $payload['reason_code']];
            if ($isPickup) {
                $this->events->record($leg->shipment, $leg, [
                    'event_type' => 'pickup_attempt_failed',
                    'visibility' => 'internal',
                    'message' => 'Rider reported a failed pickup.',
                    'metadata' => $attemptMetadata,
                    'created_by_type' => $payload['recorded_by_type'] ?? null, 'created_by_id' => $payload['recorded_by_id'] ?? null,
                ]);
                $this->events->record($leg->shipment, $leg, [
                    'event_type' => 'pickup_attempt_failed',
                    'visibility' => 'customer',
                    'message' => 'The pickup could not be completed.',
                    'metadata' => $attemptMetadata,
                    'created_by_type' => $payload['recorded_by_type'] ?? null, 'created_by_id' => $payload['recorded_by_id'] ?? null,
                ]);
                if ($terminalPickup) {
                    $this->events->record($leg->shipment, $leg, [
                        'event_type' => 'pickup_cancelled',
                        'visibility' => 'customer',
                        'message' => 'Maximum pickup attempts reached. The repair request was cancelled.',
                        'metadata' => $attemptMetadata,
                    ]);
                }
            } else {
                $this->events->record($leg->shipment, $leg, [
                    'event_type' => 'delivery_attempt_failed',
                    'visibility' => 'customer',
                    'message' => 'Delivery attempt failed.',
                    'metadata' => $attemptMetadata,
                    'created_by_type' => $payload['recorded_by_type'] ?? null, 'created_by_id' => $payload['recorded_by_id'] ?? null,
                ]);
            }

            return $attempt;
        }, 3);
    }

    private function nextOperatingDate(ShipmentLeg $leg): string
    {
        $leg->loadMissing('shipment.shopOwner.logisticsSetting');
        $settings = $leg->shipment->shopOwner->logisticsSetting;
        $next = now(config('app.shop_timezone', 'Asia/Manila'))->addDay();

        while (! in_array($next->dayOfWeekIso, $settings?->operating_days ?? [1, 2, 3, 4, 5, 6], true)
            || in_array($next->toDateString(), $settings?->blackout_dates ?? [], true)) {
            $next->addDay();
        }

        return $next->toDateString();
    }

    private function requestExhaustedPickupRefund(RepairRequest $repair, int $actorId, string $failureReason): void
    {
        if ((bool) $repair->is_warranty_job
            || (string) $repair->billing_mode === 'warranty_no_charge'
            || (float) $repair->total_paid_amount <= 0) {
            return;
        }

        $source = $this->repairRefunds->resolveRecordedRefundSource($repair, $actorId);
        $fullBalance = $this->repairRefunds->computeRecordedRepairRefundableAmount((int) $repair->id);
        if (! $source || $fullBalance <= 0) {
            return;
        }

        $pickupFee = min(
            $fullBalance,
            $this->repairRefunds->computeRecordedPaidIntakeDeliveryAmount((int) $repair->id),
        );
        $customerCaused = in_array($failureReason, self::CUSTOMER_CAUSED_PICKUP_REASONS, true);
        $operationsCaused = in_array($failureReason, self::OPERATIONS_CAUSED_PICKUP_REASONS, true);
        $serviceRefundable = $this->repairRefunds->computeRepairRefundableAmount((int) $repair->id);
        $amount = min($fullBalance, max(0.0, round($serviceRefundable, 2)));
        if ($amount <= 0) {
            return;
        }

        $feeLabel = number_format($pickupFee, 2, '.', ',');
        $repairOnlyLabel = number_format($amount, 2, '.', ',');
        $notes = match (true) {
            $customerCaused => "Auto-created after maximum repair pickup attempts were reached. The failure was customer-caused ({$failureReason}); the paid pickup fee of PHP {$feeLabel} is not refundable.",
            $operationsCaused => "Auto-created after maximum repair pickup attempts were reached. The failure was operations-caused ({$failureReason}); the paid pickup fee of PHP {$feeLabel} is not refundable.",
            default => "Auto-created after maximum repair pickup attempts were reached. The paid pickup fee of PHP {$feeLabel} is not refundable for {$failureReason}; the service refund of PHP {$repairOnlyLabel} was requested.",
        };

        $this->repairRefunds->requestRefund($source, [
            'request_type' => $amount < $fullBalance ? 'partial' : 'full',
            'requested_amount' => $amount,
            'reason_code' => 'pickup_attempts_exhausted',
            'reason_notes' => $notes,
        ], $actorId);
    }

    private function isPaidOnlineOrder(Order $order): bool
    {
        $paymentMethod = strtolower((string) ($order->payment_method ?? ''));

        return ! in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery', 'cash'], true)
            && in_array((string) ($order->payment_status ?? ''), ['paid', 'completed'], true);
    }

    private function transition(ShipmentLeg $leg, string $status, array $extra, string $eventType, string $message): ShipmentLeg
    {
        $leg->loadMissing('shipment');

        return DB::transaction(function () use ($leg, $status, $extra, $eventType, $message) {
            $this->movements->lockShopForLeg($leg);
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            if ($leg->status->value === $status) {
                return $leg;
            }
            $leg->update(['status' => $status, ...$extra]);
            $this->syncShipmentStatus($leg);

            $this->events->record($leg->shipment, $leg, [
                'event_type' => $eventType,
                'visibility' => in_array($eventType, ['in_transit', 'out_for_delivery', 'delivered'], true) ? 'customer' : 'internal',
                'message' => $message,
                'metadata' => ['delivery_assignment_id' => $leg->assignments()->latest('id')->value('id'),
                    'delivery_batch_id' => $leg->delivery_batch_id, 'stop_sequence' => $leg->stop_sequence,
                    'schedule_facts' => $this->starts->scheduleFacts($leg)],
            ]);

            return $leg->fresh();
        }, 3);
    }

    public function reconcileBatchState(?int $batchId, string $emptyReason = 'All stops were removed.'): void
    {
        if (! $batchId) {
            return;
        }

        $batch = DeliveryBatch::query()->lockForUpdate()->find($batchId);
        if (! $batch || $batch->status !== 'in_progress') {
            return;
        }

        $statuses = $batch->legs()->pluck('status')->map(fn ($status) => $status->value ?? $status);
        $changes = ['assigned_stop_count' => $statuses->count()];

        if ($statuses->isEmpty()) {
            $changes += [
                'status' => 'cancelled',
                'completed_at' => null,
                'cancelled_at' => now(),
                'cancellation_reason' => $emptyReason,
            ];
        } elseif ($statuses->every(fn ($status) => in_array($status, ['delivered', 'cancelled'], true))) {
            $completed = $statuses->contains('delivered');
            $changes += $completed
                ? ['status' => 'completed', 'completed_at' => now(), 'cancelled_at' => null]
                : [
                    'status' => 'cancelled',
                    'completed_at' => null,
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'All stops were cancelled.',
                ];
        }

        $batch->update($changes);
    }

    private function assertTransitionAllowed(ShipmentLeg $leg, array $fromStatuses, string $target): void
    {
        $current = $leg->status->value;

        if (! in_array($current, $fromStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => "Shipment leg cannot be marked {$target} from {$current}.",
            ]);
        }
    }

    private function syncShipmentStatus(ShipmentLeg $leg): void
    {
        $shipment = $leg->shipment;
        $statuses = $shipment->legs()->pluck('status')->map(fn ($status) => $status->value ?? $status);

        if ($shipment->legs()->where('resolution_type', 'loss_confirmed')->exists()) {
            $shipment->update(['status' => 'cancelled', 'completed_at' => null, 'cancelled_at' => now()]);

            return;
        }

        if ($statuses->isNotEmpty() && $statuses->every(fn ($status) => $status === 'cancelled')) {
            $shipment->update(['status' => 'cancelled', 'completed_at' => null, 'cancelled_at' => now()]);

            return;
        }

        if ($statuses->isNotEmpty()
            && $statuses->contains('delivered')
            && $statuses->every(fn ($status) => in_array($status, ['delivered', 'cancelled'], true))) {
            $shipment->update(['status' => 'completed', 'completed_at' => now(), 'cancelled_at' => null]);
            $this->completeRetailOrder($shipment);
            $this->completeShopOwnedReturn($shipment);

            return;
        }

        $shipment->update(['status' => 'active', 'completed_at' => null, 'cancelled_at' => null]);
    }

    private function completeRetailOrder($shipment): void
    {
        if ($shipment->source_type !== 'order') {
            return;
        }

        $order = Order::query()->where('shop_owner_id', $shipment->shop_owner_id)->whereKey($shipment->source_id)->lockForUpdate()->first();
        if (! $order || $order->resolvedDeliveryMethod() !== 'shop_owned') {
            return;
        }

        $this->markOrderDeliveredIfShipped($order);
    }

    private function markOrderDeliveredIfShipped(Order $order): void
    {
        if ($order->status !== OrderStatus::SHIPPED) {
            return;
        }

        $order->status = OrderStatus::DELIVERED;
        $order->save();
        app(\App\Services\RetailWarrantyService::class)->captureFulfillment($order);
    }

    private function completeShopOwnedReturn($shipment): void
    {
        if ($shipment->source_type !== 'order_refund' || $shipment->purpose !== 'refund_return') {
            return;
        }

        OrderRefund::query()
            ->whereKey($shipment->source_id)
            ->where('shop_owner_id', $shipment->shop_owner_id)
            ->where('return_source', 'staff')
            ->whereRaw('LOWER(staff_return_carrier) = ?', ['shop-owned logistics'])
            ->where('return_status', 'pending_staff_pickup')
            ->update(['return_status' => 'in_transit', 'staff_return_shipped_at' => now()]);
    }

    private function completeDirectRefundReturn(ShipmentLeg $return): void
    {
        $return->shipment->update([
            'status' => 'completed',
            'completed_at' => now(),
            'cancelled_at' => null,
        ]);
        $this->completeShopOwnedReturn($return->shipment);
    }
}
