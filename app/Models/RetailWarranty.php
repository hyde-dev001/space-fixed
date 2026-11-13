<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetailWarranty extends Model
{
    use HasFactory;

    protected $fillable = ['retail_warranty_issuance_id', 'shop_owner_id', 'order_id', 'order_item_id', 'customer_id', 'policy_snapshot', 'item_snapshot', 'original_covered_quantity', 'warranty_start_date', 'warranty_expiration_date'];

    protected $attributes = ['status' => 'active', 'refunded_quantity' => 0];

    protected $casts = [
        'policy_snapshot' => 'array', 'item_snapshot' => 'array', 'original_covered_quantity' => 'integer',
        'refunded_quantity' => 'integer', 'warranty_start_date' => 'immutable_datetime',
        'warranty_expiration_date' => 'immutable_datetime', 'voided_at' => 'immutable_datetime',
    ];

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(RetailWarrantyIssuance::class, 'retail_warranty_issuance_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $warranty) {
            if ($warranty->isDirty($warranty->getFillable())) {
                throw new \LogicException('Item warranty snapshots cannot be rewritten.');
            }
        });
    }
}
