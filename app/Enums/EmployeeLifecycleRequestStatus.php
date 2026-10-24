<?php

declare(strict_types=1);

namespace App\Enums;

enum EmployeeLifecycleRequestStatus: string
{
    case PENDING_MANAGER = 'pending_manager';
    case PENDING_OWNER = 'pending_owner';
    case APPROVED = 'approved';
    case REJECTED_MANAGER = 'rejected_manager';
    case REJECTED_OWNER = 'rejected_owner';

    public function isPendingManager(): bool
    {
        return $this === self::PENDING_MANAGER;
    }

    public function isPendingOwner(): bool
    {
        return $this === self::PENDING_OWNER;
    }

    public function isDecided(): bool
    {
        return in_array($this, [
            self::APPROVED,
            self::REJECTED_MANAGER,
            self::REJECTED_OWNER,
        ], true);
    }

    public function toFrontend(): string
    {
        return match ($this) {
            self::PENDING_MANAGER, self::PENDING_OWNER => 'pending',
            self::APPROVED => 'approved',
            self::REJECTED_MANAGER, self::REJECTED_OWNER => 'rejected',
        };
    }
}
