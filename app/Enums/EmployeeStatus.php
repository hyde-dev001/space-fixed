<?php

namespace App\Enums;

enum EmployeeStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case SUSPENDED = 'suspended';
    case TERMINATED = 'terminated';

    /**
     * Check if employee is active
     */
    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check if employee can access system
     */
    public function canAccess(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check if employment is ended
     */
    public function isEnded(): bool
    {
        return $this === self::TERMINATED;
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match($this) {
            self::ACTIVE => '✅ Active',
            self::INACTIVE => '⏸️ Inactive',
            self::SUSPENDED => '🚫 Suspended',
            self::TERMINATED => '❌ Terminated',
        };
    }

    /**
     * Get badge color class for UI
     */
    public function badgeClass(): string
    {
        return match($this) {
            self::ACTIVE => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
            self::INACTIVE => 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200',
            self::SUSPENDED => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
            self::TERMINATED => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        };
    }
}
