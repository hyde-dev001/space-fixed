<?php

namespace App\Services\Logistics;

use App\Enums\Logistics\CarrierType;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignmentService
{
    public function __construct(private DeliveryEventService $events)
    {
    }

    public function assignInternalRider(ShipmentLeg $leg, RiderProfile $rider, ShopOwner $actor, array $eventMetadata = []): DeliveryAssignment
    {
        $leg->loadMissing('shipment');

        if ((int) $rider->shop_owner_id !== (int) $leg->shipment->shop_owner_id) {
            throw ValidationException::withMessages(['rider_profile_id' => 'Rider does not belong to this shop.']);
        }

        if (!$rider->active || $rider->availability_status === 'inactive') {
            throw ValidationException::withMessages(['rider_profile_id' => 'Rider is not available for assignment.']);
        }

        if ($rider->rider_type === 'shop_owner' && strtolower((string) $actor->registration_type) !== 'individual') {
            throw ValidationException::withMessages(['rider_profile_id' => 'Owner delivery is only allowed for individual shops.']);
        }

        return DB::transaction(function () use ($leg, $rider, $actor, $eventMetadata) {
            $leg = ShipmentLeg::query()
                ->with(['shipment', 'shippingMethod'])
                ->lockForUpdate()
                ->findOrFail($leg->id);

            $method = $leg->shippingMethod;
            if ($method !== null
                && (! $method->active
                    || $method->carrier_type !== CarrierType::INTERNAL
                    || ! $method->requires_assignment
                    || ($method->shop_owner_id !== null
                        && (int) $method->shop_owner_id !== (int) $leg->shipment->shop_owner_id))) {
                throw ValidationException::withMessages([
                    'shipping_method_id' => 'Selected shipping method is not supported by shop-owned logistics.',
                ]);
            }

            if (in_array($leg->status->value, ['needs_resolution', 'delivered', 'cancelled'], true)) {
                throw ValidationException::withMessages(['shipment_leg_id' => 'Only retryable delivery legs can be assigned.']);
            }

            if ($leg->assignments()->whereIn('status', ['assigned', 'accepted'])->exists()) {
                throw ValidationException::withMessages(['shipment_leg_id' => 'This leg already has an active assignment.']);
            }

            $assignment = DeliveryAssignment::create([
                'shipment_leg_id' => $leg->id,
                'assignment_type' => 'internal_rider',
                'rider_profile_id' => $rider->id,
                'assigned_by_type' => $actor::class,
                'assigned_by_id' => $actor->id,
                'status' => 'assigned',
                'assigned_at' => now(),
            ]);

            $leg->update(['status' => 'assigned']);
            $leg->shipment->update(['status' => 'active']);

            $this->events->record($leg->shipment, $leg, [
                'event_type' => 'leg_assigned',
                'visibility' => 'internal',
                'message' => "Assigned to {$rider->name}.",
                'metadata' => ['rider_profile_id' => $rider->id] + $eventMetadata,
            ]);

            return $assignment;
        });
    }

    public function respondToOffer(ShipmentLeg $leg, RiderProfile $rider, bool $accepted, ?string $reason = null): DeliveryAssignment
    {
        if (! $accepted && ! filled($reason)) {
            throw ValidationException::withMessages(['rejection_reason' => 'Rejection reason is required.']);
        }

        return DB::transaction(function () use ($leg, $rider, $accepted, $reason) {
            $leg = ShipmentLeg::query()->with('shipment')->lockForUpdate()->findOrFail($leg->id);
            if ($leg->delivery_batch_id) {
                throw ValidationException::withMessages(['shipment_leg_id' => 'Batch offers must be answered as a batch.']);
            }

            $assignment = DeliveryAssignment::query()
                ->where('shipment_leg_id', $leg->id)
                ->where('rider_profile_id', $rider->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $accepted
                && $assignment?->status === 'rejected'
                && $assignment->rejection_reason === $reason) {
                return $assignment;
            }

            if (! $assignment || ($assignment->status !== 'assigned' && ! ($accepted && $assignment->status === 'accepted'))) {
                throw ValidationException::withMessages(['shipment_leg_id' => 'This delivery offer is no longer available.']);
            }

            if ($assignment->status === 'accepted') {
                return $assignment;
            }

            $assignment->update($accepted
                ? ['status' => 'accepted', 'accepted_at' => now()]
                : ['status' => 'rejected', 'rejection_reason' => $reason, 'rejected_at' => now()]);

            if (! $accepted) {
                $leg->update(['status' => 'pending']);
            }

            $this->events->record($leg->shipment, $leg, [
                'event_type' => $accepted ? 'leg_offer_accepted' : 'leg_offer_rejected',
                'visibility' => 'internal',
                'message' => $accepted ? 'Delivery offer accepted.' : 'Delivery offer rejected.',
                'metadata' => [
                    'rider_profile_id' => $rider->id,
                    'rejection_reason' => $accepted ? null : $reason,
                ],
            ]);

            return $assignment->fresh();
        });
    }
}
