<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformFeePayment extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = [
        'shop_id',
        'provider_checkout_id',
        'provider_payment_id',
        'amount',
        'balance_snapshot',
        'credit_snapshot',
        'net_payable_snapshot',
        'status',
        'idempotency_key',
        'paid_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_snapshot' => 'decimal:2',
        'credit_snapshot' => 'decimal:2',
        'net_payable_snapshot' => 'decimal:2',
        'paid_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'shop_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PlatformFeePaymentAllocation::class);
    }
}
