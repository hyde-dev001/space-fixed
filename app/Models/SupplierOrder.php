<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class SupplierOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'shop_owner_id',
        'supplier_id',
        'po_number',
        'status',
        'order_date',
        'expected_delivery_date',
        'actual_delivery_date',
        'total_amount',
        'currency',
        'payment_status',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'actual_delivery_date' => 'date',
        'total_amount' => 'decimal:2',
    ];

    protected $appends = ['days_to_delivery', 'is_overdue'];

    /**
     * Get the shop owner
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /**
     * Get the supplier
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the user who created the order
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the order
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get all order items
     */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierOrderItem::class);
    }

    /**
     * Scope to active orders
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotIn('status', ['completed', 'cancelled']);
    }

    /**
     * Scope by status
     */
    public function scopeByStatus(Builder $query, string $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Scope to overdue orders
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('expected_delivery_date', '<', now()->toDateString())
            ->whereNotIn('status', ['delivered', 'completed', 'cancelled']);
    }

    /**
     * Scope to orders due today
     */
    public function scopeDueToday(Builder $query): void
    {
        $query->where('expected_delivery_date', now()->toDateString())
            ->whereNotIn('status', ['delivered', 'completed', 'cancelled']);
    }

    /**
     * Scope to orders arriving soon
     */
    public function scopeArrivingSoon(Builder $query, int $days = 3): void
    {
        $query->whereBetween('expected_delivery_date', [
                now()->toDateString(),
                now()->addDays($days)->toDateString()
            ])
            ->whereNotIn('status', ['delivered', 'completed', 'cancelled']);
    }

    /**
     * Get days to delivery
     */
    public function getDaysToDeliveryAttribute(): ?int
    {
        if (!$this->expected_delivery_date || in_array($this->status, ['delivered', 'completed', 'cancelled'])) {
            return null;
        }

        return now()->startOfDay()->diffInDays($this->expected_delivery_date, false);
    }

    /**
     * Check if order is overdue
     */
    public function getIsOverdueAttribute(): bool
    {
        if (!$this->expected_delivery_date || in_array($this->status, ['delivered', 'completed', 'cancelled'])) {
            return false;
        }

        return $this->expected_delivery_date < now()->toDateString();
    }

    /**
     * Mark order as confirmed
     */
    public function markAsConfirmed(): void
    {
        $this->update(['status' => 'confirmed']);
    }

    /**
     * Mark order as in transit
     */
    public function markAsInTransit(): void
    {
        $this->update(['status' => 'in_transit']);
    }

    /**
     * Mark order as delivered
     */
    public function markAsDelivered(?string $deliveryDate = null): void
    {
        $this->update([
            'status' => 'delivered',
            'actual_delivery_date' => $deliveryDate ?? now()->toDateString(),
        ]);
    }

    /**
     * Mark order as completed
     */
    public function markAsCompleted(): void
    {
        $this->update(['status' => 'completed']);
    }

    /**
     * Cancel the order
     */
    public function cancel(?string $reason = null): void
    {
        $this->update([
            'status' => 'cancelled',
            'remarks' => $reason ? ($this->remarks ? $this->remarks . "\n\nCancellation: " . $reason : "Cancelled: " . $reason) : $this->remarks,
        ]);
    }
}
