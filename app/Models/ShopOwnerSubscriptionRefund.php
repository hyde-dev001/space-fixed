<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

final class ShopOwnerSubscriptionRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'subscription_id',
        'actor_id',
        'local_reference',
        'provider_refund_id',
        'amount',
        'currency',
        'business_reason',
        'provider_reason',
        'status',
        'failure_code',
        'initiated_at',
        'finalized_at',
        'reconciled_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'initiated_at' => 'datetime',
        'finalized_at' => 'datetime',
        'reconciled_at' => 'datetime',
    ];

    /** @return array<string, mixed> */
    public function safeArray(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'local_reference' => (string) $this->local_reference,
            'provider_refund_id' => $this->provider_refund_id,
            'payment_id' => (int) $this->payment_id,
            'subscription_id' => (int) $this->subscription_id,
            'amount' => (string) $this->amount,
            'currency' => (string) $this->currency,
            'business_reason' => (string) $this->business_reason,
            'provider_reason' => (string) $this->provider_reason,
            'status' => (string) $this->status,
            'failure_code' => $this->failure_code,
            'initiated_at' => $this->initiated_at?->toISOString(),
            'finalized_at' => $this->finalized_at?->toISOString(),
            'reconciled_at' => $this->reconciled_at?->toISOString(),
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(ShopOwnerSubscriptionPayment::class, 'payment_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ShopOwnerSubscription::class, 'subscription_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'actor_id');
    }
}
