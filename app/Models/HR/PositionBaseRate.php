<?php

namespace App\Models\HR;

use App\Models\ShopOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PositionBaseRate extends Model
{
    protected $table = 'hr_position_base_rates';

    protected $fillable = [
        'shop_owner_id',
        'position_code',
        'position_name',
        'department',
        'monthly_rate',
        'effective_from',
        'effective_to',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'monthly_rate' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
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
