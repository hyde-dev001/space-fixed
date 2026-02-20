<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        // Original preferences (Finance/HR)
        'email_expense_approval',
        'email_leave_approval',
        'email_invoice_created',
        'email_delegation_assigned',
        'browser_expense_approval',
        'browser_leave_approval',
        'browser_invoice_created',
        'browser_delegation_assigned',
        // Customer preferences
        'email_order_updates',
        'email_repair_updates',
        'email_payment_updates',
        'browser_order_updates',
        'browser_repair_updates',
        'browser_payment_updates',
        // Shop Owner preferences
        'email_new_orders',
        'email_approvals',
        'email_alerts',
        'browser_new_orders',
        'browser_approvals',
        'browser_alerts',
        // Staff preferences
        'email_tasks',
        'email_hr_updates',
        'browser_tasks',
        'browser_hr_updates',
        // Phase 6: Quiet Hours
        'quiet_hours_enabled',
        'quiet_hours_start',
        'quiet_hours_end',
        // Phase 6: Browser Push
        'browser_push_enabled',
        'push_subscription',
        // Phase 6: Grouping
        'group_notifications',
        // Phase 6: Auto-archive
        'auto_archive_enabled',
        'auto_archive_days',
    ];

    protected $casts = [
        // Original casts
        'email_expense_approval' => 'boolean',
        'email_leave_approval' => 'boolean',
        'email_invoice_created' => 'boolean',
        'email_delegation_assigned' => 'boolean',
        'browser_expense_approval' => 'boolean',
        'browser_leave_approval' => 'boolean',
        'browser_invoice_created' => 'boolean',
        'browser_delegation_assigned' => 'boolean',
        // Customer preferences
        'email_order_updates' => 'boolean',
        'email_repair_updates' => 'boolean',
        'email_payment_updates' => 'boolean',
        'browser_order_updates' => 'boolean',
        'browser_repair_updates' => 'boolean',
        'browser_payment_updates' => 'boolean',
        // Shop Owner preferences
        'email_new_orders' => 'boolean',
        'email_approvals' => 'boolean',
        'email_alerts' => 'boolean',
        'browser_new_orders' => 'boolean',
        'browser_approvals' => 'boolean',
        'browser_alerts' => 'boolean',
        // Staff preferences
        'email_tasks' => 'boolean',
        'email_hr_updates' => 'boolean',
        'browser_tasks' => 'boolean',
        'browser_hr_updates' => 'boolean',
        // Phase 6 preferences
        'quiet_hours_enabled' => 'boolean',
        'browser_push_enabled' => 'boolean',
        'group_notifications' => 'boolean',
        'auto_archive_enabled' => 'boolean',
        'auto_archive_days' => 'integer',
    ];

    /**
     * Get the user that owns these preferences
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get or create notification preferences for a user
     * 
     * @param int $userId
     * @return self
     */
    public static function getOrCreateForUser(int $userId): self
    {
        return static::firstOrCreate(
            ['user_id' => $userId],
            [
                // Default values for all preferences
                'email_expense_approval' => true,
                'email_leave_approval' => true,
                'email_invoice_created' => false,
                'email_delegation_assigned' => true,
                'browser_expense_approval' => true,
                'browser_leave_approval' => true,
                'browser_invoice_created' => true,
                'browser_delegation_assigned' => true,
                'email_order_updates' => true,
                'email_repair_updates' => true,
                'email_payment_updates' => true,
                'browser_order_updates' => true,
                'browser_repair_updates' => true,
                'browser_payment_updates' => true,
                'email_new_orders' => true,
                'email_approvals' => true,
                'email_alerts' => true,
                'browser_new_orders' => true,
                'browser_approvals' => true,
                'browser_alerts' => true,
                'email_tasks' => true,
                'email_hr_updates' => true,
                'browser_tasks' => true,
                'browser_hr_updates' => true,
                // Phase 6 defaults
                'quiet_hours_enabled' => false,
                'browser_push_enabled' => false,
                'group_notifications' => true,
                'auto_archive_enabled' => false,
                'auto_archive_days' => 30,
            ]
        );
    }

    /**
     * Check if currently in quiet hours
     */
    public function isQuietHours(): bool
    {
        if (!$this->quiet_hours_enabled || !$this->quiet_hours_start || !$this->quiet_hours_end) {
            return false;
        }

        $now = now()->format('H:i:s');
        $start = $this->quiet_hours_start;
        $end = $this->quiet_hours_end;

        // Handle overnight quiet hours (e.g., 22:00 to 06:00)
        if ($start > $end) {
            return $now >= $start || $now <= $end;
        }

        return $now >= $start && $now <= $end;
    }

    /**
     * Check if email notifications are enabled for a specific type
     * 
     * @param string $type
     * @return bool
     */
    public function isEmailEnabled(string $type): bool
    {
        $key = 'email_' . $type;
        return $this->$key ?? false;
    }

    /**
     * Check if browser notifications are enabled for a specific type
     * 
     * @param string $type
     * @return bool
     */
    public function isBrowserEnabled(string $type): bool
    {
        $key = 'browser_' . $type;
        return $this->$key ?? true; // Default to true for browser notifications
    }
}

