<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use App\Models\PosTransaction;

class RepairRequest extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'request_id',
        'customer_name',
        'email',
        'phone',
        'shoe_type',
        'brand',
        'description',
        'shop_owner_id',
        'repair_package_id',
        'user_id',
        'assigned_repairer_id',
        'assigned_manager_id',
        'assigned_at',
        'assignment_method',
        'assigned_by',
        'assignment_notes',
        'reassignment_count',
        'last_reassigned_at',
        'conversation_id',
        'images',
        'total',
        'package_price',
        'add_ons_total',
        'final_total',
        'included_services_snapshot',
        'add_on_services_snapshot',
        'pricing_breakdown',
        'paymongo_link_id',
        'paymongo_payment_id',
        'paymongo_payment_ids',
        'payment_link_created_at',
        'payment_expires_at',
        'payment_completed_at',
        'payment_status',
        'payment_failed_at',
        'payment_failure_reason',
        'payment_expired_at',
        'payment_enabled',
        'payment_enabled_at',
        'payment_enabled_by',
        'payment_policy',
        'payment_policy_snapshot',
        'payment_status_derived',
        'total_paid_amount',
        'total_refunded_amount',
        'latest_pos_transaction_id',
        'manual_pos_queue_enabled',
        'is_warranty_job',
        'parent_repair_request_id',
        'warranty_sequence',
        'warranty_claim_id',
        'billing_mode',
        'warranty_display_alias',
        'repair_handler_user_id',
        'handler_source',
        'pickup_enabled',
        'pickup_enabled_at',
        'pickup_enabled_by',
        'status',
        'delivery_method',
        'pickup_address',
        'intake_delivery_method',
        'intake_address',
        'return_delivery_method',
        'return_address',
        'scheduled_dropoff_date',
        'customer_confirmed_at',
        'is_high_value',
        'requires_owner_approval',
        'repairer_rejection_reason',
        'repairer_rejection_reason_category',
        'repairer_rejected_at',
        'repairer_rejected_by',
        'manager_review_notes',
        'manager_decision',
        'manager_reviewed_at',
        'manager_reviewed_by',
        'owner_approval_notes',
        'owner_decision',
        'owner_reviewed_at',
        'owner_reviewed_by',
        'started_at',
        'completed_at',
        'picked_up_at',
        'received_at',
        'awaiting_parts_notes',
        'awaiting_parts_since',
        'pickup_instructions',
        'tracking_number',
        'carrier_company',
        'carrier_name',
        'carrier_phone',
        'tracking_link',
        'estimated_delivery_date',
        'shipped_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'received_at' => 'datetime',
        'awaiting_parts_since' => 'datetime',
        'shipped_at' => 'datetime',
        'estimated_delivery_date' => 'date',
        'assigned_at' => 'datetime',
        'last_reassigned_at' => 'datetime',
        'scheduled_dropoff_date' => 'datetime',
        'customer_confirmed_at' => 'datetime',
        'repairer_rejected_at' => 'datetime',
        'manager_reviewed_at' => 'datetime',
        'owner_reviewed_at' => 'datetime',
        'payment_link_created_at' => 'datetime',
        'payment_expires_at' => 'datetime',
        'payment_completed_at' => 'datetime',
        'payment_failed_at' => 'datetime',
        'payment_expired_at' => 'datetime',
        'payment_enabled_at' => 'datetime',
        'pickup_enabled_at' => 'datetime',
        'total' => 'decimal:2',
        'total_paid_amount' => 'decimal:2',
        'total_refunded_amount' => 'decimal:2',
        'package_price' => 'decimal:2',
        'add_ons_total' => 'decimal:2',
        'final_total' => 'decimal:2',
        'is_high_value' => 'boolean',
        'requires_owner_approval' => 'boolean',
        'payment_enabled' => 'boolean',
        'pickup_enabled' => 'boolean',
        'manual_pos_queue_enabled' => 'boolean',
        'is_warranty_job' => 'boolean',
        'reassignment_count' => 'integer',
        'warranty_sequence' => 'integer',
        'images' => 'array',
        'pickup_address' => 'array',
        'intake_address' => 'array',
        'return_address' => 'array',
        'included_services_snapshot' => 'array',
        'add_on_services_snapshot' => 'array',
        'pricing_breakdown' => 'array',
        'paymongo_payment_ids' => 'array',
    ];

    /**
     * Get the customer who submitted the request
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the assigned repairer
     */
    public function repairer()
    {
        return $this->belongsTo(User::class, 'assigned_repairer_id');
    }

    /**
     * Get the assigned manager
     */
    public function manager()
    {
        return $this->belongsTo(User::class, 'assigned_manager_id');
    }

    /**
     * Get the user who rejected as repairer
     */
    public function repairerRejectedBy()
    {
        return $this->belongsTo(User::class, 'repairer_rejected_by');
    }

    /**
     * Get the manager who reviewed
     */
    public function managerReviewedBy()
    {
        return $this->belongsTo(User::class, 'manager_reviewed_by');
    }

    /**
     * Get the owner who reviewed
     */
    public function ownerReviewedBy()
    {
        return $this->belongsTo(ShopOwner::class, 'owner_reviewed_by');
    }

    /**
     * Get the associated conversation
     */
    public function conversation()
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function services()
    {
        return $this->belongsToMany(RepairService::class, 'repair_request_service');
    }

    public function repairPackage()
    {
        return $this->belongsTo(RepairPackage::class, 'repair_package_id');
    }

    public function shopOwner()
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    public function posTransactions()
    {
        return $this->hasMany(PosTransaction::class, 'module_reference_id')
            ->where('module_type', 'repair');
    }

    public function latestPosTransaction()
    {
        return $this->belongsTo(PosTransaction::class, 'latest_pos_transaction_id');
    }

    public function parentRepairRequest()
    {
        return $this->belongsTo(self::class, 'parent_repair_request_id');
    }

    public function warrantyJobs()
    {
        return $this->hasMany(self::class, 'parent_repair_request_id');
    }

    public function warrantyClaim()
    {
        return $this->belongsTo(RepairWarrantyClaim::class, 'warranty_claim_id');
    }

    public function warrantyClaims()
    {
        return $this->hasMany(RepairWarrantyClaim::class, 'original_repair_request_id');
    }

    public function latestWarrantyClaim()
    {
        return $this->hasOne(RepairWarrantyClaim::class, 'original_repair_request_id')->latestOfMany('id');
    }

    public function approvedWarrantyClaim()
    {
        return $this->hasOne(RepairWarrantyClaim::class, 'approved_repair_request_id');
    }

    public function repairHandler()
    {
        return $this->belongsTo(User::class, 'repair_handler_user_id');
    }

    public function materialUsages()
    {
        return $this->hasMany(RepairMaterialUsage::class);
    }

    public function materialPlanItems()
    {
        return $this->hasMany(RepairMaterialPlanItem::class);
    }

    /**
     * Scope for filtering by customer
     */
    public function scopeForCustomer($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for filtering by repairer
     */
    public function scopeForRepairer($query, $repairerId)
    {
        return $query->where('assigned_repairer_id', $repairerId);
    }

    /**
     * Scope for high value requests
     */
    public function scopeHighValue($query)
    {
        return $query->where('is_high_value', true);
    }

    /**
     * Scope for requests pending owner approval
     */
    public function scopePendingOwnerApproval($query)
    {
        return $query->where('status', 'pending_owner_approval');
    }

    /**
     * Scope for rejected requests pending manager review
     */
    public function scopePendingManagerReview($query)
    {
        return $query->where('status', 'repairer_rejected');
    }

    public function scopePayable($query)
    {
        return $query
            ->where('payment_status', 'pending')
            ->whereNull('payment_expired_at');
    }

    public function scopeExpiredPayable($query)
    {
        return $query->payable()
            ->whereNotNull('payment_expires_at')
            ->where('payment_expires_at', '<=', now());
    }

    /**
     * Activity Log Configuration
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'payment_status', 'assigned_repairer_id', 'total', 'customer_name'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "Repair Request {$eventName}");
    }
}
