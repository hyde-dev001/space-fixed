<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRefundItem extends Model
{
    protected $fillable = [
        'order_refund_id',
        'order_item_id',
        'product_id',
        'product_variant_id',
        'requested_qty',
        'approved_qty',
        'unit_price_snapshot',
        'line_amount',
        'inspection_disposition',
        'inventory_action',
        'inventory_applied_at',
    ];

    protected $casts = [
        'unit_price_snapshot' => 'decimal:2',
        'line_amount' => 'decimal:2',
        'inventory_applied_at' => 'datetime',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(OrderRefund::class, 'order_refund_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
