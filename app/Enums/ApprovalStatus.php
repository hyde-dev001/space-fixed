<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    /**
     * Check if approval is pending
     */
    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if approval is in final state
     */
    public function isFinal(): bool
    {
        return $this !== self::PENDING;
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match($this) {
            self::PENDING => '⏳ Awaiting Review',
            self::APPROVED => '✅ Approved',
            self::REJECTED => '❌ Rejected',
            self::CANCELLED => '🚫 Cancelled',
        };
    }

    /**
     * Get badge color
     */
    public function color(): string
    {
        return match($this) {
            self::PENDING => 'yellow',
            self::APPROVED => 'green',
            self::REJECTED => 'red',
            self::CANCELLED => 'gray',
        };
    }

    /**
     * Get background color class for UI
     */
    public function badgeClass(): string
    {
        return match($this) {
            self::PENDING => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
            self::APPROVED => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
            self::REJECTED => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
            self::CANCELLED => 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200',
        };
    }
}
