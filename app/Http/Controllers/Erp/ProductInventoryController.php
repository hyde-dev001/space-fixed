<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Services\Erp\ShopOwnerInventoryReadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Support\Erp\ErpActorContext;

class ProductInventoryController extends Controller
{
    public function __construct(
        private readonly ShopOwnerInventoryReadService $ownerInventoryRead,
    ) {}

    /**
     * List all inventory items with filters
     */
    public function index(Request $request)
    {
        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }
        
        if ($this->ownerMode()) {
            return response()->json($this->ownerInventoryRead->paginate($shopOwnerId, $request->only([
                'search',
                'category',
                'brand',
                'status',
                'sort_by',
                'sort_order',
                'page',
                'per_page',
            ])));
        }

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

        $products->setCollection(
            $products->getCollection()->map(function (InventoryItem $item) {
                return $this->sanitizeItemImagePaths($item);
            })
        );
        
        return response()->json($products);
    }
    
    /**
     * Show detailed product with variants and images
     */
    public function show(Request $request, $id)
    {
        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }
        
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

        $product = $this->sanitizeItemImagePaths($product);
        
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
        
        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }
        
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
        
        $shopOwnerId = $this->resolveShopOwnerId($request);
        if (!$shopOwnerId) {
            return response()->json([
                'message' => 'Shop context is missing for this account.'
            ], 403);
        }
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

    private function sanitizeItemImagePaths(InventoryItem $item): InventoryItem
    {
        if ($item->main_image && !$this->publicFileExists($item->main_image)) {
            $item->main_image = null;
        }

        if ($item->relationLoaded('images')) {
            $item->setRelation(
                'images',
                $item->images
                    ->filter(fn ($image) => $image->image_path && $this->publicFileExists($image->image_path))
                    ->values()
            );
        }

        if ($item->relationLoaded('colorVariants')) {
            $item->colorVariants->each(function ($variant) {
                if ($variant->relationLoaded('images')) {
                    $variant->setRelation(
                        'images',
                        $variant->images
                            ->filter(fn ($image) => $image->image_path && $this->publicFileExists($image->image_path))
                            ->values()
                    );
                }
            });
        }

        return $item;
    }

    private function publicFileExists(string $path): bool
    {
        return Storage::disk('public')->exists(ltrim($path, '/'));
    }

    private function resolveShopOwnerId(Request $request): ?int
    {
        $context = request()->attributes->get('erp.actor_context');
        if ($context instanceof ErpActorContext && $context->isOwnerMode()) {
            return (int) $context->tenantOwner()->getKey();
        }

        $user = $request->user();
        if (!$user) {
            return null;
        }

        $shopOwnerId = $user->shop_owner_id
            ?? $user->shopOwner?->id
            ?? null;

        return $shopOwnerId ? (int) $shopOwnerId : null;
    }

    private function ownerMode(): bool
    {
        $context = request()->attributes->get('erp.actor_context');

        return $context instanceof ErpActorContext && $context->isOwnerMode();
    }
}
