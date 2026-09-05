<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class PurchaseOrder extends Model
{
    use HasFactory, SoftDeletes;

    private const ACTIVE_RECEIVING_STATUSES = ['sent', 'confirmed', 'in_transit', 'partially_received'];
    private const RECEIVING_STATUSES = ['in_transit', 'partially_received'];
    private const CANCELLABLE_STATUSES = ['draft', 'sent', 'confirmed'];

    protected $fillable = [
        'po_number',
        'pr_id',
        'shop_owner_id',
        'supplier_id',
        'product_name',
        'inventory_item_id',
        'requested_size',
        'requested_color',
        'quantity',
        'received_quantity',
        'defective_quantity',
        'unit_cost',
        'total_cost',
        'expected_delivery_date',
        'actual_delivery_date',
        'payment_terms',
        'status',
        'is_historical',
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
        'received_quantity' => 'integer',
        'defective_quantity' => 'integer',
        'is_historical' => 'boolean',
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

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceipt::class);
    }

    public function activeReceipts(): HasMany
    {
        return $this->receipts()->where('status', 'posted');
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
        return $query->whereIn('status', self::ACTIVE_RECEIVING_STATUSES);
    }

    public function scopeAwaitingClosure(Builder $query): Builder
    {
        return $query->where('status', 'delivered');
    }

    public function scopeCancellable(Builder $query): Builder
    {
        return $query->whereIn('status', self::CANCELLABLE_STATUSES);
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
            'partially_received' => 'Partially Received',
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

        return today()->diffInDays($this->expected_delivery_date, false);
    }

    // Methods

    public function sendToSupplier(): bool
    {
        $this->requireStatus('draft');

        $this->status = 'sent';
        return $this->save();
    }

    public function markAsConfirmed(int $userId): bool
    {
        $this->requireStatus('sent');

        $this->status = 'confirmed';
        $this->confirmed_by = $userId;
        $this->confirmed_date = now();
        return $this->save();
    }

    public function markAsInTransit(int $userId): bool
    {
        $this->requireStatus('confirmed');

        $this->status = 'in_transit';
        return $this->save();
    }

    public function markAsDeliveredFromReceipts(int $userId, ?string $actualDate = null): bool
    {
        if (! $this->isReceiving()) {
            throw ValidationException::withMessages(['status' => 'Only an in-transit purchase order can become delivered.']);
        }
        if ($this->items()->get()->contains(fn (PurchaseOrderItem $item) => $item->remainingQuantity() > 0)) {
            throw ValidationException::withMessages(['status' => 'All purchase-order items must be fully received before delivery.']);
        }

        $this->status = 'delivered';
        $this->delivered_by = $userId;
        $this->delivered_date = now();
        $this->actual_delivery_date = $actualDate ? $actualDate : now();
        return $this->save();
    }

    public function markAsCompleted(int $userId): bool
    {
        $this->requireStatus('delivered');
        $items = $this->items()->get();
        if ($items->isEmpty() || $items->contains(fn (PurchaseOrderItem $item) => $item->remainingQuantity() > 0)) {
            throw ValidationException::withMessages(['status' => 'All purchase-order items must be fully received before completion.']);
        }

        $this->status = 'completed';
        $this->completed_by = $userId;
        $this->completed_date = now();
        return $this->save();
    }

    public function cancel(int $userId, string $reason): bool
    {
        if (! $this->isCancellableState()) {
            throw ValidationException::withMessages(['status' => 'Purchase order cannot be cancelled in its current state.']);
        }
        if ($this->receipts()->where('status', 'posted')->exists()) {
            throw ValidationException::withMessages(['status' => 'A purchase order with a posted receipt cannot be cancelled.']);
        }

        $this->status = 'cancelled';
        $this->cancellation_reason = $reason;
        return $this->save();
    }

    public function isCancellableState(): bool
    {
        return in_array($this->status, self::CANCELLABLE_STATUSES, true);
    }

    public function isReceiving(): bool
    {
        return in_array($this->status, self::RECEIVING_STATUSES, true);
    }

    public function isAwaitingClosure(): bool
    {
        return $this->status === 'delivered';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
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
            'delivered' => 'completed',
            default => null,
        };
    }

    private function requireStatus(string $expected): void
    {
        if ($this->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => "Purchase order must be {$expected} before this transition.",
            ]);
        }
    }

    public function updateInventoryOnDelivery(): bool
    {
        if (!$this->inventory_item_id || $this->status !== 'delivered') {
            return false;
        }

        $inventoryItem = $this->inventoryItem;
        if (!$inventoryItem) {
            return false;
        }

        // Net accepted = received - defective (fall back to full quantity for older POs)
        $netAccepted = ($this->received_quantity ?? $this->quantity) - ($this->defective_quantity ?? 0);

        if ($netAccepted <= 0) {
            return true; // All items defective — nothing goes to inventory
        }

        $receivedQuantity = ($this->received_quantity ?? $this->quantity);
        $defectiveQuantity = ($this->defective_quantity ?? 0);
        $addedToParent = $netAccepted;
        $sizeUpdateContext = null;

        // If a specific size was requested, increment only that size.
        if ($this->requested_size) {
            $requestedSizeRaw = trim((string) $this->requested_size);
            $requestedSizeSystem = 'US';
            $requestedSizeValue = $requestedSizeRaw;
            $requestedColor = trim((string) ($this->requested_color ?? ''));

            if (preg_match('/^(US|UK|EU|AU|CN)\s*[:\-]?\s*(.+)$/i', $requestedSizeRaw, $matches)) {
                $requestedSizeSystem = strtoupper($matches[1]);
                $requestedSizeValue = trim((string) $matches[2]);
            }

            $targetColorVariant = null;
            if ($requestedColor !== '') {
                $targetColorVariant = $inventoryItem->colorVariants()
                    ->whereRaw('LOWER(color_name) = ?', [strtolower($requestedColor)])
                    ->first();
            }

            $sizeRowQuery = $inventoryItem->sizes()
                ->where('size', $requestedSizeValue)
                ->where('size_system', $requestedSizeSystem);

            if ($targetColorVariant) {
                $sizeRowQuery->where('inventory_color_variant_id', $targetColorVariant->id);
            }

            $sizeRow = $sizeRowQuery->first();

            if ($sizeRow) {
                $sizeRow->quantity += $netAccepted;
                $sizeRow->save();
            } else {
                // Size didn't exist yet — create it, scoped to color when available.
                $inventoryItem->sizes()->create([
                    'inventory_color_variant_id' => $targetColorVariant?->id,
                    'size'        => $requestedSizeValue,
                    'size_system' => $requestedSizeSystem,
                    'quantity'    => $netAccepted,
                ]);
            }

            if ($targetColorVariant) {
                $targetColorVariant->quantity += $netAccepted;
                $targetColorVariant->save();
                $sizeUpdateContext = "size {$requestedSizeSystem} {$requestedSizeValue}, color {$targetColorVariant->color_name}";
            } else {
                $sizeUpdateContext = "size {$requestedSizeSystem} {$requestedSizeValue}";
            }
        } else {
            $requestedColor = trim((string) ($this->requested_color ?? ''));

            // All sizes requested (blank requested_size): apply to every existing size row.
            $allSizeRowsQuery = $inventoryItem->sizes();
            $targetColorVariant = null;

            if ($requestedColor !== '') {
                $targetColorVariant = $inventoryItem->colorVariants()
                    ->whereRaw('LOWER(color_name) = ?', [strtolower($requestedColor)])
                    ->first();

                if ($targetColorVariant) {
                    $allSizeRowsQuery->where('inventory_color_variant_id', $targetColorVariant->id);
                }
            }

            $allSizeRows = $allSizeRowsQuery->get();

            if ($allSizeRows->isNotEmpty()) {
                foreach ($allSizeRows as $sizeRow) {
                    $sizeRow->quantity += $netAccepted;
                    $sizeRow->save();
                }

                $addedToParent = $netAccepted * $allSizeRows->count();
                $sizeUpdateContext = $targetColorVariant
                    ? "all sizes for color {$targetColorVariant->color_name} ({$allSizeRows->count()} rows)"
                    : "all sizes ({$allSizeRows->count()} rows)";
            } elseif ($targetColorVariant) {
                // No per-size rows for the chosen color yet; still keep color stock accurate.
                $targetColorVariant->quantity += $netAccepted;
                $targetColorVariant->save();
                $sizeUpdateContext = "color {$targetColorVariant->color_name}";
            }
        }

        // Increment the overall available_quantity on the parent item.
        $inventoryItem->incrementStock(
            $addedToParent,
            'stock_in',
            "Delivered from PO: {$this->po_number} (received: {$receivedQuantity}, defective: {$defectiveQuantity}, added: {$addedToParent}" . ($sizeUpdateContext ? ", {$sizeUpdateContext}" : '') . ")"
        );

        return true;
    }
}
