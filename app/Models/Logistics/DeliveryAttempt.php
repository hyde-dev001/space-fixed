<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_leg_id',
        'delivery_assignment_id',
        'delivery_batch_id',
        'idempotency_key',
        'attempt_type',
        'status',
        'attempt_number',
        'reason_code',
        'notes',
        'file_path',
        'attempted_at',
        'next_attempt_at',
        'recorded_by_type',
        'recorded_by_id',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'attempted_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];

    protected $hidden = [
        'file_path',
    ];

    public function leg(): BelongsTo
    {
        return $this->belongsTo(ShipmentLeg::class, 'shipment_leg_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(DeliveryAssignment::class, 'delivery_assignment_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DeliveryBatch::class, 'delivery_batch_id');
    }
}
