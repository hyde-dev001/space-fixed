<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformCreditApplication extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = [
        'shop_id',
        'platform_fee_charge_id',
        'source_type',
        'source_id',
        'source_origin',
        'credit_amount',
        'status',
        'reason',
        'idempotency_key',
        'created_by',
    ];

    protected $casts = [
        'credit_amount' => 'decimal:2',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'shop_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PlatformFeePaymentAllocation::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(PlatformFeeCharge::class, 'platform_fee_charge_id');
    }
}
