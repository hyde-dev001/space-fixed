<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseOrderReceiptItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_receipt_id', 'purchase_order_item_id', 'received_quantity',
        'defective_quantity', 'accepted_quantity', 'inventory_effects', 'replacement_for_adjustment_id',
    ];

    protected $casts = [
        'received_quantity' => 'integer',
        'defective_quantity' => 'integer',
        'accepted_quantity' => 'integer',
        'inventory_effects' => 'array',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderReceipt::class, 'purchase_order_receipt_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function stockMovement(): HasOne
    {
        return $this->hasOne(StockMovement::class);
    }

    public function replacementForAdjustment(): BelongsTo
    {
        return $this->belongsTo(SupplierAdjustment::class, 'replacement_for_adjustment_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(SupplierAdjustment::class, 'purchase_order_receipt_item_id');
    }
}
