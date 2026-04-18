<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryColorVariant;
use App\Models\InventoryImage;
use App\Models\InventorySize;
use App\Models\StockMovement;
use App\Models\Product;
use App\Models\ProductColorVariant;
use App\Models\ProductColorVariantImage;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadInventoryController extends Controller
{
    private const CATEGORY_SHOES = 'shoes';
    private const CATEGORY_REPAIR_MATERIALS = 'repair_materials';
    private const SIZE_SYSTEMS = ['US', 'UK', 'EU', 'AU', 'CN'];

    /**
     * List uploaded inventory items
     */
    public function index(Request $request)
    {
        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }

        $allowedCategories = $this->allowedCategoriesForBusinessType(
            $this->resolveBusinessType($request)
        );
        
        $items = InventoryItem::with(['sizes', 'colorVariants.images', 'colorVariants.sizes', 'images'])
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('category', $allowedCategories)
            ->when($request->boolean('archived'), function ($query) {
                $query->onlyTrashed();
            })
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->when($request->category, function ($query, $category) use ($allowedCategories) {
                if (in_array($category, $allowedCategories, true)) {
                    $query->where('category', $category);
                    return;
                }

                $query->whereRaw('1 = 0');
            })
            ->when($request->boolean('available_for_product'), function ($query) {
                $query->where(function ($availabilityQuery) {
                    $availabilityQuery->whereNull('product_id')
                        ->orWhereDoesntHave('product');
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 20)
            ->withQueryString();

        $items->setCollection(
            $items->getCollection()->map(function (InventoryItem $item) {
                if ($item->main_image && !Storage::disk('public')->exists(ltrim($item->main_image, '/'))) {
                    $item->main_image = null;
                }

                if ($item->relationLoaded('images')) {
                    $item->setRelation(
                        'images',
                        $item->images
                            ->filter(fn ($image) => $image->image_path && Storage::disk('public')->exists(ltrim($image->image_path, '/')))
                            ->values()
                    );
                }

                if ($item->relationLoaded('colorVariants')) {
                    $item->colorVariants->each(function ($variant) {
                        if ($variant->relationLoaded('images')) {
                            $variant->setRelation(
                                'images',
                                $variant->images
                                    ->filter(fn ($image) => $image->image_path && Storage::disk('public')->exists(ltrim($image->image_path, '/')))
                                    ->values()
                            );
                        }
                    });
                }

                return $item;
            })
        );
        
        return response()->json($items);
    }
    
    /**
     * Create new inventory item
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:100|unique:inventory_items,sku',
            'category' => 'required|in:shoes,repair_materials',
            'brand' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'unit' => 'nullable|string|max:50',
            'available_quantity' => 'required|integer|min:1',
            'reorder_level' => 'nullable|integer|min:0',
            'reorder_quantity' => 'nullable|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'sizes' => 'nullable|array',
            'sizes.*.size' => 'required|string',
            'sizes.*.size_system' => 'nullable|in:US,UK,EU,AU,CN',
            'sizes.*.quantity' => 'required|integer|min:1',
            'color_variants' => 'nullable|array',
            'color_variants.*.color_name' => 'required|string',
            'color_variants.*.color_code' => 'nullable|string',
            'color_variants.*.quantity' => 'required|integer|min:1',
            'color_variants.*.sizes' => 'nullable|array',
            'color_variants.*.sizes.*.size' => 'required|string',
            'color_variants.*.sizes.*.size_system' => 'nullable|in:US,UK,EU,AU,CN',
            'color_variants.*.sizes.*.quantity' => 'required|integer|min:1',
            'color_variants.*.images' => 'nullable|array',
            'color_variants.*.images.*' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,avif|max:2048',
            'images' => 'nullable|array',
            'images.*' => 'file|mimes:jpeg,png,jpg,gif,webp,avif|max:2048'
        ]);

        if ($authorizationError = $this->authorizeCategoryForBusinessType($request, $validated['category'])) {
            return $authorizationError;
        }

        if ($validated['category'] === 'shoes') {
            $topLevelSizesCount = count($validated['sizes'] ?? []);
            $variantSizesCount = collect($validated['color_variants'] ?? [])->sum(function ($variant) {
                return count($variant['sizes'] ?? []);
            });

            if (($topLevelSizesCount + $variantSizesCount) === 0) {
                return response()->json([
                    'message' => 'At least one size with stock is required for shoes.'
                ], 422);
            }

            if (empty($validated['color_variants']) || count($validated['color_variants']) === 0) {
                return response()->json([
                    'message' => 'At least one color variant is required for shoes.'
                ], 422);
            }
        }

        if (!empty($validated['color_variants'])) {
            $seenColorIdentities = [];
            foreach ($validated['color_variants'] as $index => &$variantData) {
                $canonicalColorName = $this->canonicalizeColorName((string) ($variantData['color_name'] ?? ''));
                $colorIdentity = $this->normalizeColorIdentity($canonicalColorName);

                if (isset($seenColorIdentities[$colorIdentity])) {
                    return response()->json([
                        'message' => 'Duplicate color variants are not allowed.',
                        'errors' => [
                            "color_variants.{$index}.color_name" => [
                                'This combined color already exists in the same stock entry.',
                            ],
                        ],
                    ], 422);
                }

                $seenColorIdentities[$colorIdentity] = true;
                $variantData['color_name'] = $canonicalColorName;
            }
            unset($variantData);
        }

        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }

        $actorUserId = $this->resolveActorUserId($request);
        
        // Generate SKU if not provided
        if (empty($validated['sku'])) {
            $validated['sku'] = $this->generateSKU($validated['category'], $validated['name']);
        }
        
        DB::beginTransaction();
        try {
            // Create inventory item
            $item = InventoryItem::create([
                'shop_owner_id' => $shopOwnerId,
                'name' => $validated['name'],
                'sku' => $validated['sku'],
                'category' => $validated['category'],
                'brand' => $validated['brand'] ?? null,
                'description' => $validated['description'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'unit' => $validated['unit'] ?? 'pcs',
                'available_quantity' => $validated['available_quantity'],
                'reorder_level' => $validated['reorder_level'] ?? 10,
                'reorder_quantity' => $validated['reorder_quantity'] ?? 50,
                'price' => $validated['price'] ?? null,
                'cost_price' => $validated['cost_price'] ?? null,
                'weight' => $validated['weight'] ?? null,
                'is_active' => true,
                'created_by' => $actorUserId
            ]);
            
            // Create color variants if provided
            if (!empty($validated['color_variants'])) {
                foreach ($validated['color_variants'] as $idx => $variantData) {
                    $variantSizes = $variantData['sizes'] ?? [];
                    $variantQuantity = !empty($variantSizes)
                        ? collect($variantSizes)->sum('quantity')
                        : (int) $variantData['quantity'];

                    $variant = InventoryColorVariant::create([
                        'inventory_item_id' => $item->id,
                        'color_name' => $variantData['color_name'],
                        'color_code' => $variantData['color_code'] ?? null,
                        'quantity' => $variantQuantity
                    ]);

                    if (!empty($variantSizes)) {
                        foreach ($variantSizes as $sizeData) {
                            $sizeValue = trim((string) $sizeData['size']);
                            $sizeSystem = $this->normalizeSizeSystem($sizeData['size_system'] ?? null);

                            $existingSize = InventorySize::where('inventory_item_id', $item->id)
                                ->where('inventory_color_variant_id', $variant->id)
                                ->where('size', $sizeValue)
                                ->where('size_system', $sizeSystem)
                                ->first();

                            if ($existingSize) {
                                $existingSize->increment('quantity', (int) $sizeData['quantity']);
                            } else {
                                InventorySize::create([
                                    'inventory_item_id' => $item->id,
                                    'inventory_color_variant_id' => $variant->id,
                                    'size' => $sizeValue,
                                    'size_system' => $sizeSystem,
                                    'quantity' => (int) $sizeData['quantity'],
                                ]);
                            }
                        }
                    }

                    // Process images for this specific color variant
                    $variantImages = $request->file("color_variants.{$idx}.images");
                    if (!empty($variantImages)) {
                        $this->uploadItemImages($item, $variantImages, $variant->id);
                    }
                }
            }

            // Backward compatibility: if sizes are submitted at item-level, keep them item-level.
            if (!empty($validated['sizes'])) {
                foreach ($validated['sizes'] as $sizeData) {
                    $sizeValue = trim((string) $sizeData['size']);
                    $sizeSystem = $this->normalizeSizeSystem($sizeData['size_system'] ?? null);

                    $existingSize = InventorySize::where('inventory_item_id', $item->id)
                        ->whereNull('inventory_color_variant_id')
                        ->where('size', $sizeValue)
                        ->where('size_system', $sizeSystem)
                        ->first();

                    if ($existingSize) {
                        $existingSize->increment('quantity', (int) $sizeData['quantity']);
                    } else {
                        InventorySize::create([
                            'inventory_item_id' => $item->id,
                            'inventory_color_variant_id' => null,
                            'size' => $sizeValue,
                            'size_system' => $sizeSystem,
                            'quantity' => (int) $sizeData['quantity'],
                        ]);
                    }
                }
            }
            
            // Handle flat images (repair materials / items without color variants)
            if ($request->hasFile('images')) {
                $this->uploadItemImages($item, $request->file('images'));
            }
            
            // Create initial stock movement
            if ($validated['available_quantity'] > 0) {
                StockMovement::create([
                    'inventory_item_id' => $item->id,
                    'movement_type' => 'initial',
                    'quantity_change' => $validated['available_quantity'],
                    'quantity_before' => 0,
                    'quantity_after' => $validated['available_quantity'],
                    'reference_type' => 'initial_stock',
                    'notes' => 'Initial stock entry',
                    'performed_by' => $actorUserId,
                    'performed_at' => now()
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Inventory item created successfully',
                'item' => $item->load(['sizes', 'colorVariants.images', 'colorVariants.sizes', 'images'])
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();

            if ($this->isDuplicateColorConstraintError($e)) {
                return response()->json([
                    'message' => 'Combined color already exists in this stock item. Try a different color combination.',
                    'errors' => [
                        'color_variants' => ['Duplicate combined color is not allowed.'],
                    ],
                ], 422);
            }

            return response()->json([
                'message' => 'Error creating inventory item',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update inventory item
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:shoes,repair_materials',
            'brand' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'unit' => 'nullable|string|max:50',
            'available_quantity' => 'nullable|integer|min:0',
            'reorder_level' => 'nullable|integer|min:0',
            'reorder_quantity' => 'nullable|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean'
        ]);

        if ($authorizationError = $this->authorizeCategoryForBusinessType($request, $validated['category'])) {
            return $authorizationError;
        }
        
        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }

        $actorUserId = $this->resolveActorUserId($request);
        
        $item = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);

        if ($authorizationError = $this->authorizeCategoryForBusinessType($request, $item->category)) {
            return $authorizationError;
        }
        
        DB::transaction(function () use ($item, $validated, $request, $actorUserId) {
            $quantityBefore = $item->available_quantity;
            $newQuantity = $validated['available_quantity'] ?? $quantityBefore;
            $quantityChange = $newQuantity - $quantityBefore;

            $updateData = array_merge(
                array_diff_key($validated, ['available_quantity' => null]),
                ['available_quantity' => $newQuantity, 'updated_by' => $actorUserId]
            );

            $item->update($updateData);

            // Record stock movement if quantity changed
            if ($quantityChange !== 0) {
                StockMovement::create([
                    'inventory_item_id' => $item->id,
                    'movement_type' => 'adjustment',
                    'quantity_change' => $quantityChange,
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $newQuantity,
                    'reference_type' => 'manual',
                    'notes' => 'Quantity updated via Upload Inventory page',
                    'performed_by' => $actorUserId,
                    'performed_at' => now(),
                ]);
            }
        });
        
        return response()->json([
            'message' => 'Inventory item updated successfully',
            'item' => $item->fresh(['sizes', 'colorVariants.images', 'colorVariants.sizes', 'images'])
        ]);
    }
    
    /**
     * Archive inventory item (soft delete)
     */
    public function destroy(Request $request, $id)
    {
        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }
        
        $item = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);

        if ($authorizationError = $this->authorizeCategoryForBusinessType($request, $item->category)) {
            return $authorizationError;
        }
        
        $item->delete();
        
        return response()->json([
            'message' => 'Inventory item archived successfully'
        ]);
    }

    /**
     * Restore archived inventory item
     */
    public function restore(Request $request, $id)
    {
        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }

        $item = InventoryItem::withTrashed()
            ->where('shop_owner_id', $shopOwnerId)
            ->onlyTrashed()
            ->findOrFail($id);

        if ($authorizationError = $this->authorizeCategoryForBusinessType($request, $item->category)) {
            return $authorizationError;
        }

        $item->restore();

        return response()->json([
            'message' => 'Inventory item restored successfully',
            'item' => $item->fresh(['sizes', 'colorVariants.images', 'colorVariants.sizes', 'images'])
        ]);
    }
    
    /**
     * Upload images for inventory item
     */
    public function uploadImages(Request $request)
    {
        $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'images' => 'required|array',
            'images.*' => 'file|mimes:jpeg,png,jpg,gif,webp,avif|max:2048',
            'color_variant_id' => 'nullable|exists:inventory_color_variants,id'
        ]);
        
        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }
        
        $item = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($request->inventory_item_id);

        if ($authorizationError = $this->authorizeCategoryForBusinessType($request, $item->category)) {
            return $authorizationError;
        }
        
        $uploadedImages = $this->uploadItemImages(
            $item,
            $request->file('images'),
            $request->color_variant_id
        );

        $this->syncInventoryImagesToLinkedProduct(
            $item,
            $request->color_variant_id ? (int) $request->color_variant_id : null,
            $uploadedImages
        );
        
        return response()->json([
            'message' => 'Images uploaded successfully',
            'images' => $uploadedImages
        ]);
    }
    
    /**
     * Delete specific image
     */
    public function deleteImage(Request $request, $imageId)
    {
        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }
        
        $image = InventoryImage::whereHas('inventoryItem', function ($query) use ($shopOwnerId) {
                $query->where('shop_owner_id', $shopOwnerId);
            })
            ->findOrFail($imageId);

        if ($authorizationError = $this->authorizeCategoryForBusinessType($request, $image->inventoryItem->category)) {
            return $authorizationError;
        }
        
        $this->deleteLinkedProductImageByInventoryImage($image);

        // Delete file from storage
        if (Storage::disk('public')->exists($image->image_path)) {
            Storage::disk('public')->delete($image->image_path);
        }
        
        $image->delete();
        
        return response()->json([
            'message' => 'Image deleted successfully'
        ]);
    }
    
    /**
     * Set image as thumbnail
     */
    public function setThumbnail(Request $request, $imageId)
    {
        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }
        
        $image = InventoryImage::whereHas('inventoryItem', function ($query) use ($shopOwnerId) {
                $query->where('shop_owner_id', $shopOwnerId);
            })
            ->findOrFail($imageId);

        if ($authorizationError = $this->authorizeCategoryForBusinessType($request, $image->inventoryItem->category)) {
            return $authorizationError;
        }
        
        DB::transaction(function () use ($image) {
            // Remove thumbnail flag from other images
            InventoryImage::where('inventory_item_id', $image->inventory_item_id)
                ->update(['is_thumbnail' => false]);
            
            // Set this image as thumbnail
            $image->is_thumbnail = true;
            $image->save();
            
            // Update main_image on inventory item
            $image->inventoryItem->main_image = $image->image_path;
            $image->inventoryItem->save();

            $this->syncLinkedProductThumbnailByInventoryImage($image);
        });
        
        return response()->json([
            'message' => 'Thumbnail set successfully',
            'image' => $image
        ]);
    }
    
    /**
     * Add a new colour variant to an existing inventory item and
     * automatically sync it to the linked product (if any).
     *
     * POST /erp/inventory/items/{id}/colors
     */
    public function addColor(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'color_name'        => 'required|string|max:100',
            'color_code'        => 'nullable|string|max:20',
            'sizes'             => 'nullable|array',
            'sizes.*.size'      => 'required|string',
            'sizes.*.size_system' => 'nullable|in:US,UK,EU,AU,CN',
            'sizes.*.quantity'  => 'required|integer|min:0',
            'images'            => 'nullable|array',
            'images.*'          => 'file|mimes:jpeg,png,jpg,gif,webp,avif|max:2048',
        ]);

        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }

        $actorUserId = $this->resolveActorUserId($request);

        $item = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);

        if ($authorizationError = $this->authorizeCategoryForBusinessType($request, $item->category)) {
            return $authorizationError;
        }

        if ($item->category !== self::CATEGORY_SHOES) {
            return response()->json([
                'message' => 'Color variants can only be added for shoes inventory items.'
            ], 422);
        }

        $canonicalColorName = $this->canonicalizeColorName($validated['color_name']);
        $newColorIdentity = $this->normalizeColorIdentity($canonicalColorName);

        $existingColorIdentities = $item->colorVariants()
            ->pluck('color_name')
            ->map(fn ($name) => $this->normalizeColorIdentity((string) $name))
            ->all();

        if (in_array($newColorIdentity, $existingColorIdentities, true)) {
            return response()->json([
                'message' => 'This color variant already exists for this item.',
                'errors' => [
                    'color_name' => ['Duplicate combined color is not allowed.'],
                ],
            ], 422);
        }

        DB::beginTransaction();
        try {
            $totalQty = collect($validated['sizes'] ?? [])->sum('quantity');

            // 1. Create the inventory colour variant
            $colorVariant = InventoryColorVariant::create([
                'inventory_item_id' => $item->id,
                'color_name'        => $canonicalColorName,
                'color_code'        => $validated['color_code'] ?? null,
                'quantity'          => $totalQty,
            ]);

            // 2. Upload images (stored under inventory/{id}/)
            $uploadedImages = [];
            if ($request->hasFile('images')) {
                $uploadedImages = $this->uploadItemImages(
                    $item,
                    $request->file('images'),
                    $colorVariant->id
                );
            }

            // 3. Update sizes for this specific colour variant
            foreach ($validated['sizes'] ?? [] as $sizeData) {
                $sizeValue = trim((string) $sizeData['size']);
                $sizeSystem = $this->normalizeSizeSystem($sizeData['size_system'] ?? null);

                $existingSize = InventorySize::where('inventory_item_id', $item->id)
                    ->where('inventory_color_variant_id', $colorVariant->id)
                    ->where('size', $sizeValue)
                    ->where('size_system', $sizeSystem)
                    ->first();

                if ($existingSize) {
                    $existingSize->increment('quantity', $sizeData['quantity']);
                } else {
                    InventorySize::create([
                        'inventory_item_id' => $item->id,
                        'inventory_color_variant_id' => $colorVariant->id,
                        'size'              => $sizeValue,
                        'size_system'       => $sizeSystem,
                        'quantity'          => $sizeData['quantity'],
                    ]);
                }
            }

            // 4. Recalculate item total quantity from all colour variants
            $quantityBefore = $item->available_quantity;
            $newTotalQty    = $item->colorVariants()->sum('quantity');

            $item->update([
                'available_quantity' => $newTotalQty,
            ]);

            // Record stock movement for the new colour addition
            if ($totalQty > 0) {
                StockMovement::create([
                    'inventory_item_id' => $item->id,
                    'movement_type'     => 'adjustment',
                    'quantity_change'   => $totalQty,
                    'quantity_before'   => $quantityBefore,
                    'quantity_after'    => $newTotalQty,
                    'reference_type'    => 'colour_added',
                    'notes'             => "Added colour variant: {$canonicalColorName}",
                    'performed_by'      => $actorUserId,
                    'performed_at'      => now(),
                ]);
            }

            // 5. Auto-sync to linked product
            if ($item->product_id) {
                $nextSortOrder = (int) ProductColorVariant::where('product_id', $item->product_id)
                    ->max('sort_order') + 1;

                $productColorVariant = ProductColorVariant::create([
                    'product_id'         => $item->product_id,
                    'inventory_color_id' => $colorVariant->id,
                    'color_name'         => $canonicalColorName,
                    'color_code'         => $validated['color_code'] ?? null,
                    'is_active'          => true,
                    'sort_order'         => $nextSortOrder,
                ]);

                // Mirror images to product colour variant (reuse same storage path)
                foreach ($uploadedImages as $index => $invImage) {
                    ProductColorVariantImage::create([
                        'product_color_variant_id' => $productColorVariant->id,
                        'image_path'               => $invImage->image_path,
                        'is_thumbnail'             => (bool) ($invImage->is_thumbnail ?? ($index === 0)),
                        'sort_order'               => (int) ($invImage->sort_order ?? $index),
                    ]);
                }

                // Create / update product variants (size × colour)
                foreach ($validated['sizes'] ?? [] as $sizeData) {
                    $variantSizeValue = $this->formatProductVariantSize(
                        (string) $sizeData['size'],
                        $this->normalizeSizeSystem($sizeData['size_system'] ?? null)
                    );

                    ProductVariant::updateOrCreate(
                        [
                            'product_id' => $item->product_id,
                            'size'       => $variantSizeValue,
                            'color'      => $canonicalColorName,
                        ],
                        [
                            'quantity'  => $sizeData['quantity'],
                            'is_active' => true,
                        ]
                    );
                }

                // Refresh product total stock
                $product = Product::find($item->product_id);
                if ($product) {
                    $product->update([
                        'stock_quantity' => ProductVariant::where('product_id', $item->product_id)->sum('quantity'),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'message'       => 'Colour variant added successfully',
                'color_variant' => $colorVariant->load(['images', 'sizes']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            if ($this->isDuplicateColorConstraintError($e)) {
                return response()->json([
                    'message' => 'Combined color already exists for this stock item. Try a different color combination.',
                    'errors' => [
                        'color_name' => ['Duplicate combined color is not allowed.'],
                    ],
                ], 422);
            }

            return response()->json([
                'message' => 'Error adding colour variant',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add size quantity to an existing color variant.
     * Keeps inventory item, color variant, and linked product stock in sync.
     */
    public function addSizeToColor(Request $request, $id, $colorId): JsonResponse
    {
        $validated = $request->validate([
            'size' => 'required|string|max:20',
            'size_system' => 'nullable|in:US,UK,EU,AU,CN',
            'quantity' => 'required|integer|min:1',
        ]);

        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }

        $actorUserId = $this->resolveActorUserId($request);

        $item = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);

        if ($authorizationError = $this->authorizeCategoryForBusinessType($request, $item->category)) {
            return $authorizationError;
        }

        if ($item->category !== self::CATEGORY_SHOES) {
            return response()->json([
                'message' => 'Sizes can only be managed for shoes inventory items.'
            ], 422);
        }

        $colorVariant = InventoryColorVariant::where('inventory_item_id', $item->id)
            ->where('id', $colorId)
            ->firstOrFail();

        DB::beginTransaction();
        try {
            $quantityToAdd = (int) $validated['quantity'];
            $sizeValue = trim((string) $validated['size']);
            $sizeSystem = $this->normalizeSizeSystem($validated['size_system'] ?? null);

            // Update/create size stock for this specific color variant
            $sizeRow = InventorySize::where('inventory_item_id', $item->id)
                ->where('inventory_color_variant_id', $colorVariant->id)
                ->where('size', $sizeValue)
                ->where('size_system', $sizeSystem)
                ->first();

            if ($sizeRow) {
                $sizeRow->increment('quantity', $quantityToAdd);
            } else {
                InventorySize::create([
                    'inventory_item_id' => $item->id,
                    'inventory_color_variant_id' => $colorVariant->id,
                    'size' => $sizeValue,
                    'size_system' => $sizeSystem,
                    'quantity' => $quantityToAdd,
                ]);
            }

            // Update color variant quantity
            $colorVariant->increment('quantity', $quantityToAdd);

            // Recalculate total available quantity from color variants
            $quantityBefore = (int) $item->available_quantity;
            $newTotalQty = (int) $item->colorVariants()->sum('quantity');
            $item->update(['available_quantity' => $newTotalQty]);

            StockMovement::create([
                'inventory_item_id' => $item->id,
                'movement_type' => 'adjustment',
                'quantity_change' => $quantityToAdd,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $newTotalQty,
                'reference_type' => 'size_added',
                'notes' => "Added size {$sizeValue} (+{$quantityToAdd}) to {$colorVariant->color_name}",
                'performed_by' => $actorUserId,
                'performed_at' => now(),
            ]);

            // Sync linked product variant if this inventory item is linked to product
            if ($item->product_id) {
                $existingVariant = ProductVariant::where('product_id', $item->product_id)
                    ->where('size', $this->formatProductVariantSize($sizeValue, $sizeSystem))
                    ->where('color', $colorVariant->color_name)
                    ->first();

                if ($existingVariant) {
                    $existingVariant->increment('quantity', $quantityToAdd);
                    $existingVariant->is_active = true;
                    $existingVariant->save();
                } else {
                    ProductVariant::create([
                        'product_id' => $item->product_id,
                        'size' => $this->formatProductVariantSize($sizeValue, $sizeSystem),
                        'color' => $colorVariant->color_name,
                        'quantity' => $quantityToAdd,
                        'is_active' => true,
                    ]);
                }

                $product = Product::find($item->product_id);
                if ($product) {
                    $product->update([
                        'stock_quantity' => ProductVariant::where('product_id', $item->product_id)->sum('quantity'),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Size added successfully',
                'color_variant_id' => (int) $colorVariant->id,
                'size' => $sizeValue,
                'size_system' => $sizeSystem,
                'quantity_added' => $quantityToAdd,
                'item_quantity' => $newTotalQty,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error adding size to color variant',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Correct the quantity of an existing size (typo fix).
     *
     * PUT /erp/inventory/items/{id}/sizes/{sizeId}
     */
    public function updateSizeQuantity(Request $request, $id, $sizeId): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:0',
        ]);

        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }

        $actorUserId = $this->resolveActorUserId($request);

        $item = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);

        if ($authorizationError = $this->authorizeCategoryForBusinessType($request, $item->category)) {
            return $authorizationError;
        }

        if ($item->category !== self::CATEGORY_SHOES) {
            return response()->json([
                'message' => 'Sizes can only be managed for shoes inventory items.'
            ], 422);
        }

        $size = InventorySize::where('inventory_item_id', $item->id)
            ->findOrFail($sizeId);

        DB::beginTransaction();
        try {
            $oldQty = (int) $size->quantity;
            $newQty = (int) $validated['quantity'];

            $size->quantity = $newQty;
            $size->save();

            if ($size->inventory_color_variant_id) {
                $newColorQty = (int) InventorySize::where('inventory_item_id', $item->id)
                    ->where('inventory_color_variant_id', $size->inventory_color_variant_id)
                    ->sum('quantity');

                InventoryColorVariant::where('inventory_item_id', $item->id)
                    ->where('id', $size->inventory_color_variant_id)
                    ->update(['quantity' => $newColorQty]);
            }

            // Recompute item total from all color variants (fallback to item-level sizes for legacy rows)
            $newTotal = (int) $item->colorVariants()->sum('quantity');
            if ($newTotal === 0) {
                $newTotal = (int) InventorySize::where('inventory_item_id', $item->id)
                    ->whereNull('inventory_color_variant_id')
                    ->sum('quantity');
            }
            $item->available_quantity = $newTotal;
            $item->save();

            // Record correction movement
            StockMovement::create([
                'inventory_item_id' => $item->id,
                'movement_type'     => 'adjustment',
                'quantity_change'   => $newQty - $oldQty,
                'quantity_before'   => $oldQty,
                'quantity_after'    => $newQty,
                'reference_type'    => 'size_correction',
                'notes'             => "Size {$size->size} corrected: {$oldQty} → {$newQty}",
                'performed_by'      => $actorUserId,
                'performed_at'      => now(),
            ]);

            DB::commit();

            return response()->json([
                'message'       => 'Size quantity updated',
                'size_id'       => (int) $size->id,
                'size'          => $size->size,
                'size_system'   => $size->size_system,
                'quantity'      => $newQty,
                'item_quantity' => $newTotal,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error updating size quantity',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    protected function resolveShopOwnerId(Request $request): ?int
    {
        $requestShopId = (int) $request->input('user_shop_id');
        if ($requestShopId > 0) {
            return $requestShopId;
        }

        $user = $request->user();
        if (!empty($user?->shop_owner_id)) {
            return (int) $user->shop_owner_id;
        }

        if ($shopOwner = $request->user('shop_owner')) {
            return (int) $shopOwner->id;
        }

        return null;
    }

    protected function resolveActorUserId(Request $request): ?int
    {
        $user = $request->user();

        if (!empty($user?->shop_owner_id)) {
            return (int) $user->id;
        }

        return null;
    }

    protected function resolveBusinessType(Request $request): string
    {
        if (!empty($request->user('shop_owner')?->business_type)) {
            return $this->normalizeBusinessType($request->user('shop_owner')->business_type);
        }

        return $this->normalizeBusinessType(
            $request->user()?->shopOwner?->business_type
            ?? $request->user()?->business_type
        );
    }

    protected function normalizeBusinessType(?string $businessType): string
    {
        $normalized = strtolower(trim((string) $businessType));

        if ($normalized === 'retail') {
            return 'retail';
        }

        if ($normalized === 'repair') {
            return 'repair';
        }

        if (str_contains($normalized, 'both')) {
            return 'both';
        }

        return 'both';
    }

    protected function allowedCategoriesForBusinessType(string $businessType): array
    {
        return match ($businessType) {
            'retail' => [self::CATEGORY_SHOES],
            'repair' => [self::CATEGORY_REPAIR_MATERIALS],
            default => [self::CATEGORY_SHOES, self::CATEGORY_REPAIR_MATERIALS],
        };
    }

    protected function authorizeCategoryForBusinessType(Request $request, string $category): ?JsonResponse
    {
        if (!in_array($category, [self::CATEGORY_SHOES, self::CATEGORY_REPAIR_MATERIALS], true)) {
            return response()->json([
                'message' => 'Only shoes and repair materials can be managed in upload inventory.'
            ], 422);
        }

        $businessType = $this->resolveBusinessType($request);
        $allowedCategories = $this->allowedCategoriesForBusinessType($businessType);

        if (in_array($category, $allowedCategories, true)) {
            return null;
        }

        $allowedLabel = $businessType === 'retail'
            ? 'shoes'
            : ($businessType === 'repair' ? 'repair materials' : 'shoes and repair materials');

        return response()->json([
            'message' => "This business type can upload {$allowedLabel} only."
        ], 403);
    }

    /**
     * Generate SKU
     */
    protected function generateSKU($category, $name)
    {
        $prefix = strtoupper(substr($category, 0, 3));
        $nameCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 3));
        $random = strtoupper(Str::random(4));
        
        return "{$prefix}-{$nameCode}-{$random}";
    }

    protected function canonicalizeColorName(string $colorName): string
    {
        $parts = preg_split('/\+/', $colorName) ?: [];
        $normalized = [];

        foreach ($parts as $part) {
            $cleaned = trim((string) preg_replace('/\s+/', ' ', (string) $part));
            if ($cleaned === '') {
                continue;
            }

            $display = ucwords(strtolower($cleaned));
            $normalized[strtolower($display)] = $display;
        }

        if (empty($normalized)) {
            return trim((string) preg_replace('/\s+/', ' ', $colorName));
        }

        ksort($normalized, SORT_NATURAL | SORT_FLAG_CASE);

        return implode(' + ', array_values($normalized));
    }

    protected function normalizeColorIdentity(string $colorName): string
    {
        return strtolower($this->canonicalizeColorName($colorName));
    }

    protected function isDuplicateColorConstraintError(\Throwable $exception): bool
    {
        if (!$exception instanceof QueryException) {
            return false;
        }

        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $message = strtolower((string) $exception->getMessage());

        if ($sqlState !== '23000') {
            return false;
        }

        return str_contains($message, 'duplicate')
            && (
                str_contains($message, 'inventory_color_variants')
                || str_contains($message, 'unique_inventory_color')
                || str_contains($message, 'color_name')
            );
    }
    
    /**
     * Upload and store images
     */
    protected function uploadItemImages($item, $images, $colorVariantId = null)
    {
        $uploadedImages = [];
        
        foreach ($images as $index => $image) {
            $path = $image->store('inventory/' . $item->id, 'public');
            
            $inventoryImage = InventoryImage::create([
                'inventory_item_id' => $item->id,
                'inventory_color_variant_id' => $colorVariantId,
                'image_path' => $path,
                'is_thumbnail' => $index === 0 && !$item->main_image,
                'sort_order' => $index
            ]);
            
            // Set first image as main image if not set
            if ($index === 0 && !$item->main_image) {
                $item->main_image = $path;
                $item->save();
            }
            
            $uploadedImages[] = $inventoryImage;
        }
        
        return $uploadedImages;
    }

    protected function syncInventoryImagesToLinkedProduct(InventoryItem $item, ?int $inventoryColorVariantId, array $inventoryImages): void
    {
        if (!$item->product_id || !$inventoryColorVariantId || empty($inventoryImages)) {
            return;
        }

        $productColorVariant = ProductColorVariant::where('product_id', $item->product_id)
            ->where('inventory_color_id', $inventoryColorVariantId)
            ->first();

        if (!$productColorVariant) {
            return;
        }

        $nextSortOrder = (int) ProductColorVariantImage::where('product_color_variant_id', $productColorVariant->id)
            ->max('sort_order') + 1;

        foreach ($inventoryImages as $inventoryImage) {
            if (!$inventoryImage instanceof InventoryImage) {
                continue;
            }

            ProductColorVariantImage::updateOrCreate(
                [
                    'product_color_variant_id' => $productColorVariant->id,
                    'image_path' => $inventoryImage->image_path,
                ],
                [
                    'is_thumbnail' => (bool) $inventoryImage->is_thumbnail,
                    'sort_order' => (int) ($inventoryImage->sort_order ?? $nextSortOrder),
                ]
            );

            $nextSortOrder++;
        }
    }

    protected function deleteLinkedProductImageByInventoryImage(InventoryImage $image): void
    {
        $inventoryItem = $image->inventoryItem;
        if (!$inventoryItem || !$inventoryItem->product_id || !$image->inventory_color_variant_id) {
            return;
        }

        $productColorVariant = ProductColorVariant::where('product_id', $inventoryItem->product_id)
            ->where('inventory_color_id', $image->inventory_color_variant_id)
            ->first();

        if (!$productColorVariant) {
            return;
        }

        ProductColorVariantImage::where('product_color_variant_id', $productColorVariant->id)
            ->where('image_path', $image->image_path)
            ->delete();
    }

    protected function syncLinkedProductThumbnailByInventoryImage(InventoryImage $image): void
    {
        $inventoryItem = $image->inventoryItem;
        if (!$inventoryItem || !$inventoryItem->product_id || !$image->inventory_color_variant_id) {
            return;
        }

        $productColorVariant = ProductColorVariant::where('product_id', $inventoryItem->product_id)
            ->where('inventory_color_id', $image->inventory_color_variant_id)
            ->first();

        if (!$productColorVariant) {
            return;
        }

        ProductColorVariantImage::where('product_color_variant_id', $productColorVariant->id)
            ->update(['is_thumbnail' => false]);

        ProductColorVariantImage::where('product_color_variant_id', $productColorVariant->id)
            ->where('image_path', $image->image_path)
            ->update(['is_thumbnail' => true]);
    }

    protected function normalizeSizeSystem(?string $sizeSystem): string
    {
        $normalized = strtoupper(trim((string) $sizeSystem));

        if (in_array($normalized, self::SIZE_SYSTEMS, true)) {
            return $normalized;
        }

        return 'US';
    }

    protected function formatProductVariantSize(string $size, string $sizeSystem): string
    {
        $sizeValue = trim($size);
        $normalizedSystem = $this->normalizeSizeSystem($sizeSystem);

        if ($normalizedSystem === 'US') {
            return $sizeValue;
        }

        return "{$normalizedSystem} {$sizeValue}";
    }
}
