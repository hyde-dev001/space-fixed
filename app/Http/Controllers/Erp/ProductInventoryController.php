<?php

namespace App\Http\Controllers\ERP;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductInventoryController extends Controller
{
    /**
     * List all inventory items with filters
     */
    public function index(Request $request)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $query = InventoryItem::with(['sizes', 'colorVariants', 'images'])
            ->where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true);
        
        // Search filter
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('sku', 'like', "%{$request->search}%");
            });
        }
        
        // Category filter
        if ($request->category) {
            $query->where('category', $request->category);
        }
        
        // Brand filter
        if ($request->brand) {
            $query->where('brand', $request->brand);
        }
        
        // Status filter
        if ($request->status) {
            if ($request->status === 'low_stock') {
                $query->lowStock();
            } elseif ($request->status === 'out_of_stock') {
                $query->outOfStock();
            } elseif ($request->status === 'in_stock') {
                $query->where('available_quantity', '>', DB::raw('reorder_level'));
            }
        }
        
        // Sorting
        $sortBy = $request->sort_by ?? 'name';
        $sortOrder = $request->sort_order ?? 'asc';
        
        if ($sortBy === 'stock_level') {
            $query->orderBy('available_quantity', $sortOrder);
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }
        
        $products = $query->paginate($request->per_page ?? 20)->withQueryString();
        
        return response()->json($products);
    }
    
    /**
     * Show detailed product with variants and images
     */
    public function show($id)
    {
        $shopOwnerId = request()->user()->shop_owner_id;
        
        $product = InventoryItem::with([
                'sizes',
                'colorVariants.images',
                'images',
                'stockMovements' => function ($query) {
                    $query->with('performer')->latest('performed_at')->limit(20);
                }
            ])
            ->where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        return response()->json($product);
    }
    
    /**
     * Update available quantity for a product
     */
    public function updateQuantity(Request $request, $id)
    {
        $validated = $request->validate([
            'available_quantity' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:500',
            'movement_type' => 'required|in:adjustment,stock_in,stock_out'
        ]);
        
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $item = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        DB::transaction(function () use ($item, $validated, $request) {
            $quantityBefore = $item->available_quantity;
            $quantityAfter = $validated['available_quantity'];
            $quantityChange = $quantityAfter - $quantityBefore;
            
            // Update the item
            $item->available_quantity = $quantityAfter;
            $item->updated_by = $request->user()->id;
            $item->save();
            
            // Record stock movement
            StockMovement::create([
                'inventory_item_id' => $item->id,
                'movement_type' => $validated['movement_type'],
                'quantity_change' => $quantityChange,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'reference_type' => 'manual',
                'notes' => $validated['notes'] ?? 'Manual quantity adjustment',
                'performed_by' => $request->user()->id,
                'performed_at' => now()
            ]);
            
            // Check and create alerts if needed
            $item->checkReorderLevel();
        });
        
        return response()->json([
            'message' => 'Quantity updated successfully',
            'item' => $item->fresh()
        ]);
    }
    
    /**
     * Update multiple items at once
     */
    public function bulkUpdateQuantities(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:inventory_items,id',
            'items.*.available_quantity' => 'required|integer|min:0',
            'items.*.notes' => 'nullable|string|max:500'
        ]);
        
        $shopOwnerId = $request->user()->shop_owner_id;
        $userId = $request->user()->id;
        
        DB::transaction(function () use ($validated, $shopOwnerId, $userId) {
            foreach ($validated['items'] as $itemData) {
                $item = InventoryItem::where('shop_owner_id', $shopOwnerId)
                    ->find($itemData['id']);
                
                if (!$item) continue;
                
                $quantityBefore = $item->available_quantity;
                $quantityAfter = $itemData['available_quantity'];
                $quantityChange = $quantityAfter - $quantityBefore;
                
                if ($quantityChange != 0) {
                    $item->available_quantity = $quantityAfter;
                    $item->updated_by = $userId;
                    $item->save();
                    
                    StockMovement::create([
                        'inventory_item_id' => $item->id,
                        'movement_type' => 'adjustment',
                        'quantity_change' => $quantityChange,
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $quantityAfter,
                        'reference_type' => 'bulk_update',
                        'notes' => $itemData['notes'] ?? 'Bulk quantity update',
                        'performed_by' => $userId,
                        'performed_at' => now()
                    ]);
                    
                    $item->checkReorderLevel();
                }
            }
        });
        
        return response()->json([
            'message' => 'Quantities updated successfully',
            'updated_count' => count($validated['items'])
        ]);
    }
}
