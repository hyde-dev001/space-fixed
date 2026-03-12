<?php

namespace App\Enums;

enum PriceChangeStatus: string
{
    case PENDING = 'pending';
    case FINANCE_APPROVED = 'finance_approved';
    case FINANCE_REJECTED = 'finance_rejected';
    case OWNER_APPROVED = 'owner_approved';
    case OWNER_REJECTED = 'owner_rejected';

    /**
     * Check if pending at start
     */
    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if approved by finance
     */
    public function isFinanceApproved(): bool
    {
        return $this === self::FINANCE_APPROVED;
    }

    /**
     * Check if rejected by finance
     */
    public function isFinanceRejected(): bool
    {
        return $this === self::FINANCE_REJECTED;
    }

    /**
     * Check if approved by owner
     */
    public function isOwnerApproved(): bool
    {
        return $this === self::OWNER_APPROVED;
    }

    /**
     * Check if rejected by owner
     */
    public function isOwnerRejected(): bool
    {
        return $this === self::OWNER_REJECTED;
    }

    /**
     * Check if request is in final state
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::OWNER_APPROVED, self::OWNER_REJECTED, self::FINANCE_REJECTED]);
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match($this) {
            self::PENDING => '⏳ Awaiting Finance Review',
            self::FINANCE_APPROVED => '✅ Approved by Finance',
            self::FINANCE_REJECTED => '❌ Rejected by Finance',
            self::OWNER_APPROVED => '✅ Approved by Owner',
            self::OWNER_REJECTED => '❌ Rejected by Owner',
        };
    }

    /**
     * Get badge color class for UI
     */
    public function badgeClass(): string
    {
        return match($this) {
            self::PENDING => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
            self::FINANCE_APPROVED => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
            self::FINANCE_REJECTED => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
            self::OWNER_APPROVED => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
            self::OWNER_REJECTED => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        };
    }
}
