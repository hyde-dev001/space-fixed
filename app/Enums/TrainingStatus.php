<?php

namespace App\Enums;

enum TrainingStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    /**
     * Check if training is pending
     */
    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if training is ongoing
     */
    public function isInProgress(): bool
    {
        return $this === self::IN_PROGRESS;
    }

    /**
     * Check if training is completed
     */
    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match($this) {
            self::PENDING => '⏳ Pending',
            self::IN_PROGRESS => '⚙️ In Progress',
            self::COMPLETED => '✅ Completed',
            self::CANCELLED => '🚫 Cancelled',
        };
    }

    /**
     * Get badge color class for UI
     */
    public function badgeClass(): string
    {
        return match($this) {
            self::PENDING => 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200',
            self::IN_PROGRESS => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
            self::COMPLETED => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
            self::CANCELLED => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        };
    }
}
