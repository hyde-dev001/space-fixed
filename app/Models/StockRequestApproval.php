<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class StockRequestApproval extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'request_number',
        'shop_owner_id',
        'inventory_item_id',
        'product_name',
        'sku_code',
        'quantity_needed',
        'priority',
        'status',
        'requested_by',
        'requested_date',
        'approved_by',
        'approved_date',
        'notes',
        'approval_notes',
        'rejection_reason',
    ];

    protected $casts = [
        'requested_date' => 'datetime',
        'approved_date' => 'datetime',
        'quantity_needed' => 'integer',
    ];

    protected $appends = [
        'priority_label',
        'status_label',
        'days_pending',
    ];

    // Relationships

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Scopes

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', 'accepted');
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', 'rejected');
    }

    public function scopeNeedsDetails(Builder $query): Builder
    {
        return $query->where('status', 'needs_details');
    }

    public function scopeByPriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    public function scopeByShopOwner(Builder $query, int $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    // Accessors

    public function getPriorityLabelAttribute(): string
    {
        return ucfirst($this->priority);
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Pending',
            'accepted' => 'Accepted',
            'rejected' => 'Rejected',
            'needs_details' => 'Needs Details',
            default => ucfirst($this->status),
        };
    }

    public function getDaysPendingAttribute(): int
    {
        if (!$this->requested_date) {
            return 0;
        }

        if (in_array($this->status, ['accepted', 'rejected'])) {
            $endDate = $this->approved_date ?? now();
            return $this->requested_date->diffInDays($endDate);
        }

        return $this->requested_date->diffInDays(now());
    }

    // Methods

    public function approve(int $userId, ?string $notes = null): bool
    {
        if (!in_array($this->status, ['pending', 'needs_details'])) {
            return false;
        }

        $this->status = 'accepted';
        $this->approved_by = $userId;
        $this->approved_date = now();
        
        if ($notes) {
            $this->approval_notes = $notes;
        }

        return $this->save();
    }

    public function reject(int $userId, string $reason): bool
    {
        if (!in_array($this->status, ['pending', 'needs_details'])) {
            return false;
        }

        $this->status = 'rejected';
        $this->approved_by = $userId;
        $this->approved_date = now();
        $this->rejection_reason = $reason;

        return $this->save();
    }

    public function requestDetails(int $userId, string $notes): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->status = 'needs_details';
        $this->approved_by = $userId;
        $this->approved_date = now();
        $this->approval_notes = $notes;

        return $this->save();
    }

    public function canBeApproved(): bool
    {
        return in_array($this->status, ['pending', 'needs_details']);
    }

    public function canBeRejected(): bool
    {
        return in_array($this->status, ['pending', 'needs_details']);
    }
}
