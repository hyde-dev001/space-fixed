<?php

namespace App\Enums\Logistics;

enum ShipmentStatus: string
{
    case REQUESTED = 'requested';
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
