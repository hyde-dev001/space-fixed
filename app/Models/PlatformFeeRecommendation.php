<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformFeeRecommendation extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = [
        'shop_owner_id',
        'reliability_score_id',
        'tier',
        'current_limit',
        'recommended_limit',
        'status',
        'rationale',
        'reviewed_by_super_admin_id',
        'reviewed_at',
        'review_note',
        'idempotency_key',
    ];

    protected $casts = [
        'current_limit' => 'decimal:2',
        'recommended_limit' => 'decimal:2',
        'rationale' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    public function score(): BelongsTo
    {
        return $this->belongsTo(PlatformReliabilityScore::class, 'reliability_score_id');
    }
}
