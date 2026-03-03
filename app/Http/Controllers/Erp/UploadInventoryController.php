<?php

namespace App\Http\Controllers\ERP;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryColorVariant;
use App\Models\InventoryImage;
use App\Models\InventorySize;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class UploadInventoryController extends Controller
{
    /**
     * List uploaded inventory items
     */
    public function index(Request $request)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $items = InventoryItem::with(['sizes', 'colorVariants.images', 'images'])
            ->where('shop_owner_id', $shopOwnerId)
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->when($request->category, function ($query, $category) {
                $query->where('category', $category);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();
        
        return Inertia::render('ERP/Inventory/UploadInventory', [
            'items' => $items,
            'filters' => $request->only(['search', 'category'])
        ]);
    }
    
    /**
     * Create new inventory item
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:100|unique:inventory_items,sku',
            'category' => 'required|in:shoes,accessories,care_products,cleaning_materials,packaging,repair_materials',
            'brand' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'unit' => 'nullable|string|max:50',
            'available_quantity' => 'required|integer|min:0',
            'reorder_level' => 'nullable|integer|min:0',
            'reorder_quantity' => 'nullable|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'sizes' => 'nullable|array',
            'sizes.*.size' => 'required|string',
            'sizes.*.quantity' => 'required|integer|min:0',
            'color_variants' => 'nullable|array',
            'color_variants.*.color_name' => 'required|string',
            'color_variants.*.color_code' => 'nullable|string',
            'color_variants.*.quantity' => 'required|integer|min:0',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);
        
        $shopOwnerId = $request->user()->shop_owner_id;
        
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
                'created_by' => $request->user()->id
            ]);
            
            // Create sizes if provided
            if (!empty($validated['sizes'])) {
                foreach ($validated['sizes'] as $sizeData) {
                    InventorySize::create([
                        'inventory_item_id' => $item->id,
                        'size' => $sizeData['size'],
                        'quantity' => $sizeData['quantity']
                    ]);
                }
            }
            
            // Create color variants if provided
            if (!empty($validated['color_variants'])) {
                foreach ($validated['color_variants'] as $variantData) {
                    InventoryColorVariant::create([
                        'inventory_item_id' => $item->id,
                        'color_name' => $variantData['color_name'],
                        'color_code' => $variantData['color_code'] ?? null,
                        'quantity' => $variantData['quantity']
                    ]);
                }
            }
            
            // Handle image uploads
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
                    'performed_by' => $request->user()->id,
                    'performed_at' => now()
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Inventory item created successfully',
                'item' => $item->load(['sizes', 'colorVariants', 'images'])
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
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
            'category' => 'required|in:shoes,accessories,care_products,cleaning_materials,packaging,repair_materials',
            'brand' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'unit' => 'nullable|string|max:50',
            'reorder_level' => 'nullable|integer|min:0',
            'reorder_quantity' => 'nullable|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean'
        ]);
        
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $item = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        $item->update(array_merge($validated, [
            'updated_by' => $request->user()->id
        ]));
        
        return response()->json([
            'message' => 'Inventory item updated successfully',
            'item' => $item->fresh(['sizes', 'colorVariants', 'images'])
        ]);
    }
    
    /**
     * Delete inventory item (soft delete)
     */
    public function destroy($id)
    {
        $shopOwnerId = request()->user()->shop_owner_id;
        
        $item = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        $item->delete();
        
        return response()->json([
            'message' => 'Inventory item deleted successfully'
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
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
            'color_variant_id' => 'nullable|exists:inventory_color_variants,id'
        ]);
        
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $item = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($request->inventory_item_id);
        
        $uploadedImages = $this->uploadItemImages(
            $item,
            $request->file('images'),
            $request->color_variant_id
        );
        
        return response()->json([
            'message' => 'Images uploaded successfully',
            'images' => $uploadedImages
        ]);
    }
    
    /**
     * Delete specific image
     */
    public function deleteImage($imageId)
    {
        $shopOwnerId = request()->user()->shop_owner_id;
        
        $image = InventoryImage::whereHas('inventoryItem', function ($query) use ($shopOwnerId) {
                $query->where('shop_owner_id', $shopOwnerId);
            })
            ->findOrFail($imageId);
        
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
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $image = InventoryImage::whereHas('inventoryItem', function ($query) use ($shopOwnerId) {
                $query->where('shop_owner_id', $shopOwnerId);
            })
            ->findOrFail($imageId);
        
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
        });
        
        return response()->json([
            'message' => 'Thumbnail set successfully',
            'image' => $image
        ]);
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
}
