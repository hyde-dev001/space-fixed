<?php

namespace App\Enums;

enum ShopOwnerStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case SUSPENDED = 'suspended';

    /**
     * Check if shop owner is pending approval
     */
    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if shop owner is approved
     */
    public function isApproved(): bool
    {
        return $this === self::APPROVED;
    }

    /**
     * Check if shop owner can access system
     */
    public function canAccess(): bool
    {
        return $this === self::APPROVED;
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match($this) {
            self::PENDING => '⏳ Awaiting Approval',
            self::APPROVED => '✅ Approved',
            self::REJECTED => '❌ Rejected',
            self::SUSPENDED => '🚫 Suspended',
        };
    }

    /**
     * Get badge color class for UI
     */
    public function badgeClass(): string
    {
        return match($this) {
            self::PENDING => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
            self::APPROVED => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
            self::REJECTED => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
            self::SUSPENDED => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
        };
    }
}
