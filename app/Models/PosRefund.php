<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'refund_no',
        'shop_owner_id',
        'source_transaction_id',
        'module_type',
        'module_reference_id',
        'request_type',
        'requested_amount',
        'approved_amount',
        'reason_code',
        'reason_notes',
        'status',
        'execution_mode',
        'execution_notes',
        'paymongo_payment_id',
        'paymongo_refund_id',
        'requested_by',
        'approved_by',
        'executed_by',
        'requested_at',
        'approved_at',
        'executed_at',
        'failed_at',
        'failure_reason',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function sourceTransaction(): BelongsTo
    {
        return $this->belongsTo(PosTransaction::class, 'source_transaction_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PosRefundLine::class);
    }

    public function repairRequest(): BelongsTo
    {
        return $this->belongsTo(RepairRequest::class, 'module_reference_id');
    }
}
