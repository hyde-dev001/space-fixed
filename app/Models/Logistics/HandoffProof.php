<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HandoffProof extends Model
{
    use HasFactory;

    protected $hidden = [
        'file_path',
    ];

    protected $fillable = [
        'shipment_leg_id',
        'shop_owner_id',
        'proof_number',
        'replaces_proof_id',
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
        'proof_number' => 'integer',
        'metadata' => 'array',
        'recorded_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function leg(): BelongsTo
    {
        return $this->belongsTo(ShipmentLeg::class, 'shipment_leg_id');
    }

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ShopOwner::class);
    }

    public function replacedProof(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_proof_id');
    }

    public function replacements(): HasMany
    {
        return $this->hasMany(self::class, 'replaces_proof_id');
    }
}
