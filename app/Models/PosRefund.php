<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;

class PosRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'refund_no',
        'shop_owner_id',
        'source_transaction_id',
        'module_type',
        'module_reference_id',
        'workflow_source',
        'request_type',
        'requested_amount',
        'approved_amount',
        'reason_code',
        'reason_notes',
        'status',
        'finance_status',
        'shop_owner_status',
        'repairer_status',
        'repairer_assessment_note',
        'repairer_reviewed_by',
        'repairer_reviewed_at',
        'evidence_snapshot',
        'preferred_return_channel',
        'preferred_return_account_name',
        'preferred_return_account_ref',
        'customer_payout_consent',
        'execution_mode',
        'execution_channel',
        'execution_reference',
        'execution_amount',
        'execution_proof_urls',
        'execution_notes',
        'paymongo_payment_id',
        'paymongo_payment_ids',
        'paymongo_refund_id',
        'paymongo_refund_ids',
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
        'repairer_reviewed_at' => 'datetime',
        'evidence_snapshot' => 'array',
        'customer_payout_consent' => 'boolean',
        'execution_amount' => 'decimal:2',
        'execution_proof_urls' => 'array',
        'paymongo_payment_ids' => 'array',
        'paymongo_refund_ids' => 'array',
    ];

    public function sourceTransaction(): BelongsTo
    {
        return $this->belongsTo(PosTransaction::class, 'source_transaction_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PosRefundLine::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PosRefundItem::class);
    }

    public function legs(): HasMany
    {
        return $this->hasMany(PosRefundLeg::class, 'pos_refund_id');
    }

    public function repairRequest(): BelongsTo
    {
        return $this->belongsTo(RepairRequest::class, 'module_reference_id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
