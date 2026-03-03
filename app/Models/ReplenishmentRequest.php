<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class ReplenishmentRequest extends Model
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
        'reviewed_by',
        'reviewed_date',
        'notes',
        'response_notes',
    ];

    protected $casts = [
        'requested_date' => 'datetime',
        'reviewed_date' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
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
            $endDate = $this->reviewed_date ?? now();
            return $this->requested_date->diffInDays($endDate);
        }

        return $this->requested_date->diffInDays(now());
    }

    // Methods

    public function accept(int $userId, ?string $notes = null): bool
    {
        if (!in_array($this->status, ['pending', 'needs_details'])) {
            return false;
        }

        $this->status = 'accepted';
        $this->reviewed_by = $userId;
        $this->reviewed_date = now();
        
        if ($notes) {
            $this->response_notes = $notes;
        }

        return $this->save();
    }

    public function reject(int $userId, ?string $notes = null): bool
    {
        if (!in_array($this->status, ['pending', 'needs_details'])) {
            return false;
        }

        $this->status = 'rejected';
        $this->reviewed_by = $userId;
        $this->reviewed_date = now();
        
        if ($notes) {
            $this->response_notes = $notes;
        }

        return $this->save();
    }

    public function requestDetails(int $userId, string $notes): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->status = 'needs_details';
        $this->reviewed_by = $userId;
        $this->reviewed_date = now();
        $this->response_notes = $notes;

        return $this->save();
    }

    public function canBeAccepted(): bool
    {
        return in_array($this->status, ['pending', 'needs_details']);
    }

    public function canBeRejected(): bool
    {
        return in_array($this->status, ['pending', 'needs_details']);
    }
}
