<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopOwnerSubscriptionPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_owner_id',
        'subscription_id',
        'source_subscription_id',
        'from_premium_plan_id',
        'to_premium_plan_id',
        'payment_type',
        'gateway',
        'currency',
        'paymongo_session_id',
        'paymongo_payment_id',
        'plan_price',
        'proration_credit',
        'amount_due',
        'amount_paid',
        'status',
        'metadata',
        'paid_at',
    ];

    protected $casts = [
        'plan_price' => 'decimal:2',
        'proration_credit' => 'decimal:2',
        'amount_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'metadata' => 'array',
        'paid_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ShopOwnerSubscription::class, 'subscription_id');
    }

    public function sourceSubscription(): BelongsTo
    {
        return $this->belongsTo(ShopOwnerSubscription::class, 'source_subscription_id');
    }

    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(PremiumPlan::class, 'from_premium_plan_id');
    }

    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(PremiumPlan::class, 'to_premium_plan_id');
    }
}