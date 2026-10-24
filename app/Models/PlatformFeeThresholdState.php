<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformFeeThresholdState extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = [
        'shop_id',
        'threshold_key',
        'crossed',
        'crossing_count',
        'utilization_percentage',
        'last_notified_at',
    ];

    protected $casts = [
        'crossed' => 'boolean',
        'crossing_count' => 'integer',
        'utilization_percentage' => 'decimal:2',
        'last_notified_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'shop_id');
    }
}
