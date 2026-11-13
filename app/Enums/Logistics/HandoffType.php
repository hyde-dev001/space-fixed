<?php

namespace App\Enums\Logistics;

enum HandoffType: string
{
    case PICKUP = 'pickup';
    case DELIVERY = 'delivery';
    case RECEIVE = 'receive';
}
