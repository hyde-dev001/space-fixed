<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class PurchaseRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'pr_number',
        'shop_owner_id',
        'supplier_id',
        'product_name',
        'inventory_item_id',
        'requested_size',
        'quantity',
        'unit_cost',
        'total_cost',
        'priority',
        'justification',
        'status',
        'rejection_reason',
        'requested_by',
        'requested_date',
        'reviewed_by',
        'reviewed_date',
        'approved_by',
        'approved_date',
        'notes',
    ];

    protected $casts = [
        'requested_date' => 'datetime',
        'reviewed_date' => 'datetime',
        'approved_date' => 'datetime',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'quantity' => 'integer',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'pr_id');
    }

    // Scopes

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    public function scopePendingFinance(Builder $query): Builder
    {
        return $query->where('status', 'pending_finance');
    }

    public function scopePendingShopOwner(Builder $query): Builder
    {
        return $query->where('status', 'pending_shop_owner');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', 'rejected');
    }

    public function scopeByPriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    public function scopeByShopOwner(Builder $query, int $shopOwnerId): Builder
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    // Accessors

    public function getPriorityLabelAttribute(): string
    {
        return ucfirst($this->priority);
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'draft' => 'Draft',
            'pending_finance' => 'Pending Finance',
            'pending_shop_owner' => 'Pending Shop Owner',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => ucfirst($this->status),
        };
    }

    public function getDaysPendingAttribute(): int
    {
        if (!$this->requested_date) {
            return 0;
        }

        if (in_array($this->status, ['approved', 'rejected'])) {
            $endDate = $this->approved_date ?? $this->reviewed_date ?? now();
            return $this->requested_date->diffInDays($endDate);
        }

        return $this->requested_date->diffInDays(now());
    }

    // Methods

    public function submitToFinance(): bool
    {
        if ($this->status !== 'draft') {
            return false;
        }

        $this->status = 'pending_finance';
        return $this->save();
    }

    public function approve(int $userId, ?string $notes = null, ?string $role = null): bool
    {
        // Finance approval - moves to pending shop owner
        if ($this->status === 'pending_finance') {
            $this->status = 'pending_shop_owner';
            $this->reviewed_by = $userId;
            $this->reviewed_date = now();
            
            if ($notes) {
                $this->notes = ($this->notes ? $this->notes . "\n\n" : '') . "Finance: " . $notes;
            }

            return $this->save();
        }

        // Shop Owner approval - final approval
        if ($this->status === 'pending_shop_owner') {
            $this->status = 'approved';
            $this->approved_by = $userId;
            $this->approved_date = now();
            
            if ($notes) {
                $this->notes = ($this->notes ? $this->notes . "\n\n" : '') . "Shop Owner: " . $notes;
            }

            return $this->save();
        }

        return false;
    }

    public function reject(int $userId, string $reason): bool
    {
        if (!in_array($this->status, ['draft', 'pending_finance', 'pending_shop_owner'])) {
            return false;
        }

        $this->status = 'rejected';
        $this->reviewed_by = $userId;
        $this->reviewed_date = now();
        $this->rejection_reason = $reason;

        return $this->save();
    }

    public function convertToPurchaseOrder(array $data): ?PurchaseOrder
    {
        if ($this->status !== 'approved') {
            return null;
        }

        $po = PurchaseOrder::create([
            'pr_id' => $this->id,
            'shop_owner_id' => $this->shop_owner_id,
            'supplier_id' => $this->supplier_id,
            'product_name' => $this->product_name,
            'inventory_item_id' => $this->inventory_item_id,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'total_cost' => $this->total_cost,
            'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? 'Net 30',
            'ordered_by' => $data['ordered_by'],
            'ordered_date' => now(),
            'notes' => $data['notes'] ?? null,
        ]);

        return $po;
    }

    public function canBeApproved(): bool
    {
        return in_array($this->status, ['pending_finance', 'pending_shop_owner']);
    }

    public function canBeRejected(): bool
    {
        return in_array($this->status, ['draft', 'pending_finance', 'pending_shop_owner']);
    }

    public function canBeEdited(): bool
    {
        return $this->status === 'draft';
    }

    public function canBeDeleted(): bool
    {
        return $this->status === 'draft';
    }
}
