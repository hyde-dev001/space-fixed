<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuspensionAppeal extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_type',
        'account_id',
        'suspension_id',
        'account_name',
        'recipient_email',
        'suspension_reason',
        'suspended_by_super_admin_id',
        'reviewer_id',
        'status',
        'appeal_token',
        'appeal_message',
        'reviewer_notes',
        'submitted_at',
        'reviewed_at',
        'expires_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'suspended_by_super_admin_id');
    }

    public function suspension(): BelongsTo
    {
        return $this->belongsTo(AccountSuspension::class, 'suspension_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'reviewer_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThanOrEqualTo($this->expires_at);
    }
}
