<?php

namespace App\Enums\Logistics;

enum CarrierType: string
{
    case INTERNAL = 'internal';
    case EXTERNAL = 'external';
    case CUSTOMER_CONTROLLED = 'customer_controlled';
}
