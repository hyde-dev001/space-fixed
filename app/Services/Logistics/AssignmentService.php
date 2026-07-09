<?php

namespace App\Services\Logistics;

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

    public function assignInternalRider(ShipmentLeg $leg, RiderProfile $rider, ShopOwner $actor): DeliveryAssignment
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

        return DB::transaction(function () use ($leg, $rider, $actor) {
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

            $this->events->record($leg->shipment, $leg, [
                'event_type' => 'leg_assigned',
                'visibility' => 'internal',
                'message' => "Assigned to {$rider->name}.",
            ]);

            return $assignment;
        });
    }
}
