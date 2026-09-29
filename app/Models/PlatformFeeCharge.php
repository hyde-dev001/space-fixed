<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformFeeCharge extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = [
        'shop_id',
        'source_type',
        'source_id',
        'source_origin',
        'fee_base',
        'fee_rate',
        'platform_fee_amount',
        'vat_enabled',
        'vat_rate',
        'vat_amount',
        'total_charge',
        'status',
        'finalized_at',
        'metadata',
    ];

    protected $casts = [
        'fee_base' => 'decimal:2',
        'fee_rate' => 'decimal:6',
        'platform_fee_amount' => 'decimal:2',
        'vat_enabled' => 'boolean',
        'vat_rate' => 'decimal:6',
        'vat_amount' => 'decimal:2',
        'total_charge' => 'decimal:2',
        'finalized_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'shop_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PlatformFeeAdjustment::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PlatformFeePaymentAllocation::class);
    }
}
