<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_no',
        'idempotency_key',
        'phase_lock_key',
        'shop_owner_id',
        'module_type',
        'module_reference_id',
        'customer_type',
        'customer_id',
        'walk_in_name',
        'walk_in_phone',
        'walk_in_email',
        'due_type',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'paid_amount',
        'status',
        'paid_at',
        'voided_at',
        'created_by',
        'approved_by',
        'metadata',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function paymentLines()
    {
        return $this->hasMany(PosPaymentLine::class);
    }

    public function receipt()
    {
        return $this->hasOne(PosReceipt::class);
    }

    public function sourceOrder()
    {
        return $this->belongsTo(Order::class, 'module_reference_id');
    }

    public function repairRequest()
    {
        return $this->belongsTo(RepairRequest::class, 'module_reference_id');
    }

    public function refunds()
    {
        return $this->hasMany(PosRefund::class, 'source_transaction_id');
    }
}
