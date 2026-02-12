<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    use HasFactory;

    protected $table = 'approvals';

    protected $fillable = [
        'shop_owner_id',
        'approvable_type',
        'approvable_id',
        'reference',
        'description',
        'amount',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'current_level',
        'total_levels',
        'status',
        'comments',
        'metadata'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'current_level' => 'integer',
        'total_levels' => 'integer'
    ];

    /**
     * Get the user who requested this approval
     */
    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Get the user who reviewed this approval
     */
    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Get the shop owner
     */
    public function shopOwner()
    {
        return $this->belongsTo(User::class, 'shop_owner_id');
    }

    /**
     * Get the approvable model (polymorphic relation)
     */
    public function approvable()
    {
        return $this->morphTo();
    }

    /**
     * Get the approvable type for reference
     */
    public function approvableType()
    {
        return $this->morphTo('approvable');
    }

    /**
     * Get the approval history
     */
    public function history()
    {
        return $this->hasMany(ApprovalHistory::class);
    }

    /**
     * Scope to get pending approvals
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to get approved approvals
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope to get rejected approvals
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}
