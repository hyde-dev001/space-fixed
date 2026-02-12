<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'email_expense_approval',
        'email_leave_approval',
        'email_invoice_created',
        'email_delegation_assigned',
        'browser_expense_approval',
        'browser_leave_approval',
        'browser_invoice_created',
        'browser_delegation_assigned',
    ];

    protected $casts = [
        'email_expense_approval' => 'boolean',
        'email_leave_approval' => 'boolean',
        'email_invoice_created' => 'boolean',
        'email_delegation_assigned' => 'boolean',
        'browser_expense_approval' => 'boolean',
        'browser_leave_approval' => 'boolean',
        'browser_invoice_created' => 'boolean',
        'browser_delegation_assigned' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function getOrCreateForUser(int $userId): self
    {
        return static::firstOrCreate(['user_id' => $userId]);
    }
}
