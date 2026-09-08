<?php

namespace App\Services;

use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryReplenishmentService
{
    /**
     * Return only real inventory records that own effective replenishment settings.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function effectiveTargets(InventoryItem $item): Collection
    {
        $item->loadMissing(['sizes.colorVariant', 'colorVariants.sizes']);

        $targets = collect();

        foreach ($item->sizes as $size) {
            $targets->push($this->sizeTarget($size, $size->colorVariant));
        }

        foreach ($item->colorVariants as $color) {
            if ($color->sizes->isEmpty()) {
                $targets->push($this->colorTarget($color));
            }
        }

        if ($targets->isEmpty()) {
            $targets->push($this->itemTarget($item));
        }

        return $targets;
    }

    /**
     * Update explicit actual target records atomically.
     *
     * @param array<int, array{type:string,id:int|string,auto_stock_request_enabled:bool|int|string,reorder_level:int|string,reorder_quantity:int|string}> $targets
     */
    public function updateSettings(InventoryItem $item, array $targets): void
    {
        $normalizedTargets = collect($targets)->map(function (array $target): array {
            return [
                'type' => (string) ($target['type'] ?? ''),
                'id' => (int) ($target['id'] ?? 0),
                'auto_stock_request_enabled' => filter_var(
                    $target['auto_stock_request_enabled'],
                    FILTER_VALIDATE_BOOLEAN,
                ),
                'reorder_level' => (int) $target['reorder_level'],
                'reorder_quantity' => (int) $target['reorder_quantity'],
            ];
        });

        if ($normalizedTargets->isEmpty()) {
            throw ValidationException::withMessages(['targets' => 'At least one target is required.']);
        }

        $keys = $normalizedTargets->map(fn (array $target): string => $target['type'] . ':' . $target['id']);
        if ($keys->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['targets' => 'Duplicate replenishment targets are not allowed.']);
        }

        DB::transaction(function () use ($item, $normalizedTargets): void {
            $lockedItem = InventoryItem::query()
                ->where('shop_owner_id', $item->shop_owner_id)
                ->whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();

            $orderedTargets = $normalizedTargets
                ->sortBy(fn (array $target): string => sprintf('%d:%010d', $this->targetTypeOrder($target['type']), $target['id']))
                ->values();

            foreach ($orderedTargets as $target) {
                $this->updateTarget($lockedItem, $target);
            }
        }, 3);
    }

    /** @return array<string, mixed> */
    private function itemTarget(InventoryItem $item): array
    {
        return [
            'type' => 'item',
            'id' => (int) $item->id,
            'inventory_item_id' => (int) $item->id,
            'inventory_color_variant_id' => null,
            'inventory_size_id' => null,
            'quantity' => (int) $item->available_quantity,
            'auto_stock_request_enabled' => (bool) $item->auto_stock_request_enabled,
            'reorder_level' => (int) $item->reorder_level,
            'reorder_quantity' => (int) $item->reorder_quantity,
            'requested_color' => null,
            'requested_size' => null,
            'color_name' => null,
            'size' => null,
            'size_system' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function colorTarget(InventoryColorVariant $color): array
    {
        return [
            'type' => 'color',
            'id' => (int) $color->id,
            'inventory_item_id' => (int) $color->inventory_item_id,
            'inventory_color_variant_id' => (int) $color->id,
            'inventory_size_id' => null,
            'quantity' => (int) $color->quantity,
            'auto_stock_request_enabled' => (bool) $color->auto_stock_request_enabled,
            'reorder_level' => (int) $color->reorder_level,
            'reorder_quantity' => (int) $color->reorder_quantity,
            'requested_color' => InventoryVariantIdentity::normalizeColor($color->color_name),
            'requested_size' => null,
            'color_name' => $color->color_name,
            'size' => null,
            'size_system' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function sizeTarget(InventorySize $size, ?InventoryColorVariant $color): array
    {
        return [
            'type' => 'size',
            'id' => (int) $size->id,
            'inventory_item_id' => (int) $size->inventory_item_id,
            'inventory_color_variant_id' => $size->inventory_color_variant_id ? (int) $size->inventory_color_variant_id : null,
            'inventory_size_id' => (int) $size->id,
            'quantity' => (int) $size->quantity,
            'auto_stock_request_enabled' => (bool) $size->auto_stock_request_enabled,
            'reorder_level' => (int) $size->reorder_level,
            'reorder_quantity' => (int) $size->reorder_quantity,
            'requested_color' => InventoryVariantIdentity::normalizeColor($color?->color_name),
            'requested_size' => InventoryVariantIdentity::fromSize($size),
            'color_name' => $color?->color_name,
            'size' => $size->size,
            'size_system' => $size->size_system,
        ];
    }

    private function targetTypeOrder(string $type): int
    {
        return match ($type) {
            'item' => 1,
            'color' => 2,
            'size' => 3,
            default => 99,
        };
    }

    /** @param array<string, mixed> $target */
    private function updateTarget(InventoryItem $item, array $target): void
    {
        if ($target['reorder_level'] < 0) {
            throw ValidationException::withMessages(['targets' => 'Reorder level cannot be negative.']);
        }

        if ($target['reorder_quantity'] < 1) {
            throw ValidationException::withMessages(['targets' => 'Quantity to request must be greater than zero.']);
        }

        $values = [
            'auto_stock_request_enabled' => $target['auto_stock_request_enabled'],
            'reorder_level' => $target['reorder_level'],
            'reorder_quantity' => $target['reorder_quantity'],
        ];

        switch ($target['type']) {
            case 'item':
                if ((int) $target['id'] !== (int) $item->id || $this->hasChildTargets($item)) {
                    throw ValidationException::withMessages(['targets' => 'The item target is not valid for this inventory shape.']);
                }
                $item->update($values);
                return;

            case 'color':
                $color = InventoryColorVariant::query()
                    ->where('inventory_item_id', $item->id)
                    ->whereKey($target['id'])
                    ->lockForUpdate()
                    ->first();
                if (! $color || $color->sizes()->exists()) {
                    throw ValidationException::withMessages(['targets' => 'The color target is not valid for this inventory item.']);
                }
                $color->update($values);
                return;

            case 'size':
                $size = InventorySize::query()
                    ->where('inventory_item_id', $item->id)
                    ->whereKey($target['id'])
                    ->lockForUpdate()
                    ->first();
                if (! $size) {
                    throw ValidationException::withMessages(['targets' => 'The size target is not valid for this inventory item.']);
                }
                $size->update($values);
                return;

            default:
                throw ValidationException::withMessages(['targets' => 'Unsupported replenishment target type.']);
        }
    }

    private function hasChildTargets(InventoryItem $item): bool
    {
        return $item->sizes()->exists() || $item->colorVariants()->exists();
    }
}
