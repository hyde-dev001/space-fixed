<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformFeeAdjustment extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = [
        'shop_id',
        'platform_fee_charge_id',
        'adjustment_type',
        'source_type',
        'source_id',
        'fee_base_delta',
        'platform_fee_delta',
        'vat_delta',
        'total_delta',
        'reason',
        'idempotency_key',
        'created_by',
    ];

    protected $casts = [
        'fee_base_delta' => 'decimal:2',
        'platform_fee_delta' => 'decimal:2',
        'vat_delta' => 'decimal:2',
        'total_delta' => 'decimal:2',
    ];

    public function charge(): BelongsTo
    {
        return $this->belongsTo(PlatformFeeCharge::class, 'platform_fee_charge_id');
    }
}
