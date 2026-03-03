<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'po_number',
        'pr_id',
        'shop_owner_id',
        'supplier_id',
        'product_name',
        'inventory_item_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'expected_delivery_date',
        'actual_delivery_date',
        'payment_terms',
        'status',
        'cancellation_reason',
        'ordered_by',
        'ordered_date',
        'confirmed_by',
        'confirmed_date',
        'delivered_by',
        'delivered_date',
        'completed_by',
        'completed_date',
        'notes',
    ];

    protected $casts = [
        'expected_delivery_date' => 'date',
        'actual_delivery_date' => 'date',
        'ordered_date' => 'datetime',
        'confirmed_date' => 'datetime',
        'delivered_date' => 'datetime',
        'completed_date' => 'datetime',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'quantity' => 'integer',
    ];

    protected $appends = [
        'status_label',
        'is_overdue',
        'days_until_delivery',
    ];

    // Relationships

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'pr_id');
    }

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function orderer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    // Scopes

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeInTransit(Builder $query): Builder
    {
        return $query->where('status', 'in_transit');
    }

    public function scopeDelivered(Builder $query): Builder
    {
        return $query->where('status', 'delivered');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['sent', 'confirmed', 'in_transit']);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('expected_delivery_date', '<', now())
                     ->whereNotIn('status', ['delivered', 'completed', 'cancelled']);
    }

    public function scopeByShopOwner(Builder $query, int $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    // Accessors

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'draft' => 'Draft',
            'sent' => 'Sent',
            'confirmed' => 'Confirmed',
            'in_transit' => 'In Transit',
            'delivered' => 'Delivered',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => ucfirst($this->status),
        };
    }

    public function getIsOverdueAttribute(): bool
    {
        if (!$this->expected_delivery_date) {
            return false;
        }

        if (in_array($this->status, ['delivered', 'completed', 'cancelled'])) {
            return false;
        }

        return $this->expected_delivery_date->isPast();
    }

    public function getDaysUntilDeliveryAttribute(): ?int
    {
        if (!$this->expected_delivery_date) {
            return null;
        }

        if (in_array($this->status, ['delivered', 'completed', 'cancelled'])) {
            return null;
        }

        return now()->diffInDays($this->expected_delivery_date, false);
    }

    // Methods

    public function sendToSupplier(): bool
    {
        if ($this->status !== 'draft') {
            return false;
        }

        $this->status = 'sent';
        return $this->save();
    }

    public function markAsConfirmed(int $userId): bool
    {
        if ($this->status !== 'sent') {
            return false;
        }

        $this->status = 'confirmed';
        $this->confirmed_by = $userId;
        $this->confirmed_date = now();
        return $this->save();
    }

    public function markAsInTransit(int $userId): bool
    {
        if ($this->status !== 'confirmed') {
            return false;
        }

        $this->status = 'in_transit';
        return $this->save();
    }

    public function markAsDelivered(int $userId, ?string $actualDate = null): bool
    {
        if (!in_array($this->status, ['confirmed', 'in_transit'])) {
            return false;
        }

        $this->status = 'delivered';
        $this->delivered_by = $userId;
        $this->delivered_date = now();
        $this->actual_delivery_date = $actualDate ? $actualDate : now();
        return $this->save();
    }

    public function markAsCompleted(int $userId): bool
    {
        if ($this->status !== 'delivered') {
            return false;
        }

        $this->status = 'completed';
        $this->completed_by = $userId;
        $this->completed_date = now();
        return $this->save();
    }

    public function cancel(int $userId, string $reason): bool
    {
        if (in_array($this->status, ['completed', 'cancelled'])) {
            return false;
        }

        $this->status = 'cancelled';
        $this->cancellation_reason = $reason;
        return $this->save();
    }

    public function canProgressStatus(): bool
    {
        return !in_array($this->status, ['completed', 'cancelled']);
    }

    public function getNextStatus(): ?string
    {
        return match($this->status) {
            'draft' => 'sent',
            'sent' => 'confirmed',
            'confirmed' => 'in_transit',
            'in_transit' => 'delivered',
            'delivered' => 'completed',
            default => null,
        };
    }

    public function updateInventoryOnDelivery(): bool
    {
        if (!$this->inventory_item_id || $this->status !== 'delivered') {
            return false;
        }

        $inventoryItem = $this->inventoryItem;
        if ($inventoryItem) {
            $inventoryItem->incrementStock(
                $this->quantity,
                'stock_in',
                "Delivered from PO: {$this->po_number}"
            );
            return true;
        }

        return false;
    }
}
