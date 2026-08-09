<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HandoffProof extends Model
{
    use HasFactory;

    protected $hidden = [
        'file_path',
    ];

    protected $fillable = [
        'shipment_leg_id',
        'idempotency_key',
        'handoff_type',
        'proof_type',
        'file_path',
        'confirmed_by_type',
        'confirmed_by_id',
        'notes',
        'metadata',
        'review_status',
        'reviewed_by_type',
        'reviewed_by_id',
        'reviewed_at',
        'rejection_reason',
        'recorded_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'recorded_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function leg(): BelongsTo
    {
        return $this->belongsTo(ShipmentLeg::class, 'shipment_leg_id');
    }
}
