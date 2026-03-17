<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PremiumPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_code',
        'name',
        'description',
        'price',
        'duration_days',
        'showroom_slot_limit',
        'status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'duration_days' => 'integer',
        'showroom_slot_limit' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ShopOwnerSubscription::class, 'premium_plan_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
