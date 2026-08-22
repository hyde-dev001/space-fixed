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

    public const RECOVERY_STATUS_UNRESOLVED = 'unresolved';
    public const RECOVERY_STATUS_IN_PROGRESS = 'in_progress';
    public const RECOVERY_STATUS_RESOLVED = 'resolved';
    public const RECOVERY_STATUS_SUPERSEDED = 'superseded';

    public const RECOVERY_RESPONSIBLE_FINANCE = 'finance';
    public const RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY = 'payment_recovery';
    public const RECOVERY_RESPONSIBLE_NONE = 'none';

    public const RECOVERY_OUTCOME_MANUAL_REFUND = 'manual_refund';
    public const RECOVERY_OUTCOME_REPLACEMENT_REFUND = 'replacement_refund';
    public const RECOVERY_OUTCOME_NO_RECOVERY_REQUIRED = 'no_recovery_required';
    public const RECOVERY_OUTCOME_AUTOMATIC_SUCCESS = 'automatic_success';

    public const RECOVERY_RESOLVER_TYPES = ['user', 'shop_owner', 'super_admin'];
    public const RECOVERY_OUTCOMES = [
        self::RECOVERY_OUTCOME_MANUAL_REFUND,
        self::RECOVERY_OUTCOME_REPLACEMENT_REFUND,
        self::RECOVERY_OUTCOME_NO_RECOVERY_REQUIRED,
        self::RECOVERY_OUTCOME_AUTOMATIC_SUCCESS,
    ];

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
        'requires_owner_approval',
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
        'recovery_status',
        'recovery_responsible_party',
        'recovery_assigned_at',
        'recovery_attempt_count',
        'recovery_last_attempted_at',
        'recovery_resolved_at',
        'recovery_resolved_by_type',
        'recovery_resolved_by_id',
        'recovery_resolution_outcome',
        'recovery_resolution_reason',
        'replacement_refund_id',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'requires_owner_approval' => 'boolean',
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
        'recovery_attempt_count' => 'integer',
        'recovery_assigned_at' => 'datetime',
        'recovery_last_attempted_at' => 'datetime',
        'recovery_resolved_at' => 'datetime',
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

    public function replacementRefund(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_refund_id');
    }
}
