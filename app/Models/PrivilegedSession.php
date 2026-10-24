<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

final class PrivilegedSession extends Model
{
    protected $primaryKey = 'session_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'session_id',
        'super_admin_id',
        'security_version',
        'authenticated_at',
        'last_seen_at',
    ];

    protected $hidden = [
        'session_id',
    ];

    protected $casts = [
        'security_version' => 'integer',
        'authenticated_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function superAdmin(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class);
    }
}
