<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderRefund extends Model
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
        'staff_return_tracking_number',
        'staff_return_carrier',
        'staff_return_rider_name',
        'staff_return_rider_phone',
        'staff_return_tracking_link',
        'staff_return_shipped_at',
        'return_arranged_by_staff_id',
        'return_arranged_by_staff_at',
        'return_source',
        'refund_executed_at',
        'payment_gateway',
        'paymongo_payment_id',
        'paymongo_refund_id',
        'amount',
        'currency',
        'requested_refund_method',
        'reason_code',
        'reason_note',
        'other_reason_note',
        'evidence_media',
        'rejection_reason',
        'idempotency_key',
        'failure_reason',
        'requested_at',
        'approved_at',
        'refunded_at',
        'failed_at',
        'processed_by',
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
        'amount' => 'decimal:2',
        'evidence_media' => 'array',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'shop_owner_approved_at' => 'datetime',
        'finance_approved_at' => 'datetime',
        'return_confirmed_at' => 'datetime',
        'customer_return_shipped_at' => 'datetime',
        'staff_return_shipped_at' => 'datetime',
        'return_arranged_by_staff_at' => 'datetime',
        'refund_executed_at' => 'datetime',
        'refunded_at' => 'datetime',
        'failed_at' => 'datetime',
        'recovery_attempt_count' => 'integer',
        'recovery_assigned_at' => 'datetime',
        'recovery_last_attempted_at' => 'datetime',
        'recovery_resolved_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(OrderRefundItem::class);
    }

    public function replacementRefund(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_refund_id');
    }
}
