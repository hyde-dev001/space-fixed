<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopOwnerSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_owner_id',
        'premium_plan_id',
        'plan_code',
        'showroom_slot_limit',
        'status',
        'paymongo_session_id',
        'paymongo_payment_id',
        'paid_amount',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'showroom_slot_limit' => 'integer',
        'paid_amount' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    public function premiumPlan(): BelongsTo
    {
        return $this->belongsTo(PremiumPlan::class, 'premium_plan_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            });
    }
}
