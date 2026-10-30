<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RetailWarrantyIssuance extends Model
{
    use HasFactory;

    protected $fillable = ['shop_owner_id', 'order_id', 'customer_id', 'warranty_number', 'shop_snapshot', 'customer_snapshot', 'order_snapshot', 'fulfilled_at', 'issued_at', 'business_timezone'];

    protected $attributes = ['email_delivery_state' => 'pending', 'email_attempts' => 0];

    protected $hidden = ['customer_snapshot', 'certificate_path', 'certificate_hash', 'email_delivery_state', 'email_attempt_token', 'email_attempted_at', 'email_sent_at', 'provider_reference', 'email_failure_code', 'email_attempts'];

    protected $casts = [
        'shop_snapshot' => 'array', 'customer_snapshot' => 'array', 'order_snapshot' => 'array', 'fulfilled_at' => 'immutable_datetime',
        'issued_at' => 'immutable_datetime', 'certificate_generated_at' => 'immutable_datetime',
        'certificate_status_at_generation' => 'array', 'email_attempted_at' => 'immutable_datetime',
        'email_sent_at' => 'immutable_datetime', 'email_attempts' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function warranties(): HasMany
    {
        return $this->hasMany(RetailWarranty::class)->orderBy('order_item_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $issuance) {
            if ($issuance->isDirty($issuance->getFillable())) {
                throw new \LogicException('Issued warranty facts cannot be rewritten.');
            }
        });
    }
}
