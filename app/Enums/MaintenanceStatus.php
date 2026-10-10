<?php

namespace App\Enums;

enum MaintenanceStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Ended || $this === self::Cancelled;
    }
}
