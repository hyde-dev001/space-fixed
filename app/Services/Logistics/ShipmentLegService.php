<?php

namespace App\Services\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Services\OrderRefundService;
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

    public function __construct(
        private ProofService $proofs,
        private DeliveryEventService $events,
        private OrderRefundService $refunds,
        private RiderActiveWorkGuard $activeWork,
    ) {}

    public function markPickedUp(ShipmentLeg $leg, ?RiderProfile $rider = null): ShipmentLeg
    {
        return DB::transaction(function () use ($leg, $rider) {
            $leg = ShipmentLeg::query()
                ->with(['shipment', 'deliveryBatch'])
                ->lockForUpdate()
                ->findOrFail($leg->id);

            if (! $leg->delivery_batch_id && $leg->status->value === 'picked_up') {
                if ($rider && $leg->assignments()->where('rider_profile_id', $rider->id)->whereIn('status', ['assigned', 'accepted'])->exists()) {
                    return $leg;
                }

                throw ValidationException::withMessages(['rider' => 'This delivery is not assigned to this rider.']);
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
            if ($leg->status->value !== 'picked_up' || $leg->deliveryBatch?->status !== 'in_progress'
                || ! $leg->assignments()->where('rider_profile_id', $rider->id)->where('status', 'accepted')->exists()) {
                throw ValidationException::withMessages(['status' => 'This stop cannot start delivery.']);
            }

            return $this->transition($leg, 'in_transit', ['out_for_delivery_at' => now()], 'out_for_delivery', 'Your delivery is out for delivery.');
        });
    }

    public function markInTransit(ShipmentLeg $leg): ShipmentLeg
    {
        $this->assertTransitionAllowed($leg, ['picked_up'], 'in transit');

        return $this->transition($leg, 'in_transit', [], 'in_transit', 'Shipment leg is in transit.');
    }

    public function markDelivered(ShipmentLeg $leg): ShipmentLeg
    {
        $leg->loadMissing('shipment');
        $this->assertTransitionAllowed(
            $leg,
            $leg->requires_delivery_proof ? ['awaiting_proof_approval'] : ['in_transit', 'delivery_attempted'],
            'delivered'
        );

        if (! $this->proofs->hasRequiredDeliveryProof($leg)) {
            throw ValidationException::withMessages(['proof' => 'Delivery proof is required before marking this leg delivered.']);
        }

        $delivered = $this->transition($leg, 'delivered', ['delivered_at' => now()], 'delivered', 'Shipment leg delivered.');
        $batch = $delivered->deliveryBatch;
        if ($batch && ! $batch->legs()->where('status', '!=', 'delivered')->exists()) {
            $batch->update(['status' => 'completed', 'completed_at' => now()]);
        }

        return $delivered;
    }

    public function cancel(ShipmentLeg $leg, string $customerReason): ShipmentLeg
    {
        $leg->loadMissing('shipment');
        $this->assertTransitionAllowed($leg, ['delivery_attempted', 'needs_resolution'], 'cancelled');
        if ($leg->picked_up_at && ! in_array($leg->resolution_type, ['returned', 'loss_confirmed'], true)) {
            throw ValidationException::withMessages(['custody' => 'Post-pickup cancellation requires a confirmed return or loss resolution.']);
        }

        return DB::transaction(function () use ($leg, $customerReason) {
            $leg->update(['status' => 'cancelled']);
            $this->syncShipmentStatus($leg);

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

    public function resolveRetry(ShipmentLeg $leg, string $reason): ShipmentLeg
    {
        if (! filled($reason)) {
            throw ValidationException::withMessages(['reason' => 'Resolution reason is required.']);
        }

        return DB::transaction(function () use ($leg, $reason) {
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            $this->assertTransitionAllowed($leg, ['needs_resolution'], 'scheduled for retry');
            $leg->update(['status' => 'pending', 'resolution_type' => 'retry', 'resolution_reason' => $reason, 'scheduled_delivery_date' => now(config('app.shop_timezone', 'Asia/Manila'))->addDay()->toDateString()]);
            $this->events->record($leg->shipment, $leg, ['event_type' => 'delivery_retry_authorized', 'visibility' => 'customer', 'message' => 'Another delivery attempt has been scheduled.']);

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
            $leg->update(['resolution_type' => 'return_required', 'resolution_reason' => $reason]);
            $this->events->record($leg->shipment, $leg, ['event_type' => 'return_required', 'visibility' => 'customer', 'message' => 'The parcel is awaiting return to the shop.']);

            return $leg->fresh();
        });
    }

    public function createReturnToShop(ShipmentLeg $leg): ShipmentLeg
    {
        return DB::transaction(function () use ($leg) {
            $leg = ShipmentLeg::query()->with(['shipment', 'assignments'])->lockForUpdate()->findOrFail($leg->id);
            if (! in_array($leg->status->value, ['delivery_attempted', 'needs_resolution'], true) || $leg->resolution_type !== 'return_required') {
                throw ValidationException::withMessages(['status' => 'Return can only start from a return-required failed delivery.']);
            }
            $existing = ShipmentLeg::where('return_for_leg_id', $leg->id)->first();
            if ($existing) {
                return $existing;
            }
            $return = $leg->shipment->legs()->create([
                'sequence' => $leg->shipment->legs()->max('sequence') + 1, 'leg_type' => 'return_to_shop',
                'status' => 'picked_up', 'return_for_leg_id' => $leg->id,
                'origin_snapshot' => $leg->destination_snapshot, 'destination_snapshot' => $leg->origin_snapshot,
                'requires_delivery_proof' => true,
            ]);
            $assignment = $leg->assignments->firstWhere('status', 'accepted') ?? $leg->assignments->firstWhere('status', 'assigned');
            if ($assignment) {
                $return->assignments()->create([
                    'assignment_type' => 'internal_rider', 'rider_profile_id' => $assignment->rider_profile_id,
                    'assigned_by_type' => $assignment->assigned_by_type, 'assigned_by_id' => $assignment->assigned_by_id,
                    'status' => 'accepted', 'assigned_at' => now(), 'accepted_at' => now(),
                ]);
            }
            $this->events->record($leg->shipment, $return, ['event_type' => 'return_to_shop_started', 'visibility' => 'customer', 'message' => 'The parcel is being returned to the shop.']);

            return $return;
        });
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
            $original = ShipmentLeg::query()->lockForUpdate()->findOrFail($return->return_for_leg_id);
            if ($return->status->value === 'delivered' && $proof->review_status === 'approved') {
                $this->completeFailedDeliveryRefundReturn($return, $original);

                return $return->fresh();
            }
            if ($proof->review_status !== 'rider_confirmed') {
                abort(403);
            }
            $proof->update(['review_status' => 'approved', 'reviewed_by_type' => ShopOwner::class, 'reviewed_by_id' => $shop->id, 'reviewed_at' => now()]);
            $return->update(['status' => 'delivered', 'delivered_at' => now()]);
            $original->update(['status' => 'cancelled', 'resolution_type' => 'returned']);
            $this->completeFailedDeliveryRefundReturn($return, $original);
            $return->shipment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $this->events->record($return->shipment, $return, ['event_type' => 'return_received', 'visibility' => 'customer', 'message' => 'The returned parcel was received by the shop.']);

            return $return->fresh();
        });
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
                if (! $leg->events()->where('event_type', 'pickup_arrived')->exists()) {
                    throw ValidationException::withMessages([
                        'arrival' => 'Record your pickup arrival before reporting a failed pickup.',
                    ]);
                }
            } else {
                $this->assertTransitionAllowed(
                    $leg,
                    $allowAssigned ? ['assigned', 'picked_up', 'in_transit', 'delivery_attempted'] : ['in_transit', 'delivery_attempted'],
                    'delivery attempted',
                );
            }
            if (! $assignment) {
                $assignment = $leg->assignments()->whereIn('status', ['assigned', 'accepted'])->lockForUpdate()->first();
            }
            if (! $assignment || ! in_array($assignment->status, ['assigned', 'accepted'], true)) {
                throw ValidationException::withMessages(['delivery_assignment_id' => 'An active delivery assignment is required.']);
            }

            $attemptNumber = $leg->attempts()->where('attempt_type', $attemptType)->count() + 1;
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
                    'status' => 'needs_resolution',
                    'failed_at' => now(),
                    'delivery_batch_id' => null,
                    'stop_sequence' => null,
                    'resolution_type' => 'pickup_failed',
                    'resolution_reason' => $payload['reason_code'],
                ]);
            } else {
                $max = $leg->shipment->shopOwner->logisticsSetting?->max_delivery_attempts ?? 2;
                $needsResolution = $attemptNumber >= $max;
                $settings = $leg->shipment->shopOwner->logisticsSetting;
                $next = now(config('app.shop_timezone', 'Asia/Manila'))->addDay();
                while (! in_array($next->dayOfWeekIso, $settings?->operating_days ?? [1, 2, 3, 4, 5, 6], true)
                    || in_array($next->toDateString(), $settings?->blackout_dates ?? [], true)) {
                    $next->addDay();
                }
                $nextDate = $next->toDateString();
                $leg->update([
                    'status' => $needsResolution ? 'needs_resolution' : 'pending',
                    'failed_at' => now(),
                    'attempt_number' => $attemptNumber + 1,
                    'scheduled_delivery_date' => $needsResolution ? $leg->scheduled_delivery_date : $nextDate,
                    'delivery_batch_id' => null,
                    'stop_sequence' => null,
                    'resolution_type' => $needsResolution ? 'return_required' : null,
                    'resolution_reason' => $needsResolution ? 'Maximum delivery attempts reached.' : null,
                ]);

                if ($needsResolution) {
                    $this->createReturnToShop($leg->fresh());

                    if ($leg->shipment->source_type === 'order' && $leg->shipment->purpose === 'retail_delivery') {
                        $order = Order::query()->find($leg->shipment->source_id);
                        if ($order && $this->isPaidOnlineOrder($order)) {
                            $this->refunds->reserveFailedDeliveryRefund($order, $leg);
                        }
                    }
                }
            }

            $assignment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            if ($batch) {
                $remainingStops = $batch->legs()->count();
                $batchChanges = ['assigned_stop_count' => $remainingStops];
                if ($batch->status === 'in_progress' && $remainingStops === 0) {
                    $batchChanges += [
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                        'cancellation_reason' => $isPickup
                            ? 'All stops were removed after failed pickup attempts.'
                            : 'All stops were removed after failed delivery attempts.',
                    ];
                }
                $batch->update($batchChanges);
            }
            $leg->shipment->update(['status' => 'active']);
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

        if ($statuses->isNotEmpty() && $statuses->every(fn ($status) => $status === 'cancelled')) {
            $shipment->update(['status' => 'cancelled', 'completed_at' => null]);

            return;
        }

        if ($statuses->isNotEmpty()
            && $statuses->contains('delivered')
            && $statuses->every(fn ($status) => in_array($status, ['delivered', 'cancelled'], true))) {
            $shipment->update(['status' => 'completed', 'completed_at' => now()]);
            $this->completeShopOwnedRetailOrder($shipment);
            $this->completeShopOwnedReturn($shipment);

            return;
        }

        $shipment->update(['status' => 'active', 'completed_at' => null]);
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
            ->update(['status' => 'completed']);
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
}
