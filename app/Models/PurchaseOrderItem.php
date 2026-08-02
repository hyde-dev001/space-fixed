<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id', 'purchase_request_id', 'inventory_item_id', 'product_name',
        'requested_size', 'requested_color', 'ordered_quantity', 'unit_cost', 'line_total',
        'quantity_multiplier', 'eligible_size_ids', 'source',
    ];

    protected $casts = [
        'ordered_quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'line_total' => 'decimal:2',
        'quantity_multiplier' => 'integer',
        'eligible_size_ids' => 'array',
    ];

    protected $appends = ['accepted_quantity', 'remaining_quantity'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function receiptItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceiptItem::class);
    }

    public function acceptedQuantity(): int
    {
        return (int) $this->receiptItems()
            ->whereHas('receipt', fn ($query) => $query->where('status', 'posted'))
            ->sum('accepted_quantity');
    }

    public function remainingQuantity(): int
    {
        return max(0, $this->ordered_quantity - $this->acceptedQuantity());
    }

    public function getAcceptedQuantityAttribute(): int
    {
        return $this->acceptedQuantity();
    }

    public function getRemainingQuantityAttribute(): int
    {
        return $this->remainingQuantity();
    }

    /** @return array{eligible_size_ids: array<int>, quantity_multiplier: int} */
    public static function snapshotSizeTargets(?InventoryItem $inventoryItem, ?string $requestedSize, ?string $requestedColor): array
    {
        if (!$inventoryItem) {
            return ['eligible_size_ids' => [], 'quantity_multiplier' => 1];
        }

        $query = $inventoryItem->sizes()->orderBy('inventory_sizes.id');
        $color = trim((string) $requestedColor);
        if ($color !== '') {
            $query->whereHas('colorVariant', fn ($colorQuery) =>
                $colorQuery->whereRaw('LOWER(color_name) = ?', [strtolower($color)]));
        }

        $size = trim((string) $requestedSize);
        if ($size !== '') {
            $system = 'US';
            if (preg_match('/^(US|UK|EU|AU|CN)\s*[:\-]?\s*(.+)$/i', $size, $matches)) {
                $system = strtoupper($matches[1]);
                $size = trim($matches[2]);
            }
            $query->where('size_system', $system)->where('size', $size);
        }

        $ids = $query->pluck('inventory_sizes.id')->map(fn ($id) => (int) $id)->all();

        return [
            'eligible_size_ids' => $ids,
            'quantity_multiplier' => trim((string) $requestedSize) === '' ? max(1, count($ids)) : 1,
        ];
    }
}
