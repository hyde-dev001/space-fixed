<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformFeeSetting extends Model
{
    protected $fillable = [
        'scope',
        'shop_type',
        'platform_fee_rate',
        'platform_fee_vat_enabled',
        'platform_fee_vat_rate',
        'balance_limit',
        'warning_threshold_percentage',
        'critical_threshold_percentage',
        'enforcement_enabled',
        'effective_from',
        'reliability_window_days',
        'reliability_version',
        'reliability_weights',
        'reliability_tiers',
        'terms_version',
        'terms_text',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'platform_fee_rate' => 'decimal:6',
        'platform_fee_vat_enabled' => 'boolean',
        'platform_fee_vat_rate' => 'decimal:6',
        'balance_limit' => 'decimal:2',
        'warning_threshold_percentage' => 'decimal:4',
        'critical_threshold_percentage' => 'decimal:4',
        'enforcement_enabled' => 'boolean',
        'effective_from' => 'datetime',
        'reliability_window_days' => 'integer',
        'reliability_weights' => 'array',
        'reliability_tiers' => 'array',
        'terms_version' => 'string',
        'terms_text' => 'string',
        'approved_at' => 'datetime',
    ];
}
