<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformFeePaymentRequest extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = [
        'shop_id',
        'requested_by_user_id',
        'requested_amount',
        'balance_snapshot',
        'credit_snapshot',
        'net_payable_snapshot',
        'status',
        'owner_approved_by_shop_owner_id',
        'owner_approved_at',
        'owner_rejected_at',
        'owner_decision_note',
        'executed_by_user_id',
        'platform_fee_payment_id',
        'stale_reason',
        'stale_at',
        'idempotency_key',
        'metadata',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'balance_snapshot' => 'decimal:2',
        'credit_snapshot' => 'decimal:2',
        'net_payable_snapshot' => 'decimal:2',
        'owner_approved_at' => 'datetime',
        'owner_rejected_at' => 'datetime',
        'stale_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'shop_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function ownerApprover(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'owner_approved_by_shop_owner_id');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PlatformFeePayment::class, 'platform_fee_payment_id');
    }
}
