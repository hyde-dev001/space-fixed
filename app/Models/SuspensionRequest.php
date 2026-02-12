<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuspensionRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'requested_by',
        'reason',
        'evidence',
        'status',
        'manager_id',
        'manager_status',
        'manager_note',
        'manager_reviewed_at',
        'owner_id',
        'owner_status',
        'owner_note',
        'owner_reviewed_at',
    ];

    protected $casts = [
        'manager_reviewed_at' => 'datetime',
        'owner_reviewed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
