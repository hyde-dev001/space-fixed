<?php

namespace App\Enums\Logistics;

enum ShipmentLegStatus: string
{
    case PENDING = 'pending';
    case ASSIGNED = 'assigned';
    case PICKUP_SCHEDULED = 'pickup_scheduled';
    case PICKED_UP = 'picked_up';
    case IN_TRANSIT = 'in_transit';
    case DELIVERY_ATTEMPTED = 'delivery_attempted';
    case NEEDS_RESOLUTION = 'needs_resolution';
    case AWAITING_PROOF_APPROVAL = 'awaiting_proof_approval';
    case PROOF_CORRECTION_REQUIRED = 'proof_correction_required';
    case DELIVERED = 'delivered';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
