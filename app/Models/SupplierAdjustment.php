<?php

namespace App\Models;

use App\Models\Finance\ExpenseSettlement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class SupplierAdjustment extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    public const ISSUE_STAGE_RECEIVING_DEFECT = 'receiving_defect';
    public const ISSUE_STAGE_POST_PAYMENT = 'post_payment_issue';

    public const STATUS_REPORTED = 'reported';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_AWAITING_SUPPLIER = 'awaiting_supplier';
    public const STATUS_RESOLUTION_IN_PROGRESS = 'resolution_in_progress';
    public const STATUS_AWAITING_VERIFICATION = 'awaiting_verification';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    public const STATUS_RESOLVED = 'resolved';

    public const RESOLUTION_REPLACEMENT = 'replacement';
    public const RESOLUTION_REFUND = 'refund';
    public const RESOLUTION_SHORT_FULFILLMENT = 'short_fulfillment';

    public const REPLACEMENT_REQUESTED = 'requested';
    public const REPLACEMENT_SENT = 'sent';
    public const REPLACEMENT_ACCEPTED = 'accepted_by_supplier';
    public const REPLACEMENT_IN_TRANSIT = 'in_transit';
    public const REPLACEMENT_RECEIVED = 'received';
    public const REPLACEMENT_DECLINED = 'declined';

    public const RETURN_REQUIRED = 'required';
    public const RETURN_RELEASED = 'released';
    public const RETURN_RECEIVED_BY_SUPPLIER = 'received_by_supplier';
    public const RETURN_WAIVED = 'waived';

    public const REASON_CATEGORIES = [
        'manufacturing_defect',
        'damaged',
        'wrong_item',
        'incorrect_size_or_variant',
        'other',
    ];

    protected $fillable = [
        'shop_owner_id',
        'purchase_order_receipt_item_id',
        'idempotency_key',
        'issue_stage',
        'reported_quantity',
        'short_fulfillment_quantity',
        'unit_cost_snapshot',
        'reason_category',
        'inventory_notes',
        'status',
        'resolution',
        'replacement_status',
        'return_status',
        'procurement_notes',
        'decline_reason',
        'supplier_reference',
        'return_notes',
        'expected_refund_amount',
        'supplier_reported_refund_amount',
        'supplier_reported_refund_reference',
        'supplier_reported_refund_date',
        'reported_by',
        'reported_at',
        'reviewed_by',
        'reviewed_at',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'reported_quantity' => 'integer',
        'short_fulfillment_quantity' => 'integer',
        'unit_cost_snapshot' => 'decimal:2',
        'expected_refund_amount' => 'decimal:2',
        'supplier_reported_refund_amount' => 'decimal:2',
        'supplier_reported_refund_date' => 'date',
        'reported_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderReceiptItem::class, 'purchase_order_receipt_item_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function replacementReceiptItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceiptItem::class, 'replacement_for_adjustment_id');
    }

    public function refundSettlements(): HasMany
    {
        return $this->hasMany(ExpenseSettlement::class, 'supplier_adjustment_id')
            ->where('entry_type', ExpenseSettlement::ENTRY_SUPPLIER_REFUND);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('defect_evidence')->useDisk('local');
        $this->addMediaCollection('supplier_refund_proof')->useDisk('local');
        $this->addMediaCollection('finance_confirmation_proof')->useDisk('local');
    }
}
