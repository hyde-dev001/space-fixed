<?php

namespace App\Enums;

enum SuspensionStatus: string
{
    case PENDING_OWNER = 'pending_owner';
    case PENDING_MANAGER = 'pending_manager';
    case APPROVED = 'approved';
    case REJECTED_OWNER = 'rejected_owner';
    case REJECTED_MANAGER = 'rejected_manager';

    /**
     * Check if pending owner approval
     */
    public function isPendingOwner(): bool
    {
        return $this === self::PENDING_OWNER;
    }

    /**
     * Check if pending manager approval
     */
    public function isPendingManager(): bool
    {
        return $this === self::PENDING_MANAGER;
    }

    /**
     * Check if approved
     */
    public function isApproved(): bool
    {
        return $this === self::APPROVED;
    }

    /**
     * Check if rejected
     */
    public function isRejected(): bool
    {
        return in_array($this, [self::REJECTED_OWNER, self::REJECTED_MANAGER]);
    }

    /**
     * Get normalized status for frontend (maps to 'pending', 'approved', 'rejected')
     */
    public function toFrontend(): string
    {
        return match($this) {
            self::PENDING_OWNER, self::PENDING_MANAGER => 'pending',
            self::APPROVED => 'approved',
            self::REJECTED_OWNER, self::REJECTED_MANAGER => 'rejected',
        };
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match($this) {
            self::PENDING_OWNER => '⏳ Awaiting Owner Review',
            self::PENDING_MANAGER => '⏳ Awaiting Manager Review',
            self::APPROVED => '✅ Approved',
            self::REJECTED_OWNER => '❌ Rejected by Owner',
            self::REJECTED_MANAGER => '❌ Rejected by Manager',
        };
    }

    /**
     * Get badge color class for UI
     */
    public function badgeClass(): string
    {
        return match($this) {
            self::PENDING_OWNER, self::PENDING_MANAGER => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
            self::APPROVED => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
            self::REJECTED_OWNER, self::REJECTED_MANAGER => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        };
    }
}
