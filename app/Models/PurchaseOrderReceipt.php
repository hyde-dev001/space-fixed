<?php

namespace App\Models;

use App\Models\Finance\Expense;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseOrderReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id', 'shop_owner_id', 'source', 'status', 'idempotency_key',
        'payload_hash', 'received_by', 'received_at', 'notes', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceiptItem::class);
    }

    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class, 'procurement_receipt_id');
    }
}
