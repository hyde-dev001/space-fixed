<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\InventoryItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class ShopOwnerInventoryReadService
{
    private const DEFAULT_PRODUCT_REORDER_LEVEL = 10;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(int $shopOwnerId, array $filters = []): Collection
    {
        $inventoryItems = $this->applyInventoryFilters(
            InventoryItem::query()
                ->with(['sizes', 'colorVariants', 'images'])
                ->where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true),
            $filters,
        )
            ->get()
            ->map(fn (InventoryItem $item): array => $this->mapInventoryItem($item));

        $products = $this->applyProductFilters(
            Product::query()
                ->with(['variants' => fn ($query) => $query->where('is_active', true)])
                ->where('products.shop_owner_id', $shopOwnerId)
                ->where('products.is_active', true)
                ->whereNotIn(
                    'products.id',
                    InventoryItem::query()
                        ->select('product_id')
                        ->where('shop_owner_id', $shopOwnerId)
                        ->where('is_active', true)
                        ->whereNotNull('product_id'),
                ),
            $filters,
        )
            ->get()
            ->map(fn (Product $product): array => $this->mapProduct($product));

        return $this->sortRows($inventoryItems->concat($products), $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $shopOwnerId, array $filters = []): LengthAwarePaginator
    {
        return $this->paginateRows($this->rows($shopOwnerId, $filters), $filters);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     */
    public function paginateRows(Collection $rows, array $filters = []): LengthAwarePaginator
    {
        $rows = $this->filterRows($rows, $filters);
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 200);
        $page = max((int) ($filters['page'] ?? 1), 1);

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function filterRows(Collection $rows, array $filters = []): Collection
    {
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $category = $filters['category'] ?? null;
        $brand = $filters['brand'] ?? null;
        $status = match ($filters['status'] ?? null) {
            'low_stock' => 'Low Stock',
            'out_of_stock' => 'Out of Stock',
            'in_stock' => 'In Stock',
            default => null,
        };

        return $this->sortRows(
            $rows->filter(function (array $row) use ($search, $category, $brand, $status): bool {
                if ($search !== '' && ! str_contains(strtolower((string) ($row['name'] ?? '')), $search)
                    && ! str_contains(strtolower((string) ($row['sku'] ?? '')), $search)) {
                    return false;
                }

                return ($category === null || $category === '' || $row['category'] === $category)
                    && ($brand === null || $brand === '' || $row['brand'] === $brand)
                    && ($status === null || $row['status'] === $status);
            }),
            $filters,
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int|float>
     */
    public function metricsForRows(Collection $rows): array
    {
        return [
            'total_items' => $rows->count(),
            'total_value' => round((float) $rows->sum('total_stock_value'), 2),
            'low_stock_count' => $rows->where('status', 'Low Stock')->count(),
            'out_of_stock_count' => $rows->where('status', 'Out of Stock')->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{categories: Collection<int, string>, series: array<int, array{name: string, data: Collection<int, int>}>}
     */
    public function chartDataForRows(Collection $rows): array
    {
        $items = $rows
            ->sortByDesc(fn (array $row): int => (int) ($row['available_quantity'] ?? 0))
            ->take(10)
            ->values();

        return [
            'categories' => $items->pluck('name'),
            'series' => [
                [
                    'name' => 'Available',
                    'data' => $items->pluck('available_quantity')->map(fn ($value): int => (int) $value),
                ],
                [
                    'name' => 'Reserved',
                    'data' => $items->pluck('reserved_quantity')->map(fn ($value): int => (int) $value),
                ],
                [
                    'name' => 'Reorder Level',
                    'data' => $items->pluck('reorder_level')->map(fn ($value): int => (int) $value),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapInventoryItem(InventoryItem $item): array
    {
        $this->sanitizeInventoryImages($item);

        return array_merge($item->toArray(), [
            'source_type' => 'inventory',
            'source_id' => (int) $item->getKey(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapProduct(Product $product): array
    {
        $availableQuantity = max(0, (int) $product->stock_quantity);
        $reorderLevel = self::DEFAULT_PRODUCT_REORDER_LEVEL;
        $sizes = $product->variants
            ->pluck('size')
            ->filter()
            ->map(fn ($size): string => (string) $size)
            ->unique()
            ->values();

        if ($sizes->isEmpty()) {
            $sizes = collect($product->sizes_available ?? [])
                ->filter()
                ->map(fn ($size): string => (string) $size)
                ->unique()
                ->values();
        }

        $additionalImages = collect($product->additional_images ?? [])
            ->filter(fn ($path): bool => is_string($path) && $this->publicFileExists($path))
            ->values()
            ->map(fn (string $path, int $index): array => [
                'id' => $index,
                'image_path' => $path,
                'is_thumbnail' => false,
                'sort_order' => $index,
            ])
            ->all();

        return [
            'id' => (int) $product->getKey(),
            'product_id' => null,
            'source_type' => 'product',
            'source_id' => (int) $product->getKey(),
            'shop_owner_id' => (int) $product->shop_owner_id,
            'name' => (string) $product->name,
            'sku' => (string) ($product->sku ?: 'PRODUCT-' . $product->getKey()),
            'category' => (string) ($product->category ?: 'shoes'),
            'brand' => $product->brand,
            'description' => $product->description,
            'notes' => null,
            'unit' => 'pairs',
            'available_quantity' => $availableQuantity,
            'reserved_quantity' => 0,
            'total_quantity' => $availableQuantity,
            'reorder_level' => $reorderLevel,
            'reorder_quantity' => 0,
            'price' => $product->price,
            'cost_price' => null,
            'weight' => $product->weight,
            'is_active' => true,
            'main_image' => $this->publicPathOrNull($product->main_image),
            'images' => $additionalImages,
            'sizes' => $sizes->map(fn (string $size): array => ['size' => $size])->all(),
            'color_variants' => collect($product->colors_available ?? [])
                ->filter()
                ->map(fn ($color): array => ['color_name' => (string) $color])
                ->values()
                ->all(),
            'status' => $this->stockStatus($availableQuantity, $reorderLevel),
            'total_stock_value' => 0,
            'created_at' => $product->created_at?->toISOString(),
            'updated_at' => $product->updated_at?->toISOString(),
            'last_updated' => $product->updated_at?->toISOString(),
        ];
    }

    private function stockStatus(int $availableQuantity, int $reorderLevel): string
    {
        if ($availableQuantity <= 0) {
            return 'Out of Stock';
        }

        return $availableQuantity <= $reorderLevel ? 'Low Stock' : 'In Stock';
    }

    private function sanitizeInventoryImages(InventoryItem $item): void
    {
        if ($item->main_image && ! $this->publicFileExists($item->main_image)) {
            $item->main_image = null;
        }

        if ($item->relationLoaded('images')) {
            $item->setRelation(
                'images',
                $item->images
                    ->filter(fn ($image): bool => $image->image_path && $this->publicFileExists($image->image_path))
                    ->values(),
            );
        }

        if ($item->relationLoaded('colorVariants')) {
            $item->colorVariants->each(function ($variant): void {
                if ($variant->relationLoaded('images')) {
                    $variant->setRelation(
                        'images',
                        $variant->images
                            ->filter(fn ($image): bool => $image->image_path && $this->publicFileExists($image->image_path))
                            ->values(),
                    );
                }
            });
        }
    }

    private function publicPathOrNull(?string $path): ?string
    {
        return $path && $this->publicFileExists($path) ? $path : null;
    }

    private function publicFileExists(string $path): bool
    {
        return Storage::disk('public')->exists(ltrim($path, '/'));
    }

    private function applyInventoryFilters(Builder $query, array $filters): Builder
    {
        return $this->applyFilters($query, $filters, 'available_quantity', true);
    }

    private function applyProductFilters(Builder $query, array $filters): Builder
    {
        return $this->applyFilters($query, $filters, 'stock_quantity', false);
    }

    private function applyFilters(Builder $query, array $filters, string $quantityColumn, bool $inventory): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere('sku', 'like', '%' . $search . '%');
            });
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['brand'])) {
            $query->where('brand', $filters['brand']);
        }

        $status = $filters['status'] ?? null;
        if ($status === 'low_stock') {
            $query->where($quantityColumn, '>', 0);
            if ($inventory) {
                $query->whereColumn($quantityColumn, '<=', 'reorder_level');
            } else {
                $query->where($quantityColumn, '<=', self::DEFAULT_PRODUCT_REORDER_LEVEL);
            }
        } elseif ($status === 'out_of_stock') {
            $query->where($quantityColumn, 0);
        } elseif ($status === 'in_stock') {
            if ($inventory) {
                $query->whereColumn($quantityColumn, '>', 'reorder_level');
            } else {
                $query->where($quantityColumn, '>', self::DEFAULT_PRODUCT_REORDER_LEVEL);
            }
        }

        return $query;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function sortRows(Collection $rows, array $filters): Collection
    {
        $sortBy = (string) ($filters['sort_by'] ?? 'name');
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $sortKey = match ($sortBy) {
            'stock_level', 'available_quantity' => 'available_quantity',
            'sku' => 'sku',
            'brand' => 'brand',
            'updated_at' => 'updated_at',
            default => 'name',
        };

        $sorted = $rows->sortBy(
            fn (array $row): mixed => is_string($row[$sortKey] ?? null)
                ? strtolower((string) $row[$sortKey])
                : ($row[$sortKey] ?? 0),
            SORT_NATURAL,
        );

        return ($sortOrder === 'desc' ? $sorted->reverse() : $sorted)->values();
    }
}
