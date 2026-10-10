<?php

namespace App\Enums\Logistics;

enum RiderProgressState: string
{
    case ACTIVE = 'active';
    case PROOF_SUBMITTED = 'proof_submitted';
    case PROOF_ACTION_REQUIRED = 'proof_action_required';
    case RIDER_RELEASED = 'rider_released';
}
