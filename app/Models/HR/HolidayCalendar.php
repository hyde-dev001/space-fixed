<?php

namespace App\Models\HR;

use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidayCalendar extends Model
{
    protected $table = 'hr_holiday_calendars';

    protected $fillable = [
        'shop_owner_id',
        'holiday_date',
        'holiday_name',
        'holiday_type',
        'is_paid',
        'rate_multiplier',
        'is_active',
    ];

    protected $casts = [
        'holiday_date' => 'date',
        'is_paid' => 'boolean',
        'rate_multiplier' => 'decimal:2',
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
