<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopRetailWarrantySetting extends Model
{
    use HasFactory;

    protected $fillable = ['shop_owner_id', 'enabled', 'title', 'duration_value', 'duration_unit', 'description', 'terms', 'exclusions', 'instructions'];

    protected $attributes = ['enabled' => false, 'title' => 'Product Warranty', 'duration_value' => 5, 'duration_unit' => 'days'];

    protected $casts = ['enabled' => 'boolean', 'duration_value' => 'integer', 'eligible_orders_from' => 'immutable_datetime'];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $setting) {
            if ($setting->getOriginal('eligible_orders_from') && $setting->isDirty('eligible_orders_from')) {
                throw new \LogicException('The warranty purchase boundary cannot be changed.');
            }
        });
    }
}
