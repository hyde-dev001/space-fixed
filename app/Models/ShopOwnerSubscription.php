<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopOwnerSubscription extends Model
{
    use HasFactory;

    public const AUTO_RENEW_STATUS_ENABLED = 'enabled';
    public const AUTO_RENEW_STATUS_DISABLED = 'disabled';
    public const AUTO_RENEW_STATUS_ACTION_REQUIRED = 'action_required';

    protected $fillable = [
        'shop_owner_id',
        'premium_plan_id',
        'plan_code',
        'showroom_slot_limit',
        'status',
        'auto_renew',
        'auto_renew_status',
        'paymongo_session_id',
        'paymongo_payment_id',
        'paid_amount',
        'renewal_of_subscription_id',
        'renewal_due_at',
        'renewal_retry_count',
        'renewal_last_attempt_at',
        'renewal_next_attempt_at',
        'renewal_checkout_session_id',
        'renewal_checkout_url',
        'renewal_checkout_url_expires_at',
        'cancellation_reason',
        'cancellation_notes',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'showroom_slot_limit' => 'integer',
        'auto_renew' => 'boolean',
        'paid_amount' => 'decimal:2',
        'renewal_of_subscription_id' => 'integer',
        'renewal_due_at' => 'datetime',
        'renewal_retry_count' => 'integer',
        'renewal_last_attempt_at' => 'datetime',
        'renewal_next_attempt_at' => 'datetime',
        'renewal_checkout_url_expires_at' => 'datetime',
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

    /**
     * Subscriptions that can still use premium showroom features.
     *
     * Includes:
     * - active subscriptions within their validity window
     * - cancelled subscriptions that are still within their paid access window
     */
    public function scopeShowroomEntitled($query)
    {
        return $query
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'active')
                        ->where(function ($activeWindow) {
                            $activeWindow->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                        });
                })->orWhere(function ($sub) {
                    $sub->where('status', 'cancelled')
                        ->where(function ($cancelledWindow) {
                            $cancelledWindow->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                        });
                });
            });
    }
}
