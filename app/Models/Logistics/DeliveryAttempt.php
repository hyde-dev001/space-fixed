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
        'attempt_type',
        'status',
        'reason_code',
        'notes',
        'attempted_at',
        'next_attempt_at',
        'recorded_by_type',
        'recorded_by_id',
    ];

    protected $casts = [
        'attempted_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];

    public function leg(): BelongsTo
    {
        return $this->belongsTo(ShipmentLeg::class, 'shipment_leg_id');
    }
}
