<?php

namespace App\Models\Logistics;

use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsSetting extends Model
{
    protected $fillable = [
        'shop_owner_id', 'operating_days', 'cutoff_time', 'blackout_dates',
        'lead_time_days', 'morning_start', 'morning_end', 'afternoon_start',
        'afternoon_end', 'coverage_radius_km', 'arrival_radius_m', 'daily_rider_capacity',
        'max_delivery_attempts',
    ];

    protected $attributes = [
        'operating_days' => '[1,2,3,4,5,6]',
        'cutoff_time' => '15:00',
        'blackout_dates' => '[]',
        'lead_time_days' => 1,
        'morning_start' => '08:00',
        'morning_end' => '12:00',
        'afternoon_start' => '13:00',
        'afternoon_end' => '18:00',
        'coverage_radius_km' => 20,
        'arrival_radius_m' => 100,
        'daily_rider_capacity' => 20,
        'max_delivery_attempts' => 2,
    ];

    protected $casts = [
        'operating_days' => 'array',
        'blackout_dates' => 'array',
        'lead_time_days' => 'integer',
        'coverage_radius_km' => 'decimal:2',
        'arrival_radius_m' => 'integer',
        'daily_rider_capacity' => 'integer',
        'max_delivery_attempts' => 'integer',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }
}
