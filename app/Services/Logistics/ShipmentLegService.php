<?php

namespace App\Services\Logistics;

use App\Enums\Logistics\RiderProgressState;
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
use App\Services\NotificationService;
use App\Services\OrderRefundService;
use App\Services\RepairDeliveryService;
use App\Services\RepairPosRefundService;
use Illuminate\Support\Facades\DB;
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
    ) {}

    public function markPickedUp(ShipmentLeg $leg, ?RiderProfile $rider = null): ShipmentLeg
    {
        return DB::transaction(function () use ($leg, $rider) {
            $leg = ShipmentLeg::query()
                ->with(['shipment', 'deliveryBatch'])
                ->lockForUpdate()
                ->findOrFail($leg->id);

            if ($leg->status->value === 'picked_up') {
                if ($rider && ! $leg->assignments()->where('rider_profile_id', $rider->id)->whereIn('status', ['assigned', 'accepted'])->exists()) {
                    throw ValidationException::withMessages(['rider' => 'This delivery is not assigned to this rider.']);
                }

                return $leg;
            }

            $this->assertTransitionAllowed($leg, ['assigned', 'pickup_scheduled', 'delivery_attempted'], 'picked up');

            if ($leg->shipment->source_type === 'order_refund' && $leg->shipment->purpose === 'refund_return') {
                $refund = OrderRefund::query()->find($leg->shipment->source_id);
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

            return $this->transition($leg, 'picked_up', ['picked_up_at' => now()], 'picked_up', 'Shipment leg picked up.');
        });
    }

    public function confirmPickup(ShipmentLeg $leg, HandoffProof $proof, RiderProfile $rider): ShipmentLeg
    {
        return DB::transaction(function () use ($leg, $proof, $rider) {
            $leg = ShipmentLeg::query()->lockForUpdate()->findOrFail($leg->id);
            $proof = HandoffProof::query()->lockForUpdate()->findOrFail($proof->id);
            if ($proof->shipment_leg_id !== $leg->id || $proof->handoff_type !== 'pickup'
                || ! $leg->assignments()->where('rider_profile_id', $rider->id)->where('status', 'accepted')->exists()) {
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
        });
    }

    public function rejectPickup(ShipmentLeg $leg, HandoffProof $proof, RiderProfile $rider, string $reason): ShipmentLeg
    {
        if (! filled($reason)) {
            throw ValidationException::withMessages(['reason' => 'Rejection reason is required.']);
        }

        return DB::transaction(function () use ($leg, $proof, $rider, $reason) {
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
        });
    }

    public function markOutForDelivery(ShipmentLeg $leg, RiderProfile $rider): ShipmentLeg
    {
        return DB::transaction(function () use ($leg, $rider) {
            $leg = ShipmentLeg::query()->with(['shipment', 'deliveryBatch'])->lockForUpdate()->findOrFail($leg->id);
            if ($leg->status->value === 'in_transit' && $leg->out_for_delivery_at) {
                return $leg;
            }
            $this->activeWork->assertCanAdvanceLeg($rider, $leg);
            if ($leg->status->value !== 'picked_up' || $leg->deliveryBatch?->status !== 'in_progress'
                || ! $leg->assignments()->where('rider_profile_id', $rider->id)->where('status', 'accepted')->exists()) {
                throw ValidationException::withMessages(['status' => 'This stop cannot start delivery.']);
            }

            return $this->transition($leg, 'in_transit', ['out_for_delivery_at' => now()], 'out_for_delivery', 'Your delivery is out for delivery.');
        });
    }

    public function markInTransit(ShipmentLeg $leg, ?RiderProfile $rider = null): ShipmentLeg
    {
        return DB::transaction(function () use ($leg, $rider) {
            $leg = ShipmentLeg::query()
                ->with('shipment.shopOwner')
                ->lockForUpdate()
                ->findOrFail($leg->id);
            if ($leg->status->value === 'in_transit') {
                return $leg;
            }

            if ($leg->status->value === 'needs_resolution' && $leg->resolution_type === 'retry') {
                if (! $rider) {
                    throw ValidationException::withMessages([
                        'rider' => 'A rider must start a scheduled delivery retry.',
                    ]);
                }

                $today = now(config('app.shop_timezone', 'Asia/Manila'))->toDateString();
                if (! $leg->scheduled_delivery_date || $leg->scheduled_delivery_date->toDateString() > $today) {
                    throw ValidationException::withMessages([
                        'scheduled_delivery_date' => 'This retry cannot start before its scheduled delivery date.',
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
                }

                return $this->transition($leg, 'in_transit', [], 'in_transit', 'Shipment leg retry is in transit.');
            }

            if ($rider) {
                $this->activeWork->assertCanAdvanceLeg($rider, $leg);
            }
            $this->assertTransitionAllowed($leg, ['picked_up'], 'in transit');

            return $this->transition($leg, 'in_transit', [], 'in_transit', 'Shipment leg is in transit.');
        });
    }

    public function markDelivered(ShipmentLeg $leg, ?RiderProfile $rider = null): ShipmentLeg
    {
        return DB::transaction(function () use ($leg, $rider) {
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            if ($leg->status->value === 'delivered') {
                if ($leg->rider_progress_state !== RiderProgressState::RIDER_RELEASED) {
                    $leg->update(['rider_progress_state' => RiderProgressState::RIDER_RELEASED]);
                }

                return $leg;
            }
            if ($rider) {
                $this->activeWork->assertCanAdvanceLeg($rider, $leg);
            }
            $this->assertTransitionAllowed(
                $leg,
                $leg->requires_delivery_proof ? ['awaiting_proof_approval'] : ['in_transit', 'delivery_attempted'],
                'delivered'
            );

            if (! $this->proofs->hasRequiredDeliveryProof($leg)) {
                throw ValidationException::withMessages(['proof' => 'Delivery proof is required before marking this leg delivered.']);
            }

            $delivered = $this->transition($leg, 'delivered', [
                'delivered_at' => now(),
                'rider_progress_state' => RiderProgressState::RIDER_RELEASED,
            ], 'delivered', 'Shipment leg delivered.');
            $this->reconcileBatchState($delivered->delivery_batch_id);

            return $delivered;
        });
    }

    public function cancel(ShipmentLeg $leg, string $customerReason): ShipmentLeg
    {
        $leg->loadMissing('shipment');
        $this->assertTransitionAllowed($leg, ['delivery_attempted', 'needs_resolution'], 'cancelled');
        if ($leg->picked_up_at && ! in_array($leg->resolution_type, ['returned', 'loss_confirmed'], true)) {
            throw ValidationException::withMessages(['custody' => 'Post-pickup cancellation requires a confirmed return or loss resolution.']);
        }

        return DB::transaction(function () use ($leg, $customerReason) {
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
        });
    }

    public function confirmLoss(ShipmentLeg $leg, string $reason): ShipmentLeg
    {
        if (! filled($reason)) {
            throw ValidationException::withMessages(['reason' => 'Loss investigation reason is required.']);
        }

        return DB::transaction(function () use ($leg, $reason) {
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
                $order = Order::query()->find($leg->shipment->source_id);
                if ($order) {
                    $recovery = $this->refunds->reserveConfirmedLossRefund($order, $leg, $reason);
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
                'resolution_reason' => $reason,
            ]);
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
        });
    }

    public function resolveRetry(ShipmentLeg $leg, string $reason): ShipmentLeg
    {
        if (! filled($reason)) {
            throw ValidationException::withMessages(['reason' => 'Resolution reason is required.']);
        }

        return DB::transaction(function () use ($leg, $reason) {
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            $this->assertTransitionAllowed($leg, ['needs_resolution'], 'scheduled for retry');
            $failedPickup = $leg->shipment->purpose === 'repair_pickup'
                && $leg->resolution_type === 'pickup_failed';

            if (! $failedPickup) {
                $hasReturn = ShipmentLeg::query()
                    ->where('return_for_leg_id', $leg->id)
                    ->lockForUpdate()
                    ->exists();
                if ($hasReturn || $leg->resolution_type === 'return_required') {
                    throw ValidationException::withMessages([
                        'resolution' => 'This delivery already has a return resolution.',
                    ]);
                }
                if ($leg->resolution_type === 'retry') {
                    return $leg;
                }

                $assignment = $leg->assignments()
                    ->whereIn('status', ['assigned', 'accepted'])
                    ->lockForUpdate()
                    ->first();
                if (! $assignment) {
                    throw ValidationException::withMessages([
                        'custody' => 'An active rider assignment is required before retry can be scheduled.',
                    ]);
                }
            }

            $leg->update([
                'status' => $failedPickup ? 'pending' : 'needs_resolution',
                'resolution_type' => 'retry',
                'resolution_reason' => $reason,
                'scheduled_delivery_date' => $this->nextOperatingDate($leg),
            ]);

            $eventType = $failedPickup ? 'pickup_rescheduled' : 'delivery_retry_authorized';
            $message = $failedPickup
                ? 'Another pickup attempt has been scheduled.'
                : 'Another delivery attempt has been scheduled.';
            if (! $failedPickup) {
                $this->events->record($leg->shipment, $leg, [
                    'event_type' => $eventType,
                    'visibility' => 'internal',
                    'message' => $message,
                ]);
            }
            $this->events->record($leg->shipment, $leg, [
                'event_type' => $eventType,
                'visibility' => 'customer',
                'message' => $message,
            ]);

            return $leg->fresh();
        });
    }

    public function requireReturn(ShipmentLeg $leg, string $reason): ShipmentLeg
    {
        if (! filled($reason)) {
            throw ValidationException::withMessages(['reason' => 'Return reason is required.']);
        }

        return DB::transaction(function () use ($leg, $reason) {
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            $this->assertTransitionAllowed($leg, ['needs_resolution'], 'return required');
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
                ->whereIn('status', ['assigned', 'accepted'])
                ->lockForUpdate()
                ->first();
            if (! $assignment) {
                throw ValidationException::withMessages([
                    'custody' => 'An active rider assignment is required before return can be selected.',
                ]);
            }

            $leg->update(['resolution_type' => 'return_required', 'resolution_reason' => $reason]);
            $this->events->record($leg->shipment, $leg, ['event_type' => 'return_required', 'visibility' => 'customer', 'message' => 'The parcel is awaiting return to the shop.']);
            $this->createReturnToShopLocked($leg, $assignment);

            return $leg->fresh();
        });
    }

    public function createReturnToShop(ShipmentLeg $leg): ShipmentLeg
    {
        return DB::transaction(function () use ($leg) {
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
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
        });
    }

    private function createReturnToShopLocked(ShipmentLeg $leg, DeliveryAssignment $assignment): ShipmentLeg
    {
        $existing = ShipmentLeg::query()
            ->where('return_for_leg_id', $leg->id)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            return $existing;
        }

        $return = $leg->shipment->legs()->create([
            'sequence' => $leg->shipment->legs()->max('sequence') + 1,
            'leg_type' => 'return_to_shop',
            'status' => 'picked_up',
            'return_for_leg_id' => $leg->id,
            'origin_snapshot' => $leg->destination_snapshot,
            'destination_snapshot' => $leg->origin_snapshot,
            'requires_delivery_proof' => true,
        ]);
        $return->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $assignment->rider_profile_id,
            'assigned_by_type' => $assignment->assigned_by_type,
            'assigned_by_id' => $assignment->assigned_by_id,
            'status' => 'accepted',
            'assigned_at' => now(),
            'accepted_at' => now(),
        ]);
        $assignment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->reserveFailedDeliveryRefundForReturn($leg);
        $this->events->record($leg->shipment, $return, [
            'event_type' => 'return_to_shop_started',
            'visibility' => 'customer',
            'message' => 'The parcel is being returned to the shop.',
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

        $order = Order::query()->find($leg->shipment->source_id);
        if ($order && $this->isPaidOnlineOrder($order)) {
            $this->refunds->reserveFailedDeliveryRefund($order, $leg, (string) $reasonCode);
        }
    }

    public function confirmReturnHandoff(ShipmentLeg $return, HandoffProof $proof, RiderProfile $rider): ShipmentLeg
    {
        return DB::transaction(function () use ($return, $proof, $rider) {
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
        });
    }

    public function confirmReturnReceipt(ShipmentLeg $return, HandoffProof $proof, ShopOwner $shop): ShipmentLeg
    {
        return DB::transaction(function () use ($return, $proof, $shop) {
            $return = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($return->id);
            $proof = HandoffProof::query()->lockForUpdate()->findOrFail($proof->id);
            if ($return->shipment->shop_owner_id !== $shop->id || $return->leg_type !== 'return_to_shop'
                || $proof->shipment_leg_id !== $return->id || $proof->handoff_type !== 'receive') {
                abort(403);
            }
            $isDirectRefundReturn = $return->shipment->source_type === 'order_refund'
                && $return->shipment->purpose === 'refund_return';
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
                } else {
                    $this->completeFailedDeliveryRefundReturn($return, $original);
                    $this->completeRepairReturnRecovery($return);
                }

                return $return->fresh();
            }
            if ($proof->review_status !== 'rider_confirmed') {
                abort(403);
            }
            $proof->update(['review_status' => 'approved', 'reviewed_by_type' => ShopOwner::class, 'reviewed_by_id' => $shop->id, 'reviewed_at' => now()]);
            $return->update([
                'status' => 'delivered',
                'rider_progress_state' => RiderProgressState::RIDER_RELEASED,
                'delivered_at' => now(),
            ]);
            if ($isDirectRefundReturn) {
                $this->completeDirectRefundReturn($return);
            } else {
                $original->update([
                    'status' => 'cancelled',
                    'rider_progress_state' => RiderProgressState::RIDER_RELEASED,
                    'resolution_type' => 'returned',
                ]);
                $this->completeFailedDeliveryRefundReturn($return, $original);
                $return->shipment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                $this->completeRepairReturnRecovery($return);
            }
            $this->events->record($return->shipment, $return, ['event_type' => 'return_received', 'visibility' => 'customer', 'message' => 'The returned parcel was received by the shop.']);

            return $return->fresh();
        });
    }

    private function completeRepairReturnRecovery(ShipmentLeg $return): void
    {
        if ($return->shipment->source_type !== 'repair_request'
            || $return->shipment->purpose !== 'repair_return') {
            return;
        }

        $repair = RepairRequest::query()
            ->whereKey($return->shipment->source_id)
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

    private function completeFailedDeliveryRefundReturn(ShipmentLeg $return, ShipmentLeg $original): void
    {
        if ($return->shipment->source_type !== 'order' || $return->shipment->purpose !== 'retail_delivery') {
            return;
        }

        $refund = OrderRefund::query()
            ->with('items')
            ->where('order_id', $return->shipment->source_id)
            ->where('reason_code', 'delivery_attempts_exhausted')
            ->where('idempotency_key', "delivery-attempts-exhausted:{$return->shipment->source_id}:{$original->id}")
            ->latest('id')
            ->first();
        if (! $refund) {
            return;
        }

        $result = $this->refunds->confirmReturnReceived($refund, null, lineDispositions: $refund->items->map(fn ($line) => [
            'order_item_id' => (int) $line->order_item_id,
            'approved_qty' => (int) $line->approved_qty,
            'inspection_disposition' => 'resellable',
        ])->all());
        if (($result['result'] ?? null) !== 'received') {
            throw ValidationException::withMessages(['refund' => $result['message'] ?? 'Failed-delivery refund could not be completed.']);
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
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            if ($assignment) {
                $existing = DeliveryAttempt::query()
                    ->where('shipment_leg_id', $leg->id)
                    ->where('attempt_type', $attemptType)
                    ->where('delivery_assignment_id', $assignment->id)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $batch = $batchId
                ? DeliveryBatch::query()->lockForUpdate()->find($batchId)
                : null;
            $leg = ShipmentLeg::query()->with('shipment.shopOwner.logisticsSetting')->lockForUpdate()->findOrFail($leg->id);
            if ((int) $leg->delivery_batch_id !== (int) $batchId || ($batchId && ! $batch)) {
                throw ValidationException::withMessages(['leg' => 'This stop changed batches. Please try again.']);
            }
            if ($leg->leg_type === 'return_to_shop') {
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
                if ($leg->shipment->source_type !== 'repair_request'
                    || $leg->shipment->purpose !== 'repair_pickup') {
                    throw ValidationException::withMessages([
                        'attempt_type' => 'Failed pickup is available only for repair pickups.',
                    ]);
                }
                $this->assertTransitionAllowed($leg, ['assigned', 'pickup_scheduled'], 'reported as a failed pickup');
                if ($leg->picked_up_at) {
                    throw ValidationException::withMessages(['status' => 'This pickup was already confirmed.']);
                }
            } else {
                $this->assertTransitionAllowed(
                    $leg,
                    $allowAssigned ? ['assigned', 'picked_up', 'in_transit', 'delivery_attempted'] : ['in_transit', 'delivery_attempted'],
                    'delivery attempted',
                );
            }
            $attemptNumber = $leg->attempts()->where('attempt_type', $attemptType)->count() + 1;
            $maxAttempts = $leg->shipment->shopOwner->logisticsSetting?->max_delivery_attempts ?? 2;
            $terminalPickup = $isPickup && $attemptNumber >= $maxAttempts;
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
                        : $payload['reason_code'],
                ]);
                if ($terminalPickup) {
                    $repair = RepairRequest::query()
                        ->whereKey($leg->shipment->source_id)
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
                $preserveCustody = $needsResolution;
                $leg->update([
                    'status' => $needsResolution ? 'needs_resolution' : 'pending',
                    'failed_at' => now(),
                    'attempt_number' => $attemptNumber + 1,
                    'scheduled_delivery_date' => $needsResolution ? $leg->scheduled_delivery_date : $this->nextOperatingDate($leg),
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
            if ($isPickup) {
                $this->events->record($leg->shipment, $leg, [
                    'event_type' => 'pickup_attempt_failed',
                    'visibility' => 'internal',
                    'message' => 'Rider reported a failed pickup.',
                    'metadata' => ['reason_code' => $payload['reason_code']],
                ]);
                $this->events->record($leg->shipment, $leg, [
                    'event_type' => 'pickup_attempt_failed',
                    'visibility' => 'customer',
                    'message' => 'The pickup could not be completed.',
                    'metadata' => ['reason_code' => $payload['reason_code']],
                ]);
                if ($terminalPickup) {
                    $this->events->record($leg->shipment, $leg, [
                        'event_type' => 'pickup_cancelled',
                        'visibility' => 'customer',
                        'message' => 'Maximum pickup attempts reached. The repair request was cancelled.',
                    ]);
                }
            } else {
                $this->events->record($leg->shipment, $leg, [
                    'event_type' => 'delivery_attempt_failed',
                    'visibility' => 'customer',
                    'message' => 'Delivery attempt failed.',
                    'metadata' => ['reason_code' => $payload['reason_code']],
                ]);
            }

            return $attempt;
        });
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
        $amount = $customerCaused ? max(0.0, round($fullBalance - $pickupFee, 2)) : $fullBalance;
        if ($amount <= 0) {
            return;
        }

        $feeLabel = number_format($pickupFee, 2, '.', ',');
        $repairOnlyLabel = number_format(max(0.0, $fullBalance - $pickupFee), 2, '.', ',');
        $notes = match (true) {
            $customerCaused => "Auto-created after maximum repair pickup attempts were reached. The failure was customer-caused ({$failureReason}); the paid pickup fee of PHP {$feeLabel} was retained.",
            $operationsCaused => "Auto-created after maximum repair pickup attempts were reached. The failure was operations-caused ({$failureReason}); this refund includes the paid pickup fee of PHP {$feeLabel}.",
            default => "Auto-created after maximum repair pickup attempts were reached. Finance must decide whether the paid pickup fee of PHP {$feeLabel} is refundable for {$failureReason}. The full remaining balance was requested; approve PHP {$repairOnlyLabel} to retain the fee.",
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

        return ! in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery'], true)
            && in_array((string) ($order->payment_status ?? ''), ['paid', 'completed'], true);
    }

    private function transition(ShipmentLeg $leg, string $status, array $extra, string $eventType, string $message): ShipmentLeg
    {
        $leg->loadMissing('shipment');

        return DB::transaction(function () use ($leg, $status, $extra, $eventType, $message) {
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            if ($leg->status->value === $status) {
                return $leg;
            }
            $leg->update(['status' => $status, ...$extra]);
            $this->syncShipmentStatus($leg);

            $this->events->record($leg->shipment, $leg, [
                'event_type' => $eventType,
                'visibility' => in_array($eventType, ['in_transit', 'delivered'], true) ? 'customer' : 'internal',
                'message' => $message,
            ]);

            return $leg->fresh();
        });
    }

    private function reconcileBatchState(?int $batchId, string $emptyReason = 'All stops were removed.'): void
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
            $this->completeShopOwnedRetailOrder($shipment);
            $this->completeShopOwnedReturn($shipment);

            return;
        }

        $shipment->update(['status' => 'active', 'completed_at' => null, 'cancelled_at' => null]);
    }

    private function completeShopOwnedRetailOrder($shipment): void
    {
        if ($shipment->source_type !== 'order') {
            return;
        }

        Order::query()
            ->whereKey($shipment->source_id)
            ->whereRaw('LOWER(carrier_company) = ?', ['shop-owned logistics'])
            ->where('status', 'shipped')
            ->update(['status' => 'delivered']);
    }

    private function completeShopOwnedReturn($shipment): void
    {
        if ($shipment->source_type !== 'order_refund' || $shipment->purpose !== 'refund_return') {
            return;
        }

        OrderRefund::query()
            ->whereKey($shipment->source_id)
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
