<?php

namespace App\Models;

use App\Enums\MaintenanceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceWindow extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'public_message',
        'internal_note',
        'status',
        'starts_at',
        'ends_at',
        'activated_at',
        'ended_at',
        'cancelled_at',
        'version',
        'notify_before_minutes',
        'transaction_freeze_minutes',
        'progress_stage',
        'public_update_message',
        'public_update_updated_at',
        'created_by',
        'updated_by',
        'activated_by',
        'ended_by',
        'cancelled_by',
    ];

    protected $casts = [
        'status' => MaintenanceStatus::class,
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'activated_at' => 'datetime',
        'ended_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'public_update_updated_at' => 'datetime',
        'version' => 'integer',
        'notify_before_minutes' => 'integer',
        'transaction_freeze_minutes' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'updated_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'activated_by');
    }

    public function ender(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'ended_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'cancelled_by');
    }
}
