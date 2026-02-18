<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Notification;

class RepairRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'customer_name',
        'email',
        'phone',
        'shoe_type',
        'brand',
        'description',
        'shop_owner_id',
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
        'paymongo_link_id',
        'paymongo_payment_id',
        'payment_link_created_at',
        'payment_completed_at',
        'payment_status',
        'status',
        'delivery_method',
        'pickup_address',
        'scheduled_dropoff_date',
        'customer_confirmed_at',
        'is_high_value',
        'requires_owner_approval',
        'repairer_rejection_reason',
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
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'received_at' => 'datetime',
        'assigned_at' => 'datetime',
        'last_reassigned_at' => 'datetime',
        'scheduled_dropoff_date' => 'datetime',
        'customer_confirmed_at' => 'datetime',
        'repairer_rejected_at' => 'datetime',
        'manager_reviewed_at' => 'datetime',
        'owner_reviewed_at' => 'datetime',
        'payment_link_created_at' => 'datetime',
        'payment_completed_at' => 'datetime',
        'total' => 'decimal:2',
        'is_high_value' => 'boolean',
        'requires_owner_approval' => 'boolean',
        'reassignment_count' => 'integer',
        'images' => 'array',
        'pickup_address' => 'array',
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

    public function shopOwner()
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
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

    protected static function booted(): void
    {
        static::updated(function (RepairRequest $repair): void {
            if (!$repair->wasChanged('status') || !$repair->user_id) {
                return;
            }

            $status = (string) $repair->status;
            if ($status === 'new_request') {
                return;
            }

            $statusLabel = str_replace('_', ' ', $status);

            Notification::create([
                'user_id' => $repair->user_id,
                'type' => 'repair_status_update',
                'title' => 'Repair Status Updated',
                'message' => "Repair {$repair->request_id} is now {$statusLabel}.",
                'data' => [
                    'repair_id' => $repair->id,
                    'request_id' => $repair->request_id,
                    'status' => $status,
                ],
                'action_url' => '/my-repairs',
                'shop_id' => $repair->shop_owner_id,
            ]);
        });
    }
}
