<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

final class PrivilegedSecurityToken extends Model
{
    public const PURPOSE_SETUP = 'setup';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';
    public const PURPOSE_RECOVERY_ACK = 'recovery_ack';

    protected $fillable = [
        'super_admin_id',
        'created_by_super_admin_id',
        'purpose',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function superAdmin(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class);
    }

    public function createdBySuperAdmin(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'created_by_super_admin_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }
}
