<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformReliabilityScore extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = [
        'shop_owner_id',
        'score_date',
        'score',
        'factor_breakdown',
        'metrics',
        'version',
        'calculated_at',
    ];

    protected $casts = [
        'score_date' => 'date',
        'score' => 'decimal:2',
        'factor_breakdown' => 'array',
        'metrics' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }
}
