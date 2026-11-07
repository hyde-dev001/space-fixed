<?php

declare(strict_types=1);

namespace App\Enums;

enum OwnerActionCenterDegradationStatus: string
{
    case NotSelected = 'not_selected';
    case None = 'none';
    case NoEnabledAdapters = 'no_enabled_adapters';
    case Partial = 'partial';
    case Unavailable = 'unavailable';
}
