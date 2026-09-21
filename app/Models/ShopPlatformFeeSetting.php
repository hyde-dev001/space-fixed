<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopPlatformFeeSetting extends PlatformFeeSetting
{
    protected $table = 'shop_platform_fee_settings';

    protected $fillable = [
        'shop_owner_id',
        'platform_fee_rate',
        'platform_fee_vat_enabled',
        'platform_fee_vat_rate',
        'balance_limit',
        'warning_threshold_percentage',
        'critical_threshold_percentage',
        'enforcement_enabled',
        'status',
        'approved_by',
        'approved_at',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }
}
