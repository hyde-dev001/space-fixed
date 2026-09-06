<?php

namespace App\Services;

use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Validation\ValidationException;

final class InventoryCheckoutService
{
    public function availableForCheckout(Product $product, ?string $itemSize, ?string $itemColor): ?int
    {
        $inventoryItem = InventoryItem::query()->where('product_id', $product->id)->first();
        if (! $inventoryItem) {
            return null;
        }
        if (! $itemSize || ! $itemColor) {
            return (int) $inventoryItem->available_quantity;
        }

        $color = InventoryColorVariant::query()
            ->where('inventory_item_id', $inventoryItem->id)
            ->whereRaw('LOWER(color_name) = ?', [strtolower(trim($itemColor))])
            ->first();
        if (! $color) {
            return 0;
        }

        $size = $this->resolveSizeRow((int) $inventoryItem->id, (int) $color->id, $itemSize, false);

        return $size ? (int) $size->quantity : (int) $color->quantity;
    }

    public function deduct(Product $product, array $item, ?ProductVariant $resolvedVariant, int $performedBy, string $referenceType = 'order', ?int $referenceId = null, bool $decrementVariant = false): bool
    {
        $inventoryItem = InventoryItem::query()
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if (! $inventoryItem) {
            return false;
        }

        $qty = (int) ($item['qty'] ?? 0);
        if ($qty <= 0) {
            return true;
        }

        $options = isset($item['options'])
            ? (is_string($item['options']) ? json_decode($item['options'], true) : $item['options'])
            : [];
        $itemSize = $item['size'] ?? null;
        $itemColor = $item['color'] ?? ($options['color'] ?? null);
        $quantityBefore = (int) $inventoryItem->available_quantity;
        $didSpecificDeduction = false;
        $inventoryColorVariant = null;
        $sizeRow = null;

        if ($itemSize && $itemColor) {
            $inventoryColorVariant = InventoryColorVariant::query()
                ->where('inventory_item_id', $inventoryItem->id)
                ->whereRaw('LOWER(color_name) = ?', [strtolower(trim((string) $itemColor))])
                ->lockForUpdate()
                ->first();

            if ($inventoryColorVariant) {
                $sizeRow = $this->resolveSizeRow(
                    (int) $inventoryItem->id,
                    (int) $inventoryColorVariant->id,
                    (string) $itemSize
                );
                if ($sizeRow) {
                    if ($sizeRow->quantity < $qty) throw ValidationException::withMessages(['stock' => ['Insufficient linked inventory size stock.']]);
                    $sizeRow->decrement('quantity', $qty);
                    $inventoryColorVariant->quantity = (int) InventorySize::query()
                        ->where('inventory_item_id', $inventoryItem->id)
                        ->where('inventory_color_variant_id', $inventoryColorVariant->id)
                        ->sum('quantity');
                    $inventoryColorVariant->save();
                    $didSpecificDeduction = true;
                } else {
                    if ($inventoryColorVariant->quantity < $qty) throw ValidationException::withMessages(['stock' => ['Insufficient linked inventory color stock.']]);
                    $inventoryColorVariant->decrement('quantity', $qty);
                    $didSpecificDeduction = true;
                }
            }
        }
        $newTotalQty = (int) InventoryColorVariant::query()
            ->where('inventory_item_id', $inventoryItem->id)
            ->sum('quantity');
        if ($newTotalQty === 0) {
            $newTotalQty = (int) InventorySize::query()
                ->where('inventory_item_id', $inventoryItem->id)
                ->whereNull('inventory_color_variant_id')
                ->sum('quantity');
        }
        if (! $didSpecificDeduction) {
            if ($inventoryItem->available_quantity < $qty) throw ValidationException::withMessages(['stock' => ['Insufficient linked inventory stock.']]);
            $newTotalQty = (int) $inventoryItem->available_quantity - $qty;
        }
        if ($newTotalQty < 0) throw ValidationException::withMessages(['stock' => ['Insufficient linked inventory stock.']]);
        $inventoryItem->update(['available_quantity' => $newTotalQty]);
        StockMovement::create([
            'inventory_item_id' => $inventoryItem->id,
            'movement_type' => 'stock_out',
            'quantity_change' => -$qty,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $newTotalQty,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'notes' => 'Checkout deduction',
            'performed_by' => $performedBy,
            'performed_at' => now(),
        ]);
        $product->update(['stock_quantity' => $newTotalQty]);
        if ($decrementVariant && $resolvedVariant) {
            $resolvedVariant->decrement('quantity', $qty);
        }
        return true;
    }

    private function resolveSizeRow(int $inventoryItemId, int $colorVariantId, string $rawSize): ?InventorySize
    {
        $size = trim($rawSize);
        if (preg_match('/^(US|UK|EU|AU|CN)\s*[:\-]?\s*(.+)$/i', $size, $matches)) {
            $size = trim((string) ($matches[2] ?? ''));
        }
        if ($size === '') {
            return null;
        }
        return InventorySize::query()
            ->where('inventory_item_id', $inventoryItemId)
            ->where('inventory_color_variant_id', $colorVariantId)
            ->where('size', $size)
            ->lockForUpdate()
            ->first();
    }
}
