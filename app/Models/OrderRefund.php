<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRefund extends Model
{
    protected $fillable = [
        'order_id',
        'customer_id',
        'shop_owner_id',
        'flow_type',
        'status',
        'shop_owner_status',
        'shop_owner_approved_at',
        'shop_owner_approved_by',
        'finance_status',
        'finance_approved_at',
        'finance_approved_by',
        'return_status',
        'return_confirmed_at',
        'return_confirmed_by_staff_id',
        'return_notes',
        'customer_return_tracking_number',
        'customer_return_carrier',
        'customer_return_rider_name',
        'customer_return_rider_phone',
        'customer_return_tracking_link',
        'customer_return_shipped_at',
        'refund_executed_at',
        'payment_gateway',
        'paymongo_payment_id',
        'paymongo_refund_id',
        'amount',
        'currency',
        'requested_refund_method',
        'reason_code',
        'reason_note',
        'evidence_media',
        'rejection_reason',
        'idempotency_key',
        'failure_reason',
        'requested_at',
        'approved_at',
        'refunded_at',
        'failed_at',
        'processed_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'evidence_media' => 'array',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'shop_owner_approved_at' => 'datetime',
        'finance_approved_at' => 'datetime',
        'return_confirmed_at' => 'datetime',
        'customer_return_shipped_at' => 'datetime',
        'refund_executed_at' => 'datetime',
        'refunded_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
