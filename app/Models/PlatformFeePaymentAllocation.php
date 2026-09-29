<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformFeePaymentAllocation extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = [
        'platform_fee_payment_id',
        'platform_fee_charge_id',
        'platform_credit_application_id',
        'allocation_type',
        'amount',
        'idempotency_key',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PlatformFeePayment::class, 'platform_fee_payment_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(PlatformFeeCharge::class, 'platform_fee_charge_id');
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(PlatformCreditApplication::class, 'platform_credit_application_id');
    }
}
