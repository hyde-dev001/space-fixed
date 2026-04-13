<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RepairWarrantyClaim extends Model
{
    use HasFactory;

    public const STATUS_PENDING_REPAIRER = 'pending_repairer';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'claim_no',
        'original_repair_request_id',
        'approved_repair_request_id',
        'customer_user_id',
        'shop_owner_id',
        'repair_handler_user_id',
        'handler_source',
        'status',
        'reason_code',
        'reason_details',
        'same_issue_confirmation',
        'evidence_media',
        'preferred_return_method',
        'shipping_cost_bearer',
        'source_channel',
        'warranty_started_at_snapshot',
        'warranty_expires_at_snapshot',
        'reviewed_by_repairer_id',
        'reviewed_at',
        'rejection_reason',
        'approved_once_guard',
        'created_by',
    ];

    protected $casts = [
        'same_issue_confirmation' => 'boolean',
        'evidence_media' => 'array',
        'warranty_started_at_snapshot' => 'datetime',
        'warranty_expires_at_snapshot' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_once_guard' => 'integer',
        'created_by' => 'integer',
    ];

    public function originalRepair()
    {
        return $this->belongsTo(RepairRequest::class, 'original_repair_request_id');
    }

    public function approvedRepair()
    {
        return $this->belongsTo(RepairRequest::class, 'approved_repair_request_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function shopOwner()
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    public function repairHandler()
    {
        return $this->belongsTo(User::class, 'repair_handler_user_id');
    }

    public function reviewedByRepairer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_repairer_id');
    }
}
