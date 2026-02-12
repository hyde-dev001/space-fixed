<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SHIPPED = 'shipped';
    case COMPLETED = 'completed';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';

    /**
     * Check if order is pending
     */
    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if order can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return in_array($this, [self::PENDING, self::PROCESSING]);
    }

    /**
     * Check if order is in final state
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED, self::DELIVERED]);
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match($this) {
            self::PENDING => '⏳ Pending',
            self::PROCESSING => '⚙️ Processing',
            self::COMPLETED => '✅ Completed',
            self::CANCELLED => '🚫 Cancelled',
            self::DELIVERED => '📦 Delivered',
        };
    }

    /**
     * Get badge color class for UI
     */
    public function badgeClass(): string
    {
        return match($this) {
            self::PENDING => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
            self::PROCESSING => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
            self::COMPLETED => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
            self::CANCELLED => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
            self::DELIVERED => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        };
    }
}
