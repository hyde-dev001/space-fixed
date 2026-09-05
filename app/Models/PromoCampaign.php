<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromoCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_owner_id',
        'kind',
        'discount_target',
        'scope',
        'name',
        'code',
        'discount_mode',
        'value',
        'min_spend',
        'usage_limit',
        'used_count',
        'start_at',
        'end_at',
        'status',
        'stacking_mode',
    ];

    protected $attributes = [
        'discount_target' => 'items',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_spend' => 'decimal:2',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    public function shopOwner()
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'promo_campaign_products');
    }

    public function claims()
    {
        return $this->hasMany(VoucherClaim::class, 'promo_campaign_id');
    }
}
