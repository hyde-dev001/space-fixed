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
        return $this->availableForSelection($this->resolveSelection($product, [
            'size' => $itemSize,
            'color' => $itemColor,
        ]));
    }

    /**
     * Resolve the catalog variant and linked inventory target as one selection.
     * Client-supplied IDs are accepted only when they belong to this product's linked inventory
     * and match the submitted catalog color/size.
     *
     * @return array{inventory_item:?InventoryItem,color:?InventoryColorVariant,size:?InventorySize,variant:?ProductVariant,requested_size:string,requested_color:string}
     */
    public function resolveSelection(Product $product, array $item, bool $forUpdate = false): array
    {
        $variant = $this->resolveProductVariant($product, $item, $forUpdate);
        $options = isset($item['options'])
            ? (is_string($item['options']) ? json_decode($item['options'], true) : $item['options'])
            : [];
        $options = is_array($options) ? $options : [];
        $requestedSize = trim((string) ($item['size'] ?? $options['size'] ?? $variant?->size ?? ''));
        $requestedColor = trim((string) ($item['color'] ?? $options['color'] ?? $variant?->color ?? ''));
        $inventoryItem = InventoryItem::query()
            ->where('product_id', $product->id)
            ->where('shop_owner_id', $product->shop_owner_id)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->first();

        if (! $inventoryItem) {
            return [
                'inventory_item' => null,
                'color' => null,
                'size' => null,
                'variant' => $variant,
                'requested_size' => $requestedSize,
                'requested_color' => $requestedColor,
            ];
        }

        $colorId = (int) ($item['inventory_color_variant_id'] ?? 0);
        $sizeId = (int) ($item['inventory_size_id'] ?? 0);
        $color = null;
        $size = null;

        if ($sizeId > 0) {
            $size = InventorySize::query()
                ->where('inventory_item_id', $inventoryItem->id)
                ->whereKey($sizeId)
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())
                ->first();

            if (! $size) {
                $this->invalidSelection('inventory_size_id', 'Selected inventory size is not valid for this product.');
            }

            $sizeColor = $size->inventory_color_variant_id
                ? InventoryColorVariant::query()
                    ->where('inventory_item_id', $inventoryItem->id)
                    ->whereKey($size->inventory_color_variant_id)
                    ->when($forUpdate, fn ($query) => $query->lockForUpdate())
                    ->first()
                : null;

            if ($size->inventory_color_variant_id && ! $sizeColor) {
                $this->invalidSelection('inventory_size_id', 'Selected inventory size has an invalid color target.');
            }

            if ($colorId > 0 && (int) $size->inventory_color_variant_id !== $colorId) {
                $this->invalidSelection('inventory_color_variant_id', 'Selected inventory color does not match the selected size.');
            }

            if ($requestedColor !== '' && InventoryVariantIdentity::normalizeColor($requestedColor) !== InventoryVariantIdentity::normalizeColor($sizeColor?->color_name)) {
                $this->invalidSelection('color', 'Selected inventory color does not match the catalog variant.');
            }

            if ($requestedSize !== '' && InventoryVariantIdentity::normalizeSize($requestedSize) !== InventoryVariantIdentity::fromSize($size)) {
                $this->invalidSelection('size', 'Selected inventory size does not match the catalog variant.');
            }

            $color = $sizeColor;
        } elseif ($colorId > 0) {
            $color = InventoryColorVariant::query()
                ->where('inventory_item_id', $inventoryItem->id)
                ->whereKey($colorId)
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())
                ->first();

            if (! $color) {
                $this->invalidSelection('inventory_color_variant_id', 'Selected inventory color is not valid for this product.');
            }
        } elseif ($requestedColor !== '') {
            $color = InventoryColorVariant::query()
                ->where('inventory_item_id', $inventoryItem->id)
                ->whereRaw('LOWER(color_name) = ?', [InventoryVariantIdentity::normalizeColor($requestedColor)])
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())
                ->first();
        }

        if ($color && $requestedColor !== '' && InventoryVariantIdentity::normalizeColor($requestedColor) !== InventoryVariantIdentity::normalizeColor($color->color_name)) {
            $this->invalidSelection('inventory_color_variant_id', 'Selected inventory color does not match the catalog variant.');
        }

        if ($color && $requestedSize !== '' && ! $size) {
            $size = $this->resolveSizeRow((int) $inventoryItem->id, (int) $color->id, $requestedSize, $forUpdate);
        }

        if ($size && $requestedSize !== '' && InventoryVariantIdentity::normalizeSize($requestedSize) !== InventoryVariantIdentity::fromSize($size)) {
            $this->invalidSelection('inventory_size_id', 'Selected inventory size does not match the catalog variant.');
        }

        return [
            'inventory_item' => $inventoryItem,
            'color' => $color,
            'size' => $size,
            'variant' => $variant,
            'requested_size' => $requestedSize,
            'requested_color' => $requestedColor,
        ];
    }

    /** @param array<string, mixed> $selection */
    public function availableForSelection(array $selection): ?int
    {
        if (! $selection['inventory_item']) {
            return null;
        }

        if ($selection['requested_color'] !== '' && ! $selection['color']) {
            return 0;
        }

        if ($selection['requested_size'] !== '' && $selection['color'] && ! $selection['size'] && $selection['color']->sizes()->exists()) {
            return 0;
        }

        if ($selection['size']) {
            return (int) $selection['size']->quantity;
        }

        if ($selection['color']) {
            return (int) $selection['color']->quantity;
        }

        return (int) $selection['inventory_item']->available_quantity;
    }

    private function resolveProductVariant(Product $product, array $item, bool $forUpdate): ?ProductVariant
    {
        $variantId = (int) ($item['variant_id'] ?? 0);
        $requestedSize = trim((string) ($item['size'] ?? ''));
        $requestedColor = trim((string) ($item['color'] ?? ''));
        $query = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->when($forUpdate, fn ($builder) => $builder->lockForUpdate());

        if ($variantId > 0) {
            $variant = (clone $query)->whereKey($variantId)->first();
            if (! $variant) {
                $this->invalidSelection('variant_id', 'Selected catalog variant is not valid for this product.');
            }
        } elseif ($requestedSize !== '' && $requestedColor !== '') {
            $normalizedSize = InventoryVariantIdentity::normalizeSize($requestedSize);
            $normalizedColor = InventoryVariantIdentity::normalizeColor($requestedColor);
            $variant = $query->get()->first(function (ProductVariant $candidate) use ($normalizedSize, $normalizedColor): bool {
                return InventoryVariantIdentity::normalizeSize($candidate->size) === $normalizedSize
                    && InventoryVariantIdentity::normalizeColor($candidate->color) === $normalizedColor;
            });
        } else {
            $variant = null;
        }

        if ($variant && $requestedSize !== '' && InventoryVariantIdentity::normalizeSize($variant->size) !== InventoryVariantIdentity::normalizeSize($requestedSize)) {
            $this->invalidSelection('variant_id', 'Selected catalog variant does not match the submitted size.');
        }

        if ($variant && $requestedColor !== '' && InventoryVariantIdentity::normalizeColor($variant->color) !== InventoryVariantIdentity::normalizeColor($requestedColor)) {
            $this->invalidSelection('variant_id', 'Selected catalog variant does not match the submitted color.');
        }

        return $variant;
    }

    private function invalidSelection(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    public function deduct(Product $product, array $item, ?ProductVariant $resolvedVariant, int $performedBy, string $referenceType = 'order', ?int $referenceId = null, bool $decrementVariant = false): bool
    {
        $options = isset($item['options'])
            ? (is_string($item['options']) ? json_decode($item['options'], true) : $item['options'])
            : [];
        $options = is_array($options) ? $options : [];
        $selectionItem = $item;
        $selectionItem['size'] ??= $options['size'] ?? null;
        $selectionItem['color'] ??= $options['color'] ?? null;
        $selection = $this->resolveSelection($product, $selectionItem, true);
        $inventoryItem = $selection['inventory_item'];

        if (! $inventoryItem) {
            return false;
        }

        $qty = (int) ($item['qty'] ?? 0);
        if ($qty <= 0) {
            return true;
        }

        $inventoryColorVariant = $selection['color'];
        $sizeRow = $selection['size'];
        if ($selection['requested_color'] !== '' && ! $inventoryColorVariant) {
            $this->invalidSelection('color', 'Selected inventory color is not available for this product.');
        }

        if ($selection['requested_size'] !== '' && $inventoryColorVariant && ! $sizeRow && $inventoryColorVariant->sizes()->exists()) {
            $this->invalidSelection('size', 'Selected inventory size is not available for this color.');
        }

        $quantityBefore = (int) $inventoryItem->available_quantity;
        $didSpecificDeduction = false;

        if ($sizeRow) {
            if ((int) $sizeRow->quantity < $qty) {
                throw ValidationException::withMessages(['stock' => ['Insufficient linked inventory size stock.']]);
            }

            $sizeRow->decrement('quantity', $qty);
            if ($inventoryColorVariant) {
                $inventoryColorVariant->quantity = (int) InventorySize::query()
                    ->where('inventory_item_id', $inventoryItem->id)
                    ->where('inventory_color_variant_id', $inventoryColorVariant->id)
                    ->sum('quantity');
                $inventoryColorVariant->save();
            }
            $didSpecificDeduction = true;
        } elseif ($inventoryColorVariant) {
            if ((int) $inventoryColorVariant->quantity < $qty) {
                throw ValidationException::withMessages(['stock' => ['Insufficient linked inventory color stock.']]);
            }

            $inventoryColorVariant->decrement('quantity', $qty);
            $didSpecificDeduction = true;
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
            if ((int) $inventoryItem->available_quantity < $qty) {
                throw ValidationException::withMessages(['stock' => ['Insufficient linked inventory stock.']]);
            }

            $newTotalQty = (int) $inventoryItem->available_quantity - $qty;
        }

        if ($newTotalQty < 0) {
            throw ValidationException::withMessages(['stock' => ['Insufficient linked inventory stock.']]);
        }

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

    private function resolveSizeRow(int $inventoryItemId, int $colorVariantId, string $rawSize, bool $forUpdate = true): ?InventorySize
    {
        $sizeValue = trim($rawSize);
        $sizeSystem = 'US';
        $hasExplicitSystem = false;

        if (preg_match('/^(US|UK|EU|AU|CN)\s*[:\-]?\s*(.+)$/i', $sizeValue, $matches)) {
            $sizeSystem = strtoupper((string) ($matches[1] ?? 'US'));
            $sizeValue = trim((string) ($matches[2] ?? ''));
            $hasExplicitSystem = true;
        }

        if ($sizeValue === '') {
            return null;
        }

        $query = InventorySize::query()
            ->where('inventory_item_id', $inventoryItemId)
            ->where('inventory_color_variant_id', $colorVariantId)
            ->where('size', $sizeValue);

        if ($hasExplicitSystem) {
            $preferred = (clone $query)
                ->where('size_system', $sizeSystem)
                ->when($forUpdate, fn ($builder) => $builder->lockForUpdate())
                ->first();

            if ($preferred) {
                return $preferred;
            }
        }

        return $query
            ->orderByRaw("CASE WHEN size_system = 'US' THEN 0 ELSE 1 END")
            ->when($forUpdate, fn ($builder) => $builder->lockForUpdate())
            ->first();
    }
}
