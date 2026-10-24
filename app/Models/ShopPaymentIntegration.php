<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopPaymentIntegration extends Model
{
    use HasFactory;

    public const PROVIDER_XENDIT = 'xendit';
    public const PURPOSE_SUPPLIER_PAYOUT = 'supplier_payout';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'shop_owner_id',
        'provider',
        'purpose',
        'environment',
        'secret_key',
        'webhook_callback_token',
        'status',
        'connected_at',
        'last_verified_at',
    ];

    protected $hidden = [
        'secret_key',
        'webhook_callback_token',
    ];

    protected $casts = [
        'secret_key' => 'encrypted',
        'webhook_callback_token' => 'encrypted',
        'connected_at' => 'datetime',
        'last_verified_at' => 'datetime',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function scopeForSupplierPayouts(Builder $query, int $shopId): Builder
    {
        return $query
            ->where('shop_owner_id', $shopId)
            ->where('provider', self::PROVIDER_XENDIT)
            ->where('purpose', self::PURPOSE_SUPPLIER_PAYOUT);
    }

    public function scopeForXenditMoneyOut(Builder $query, int $shopId): Builder
    {
        return $query
            ->where('shop_owner_id', $shopId)
            ->where('provider', self::PROVIDER_XENDIT);
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED
            && trim((string) $this->secret_key) !== ''
            && trim((string) $this->webhook_callback_token) !== '';
    }

    /** @return array<string, mixed> */
    public function toSafeArray(): array
    {
        $secretKey = trim((string) $this->secret_key);

        return [
            'provider' => $this->provider,
            'purpose' => $this->purpose,
            'environment' => $this->environment,
            'status' => $this->status,
            'connected' => $this->isConnected(),
            'secret_key_masked' => $secretKey !== '' ? '••••••••' . substr($secretKey, -4) : null,
            'callback_token_configured' => trim((string) $this->webhook_callback_token) !== '',
            'connected_at' => $this->connected_at?->toISOString(),
            'last_verified_at' => $this->last_verified_at?->toISOString(),
        ];
    }
}
