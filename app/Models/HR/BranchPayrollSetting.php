<?php

namespace App\Models\HR;

use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchPayrollSetting extends Model
{
    protected $table = 'hr_branch_payroll_settings';

    protected $fillable = [
        'shop_owner_id',
        'branch_name',
        'pay_cycle',
        'pay_day_first',
        'pay_day_second',
        'standard_work_days_per_month',
        'standard_work_hours_per_day',
        'night_differential_start',
        'night_differential_end',
        'night_differential_rate',
        'overtime_multiplier',
        'rest_day_multiplier',
        'special_holiday_multiplier',
        'regular_holiday_multiplier',
        'non_business_day_rule',
        'is_active',
    ];

    protected $casts = [
        'pay_day_first' => 'integer',
        'pay_day_second' => 'integer',
        'standard_work_days_per_month' => 'integer',
        'standard_work_hours_per_day' => 'decimal:2',
        'night_differential_rate' => 'decimal:2',
        'overtime_multiplier' => 'decimal:2',
        'rest_day_multiplier' => 'decimal:2',
        'special_holiday_multiplier' => 'decimal:2',
        'regular_holiday_multiplier' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function scopeForShopOwner($query, int $shopOwnerId)
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
