<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    /**
     * Get all cart items for the authenticated user
     */
    public function index(): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['items' => [], 'count' => 0]);
        }

        $cartItems = CartItem::where('user_id', $user->id)
            ->with('product:id,name,price,stock_quantity,main_image')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'pid' => $item->product_id,
                    'name' => $item->product_name,
                    'price' => $item->price,
                    'size' => $item->size,
                    'qty' => $item->quantity,
                    'quantity' => $item->quantity,
                    'image' => $item->image,
                    'stock_quantity' => $item->stock_quantity,
                    'options' => $item->options,
                ];
            });

        return response()->json([
            'items' => $cartItems,
            'count' => $cartItems->sum('qty'),
        ]);
    }

    /**
     * Add item to cart (for authenticated users)
     */
    public function add(Request $request): JsonResponse
    {
        // LOG: Entry point with full request details
        \Log::info('=== CartController::add() called ===', [
            'timestamp' => now()->toDateTimeString(),
            'request_id' => uniqid('req_'),
            'raw_input' => $request->all(),
            'request_headers' => $request->headers->all(),
            'request_method' => $request->method(),
            'request_url' => $request->fullUrl()
        ]);
        
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check if user is ERP staff (ERP roles cannot use cart)
        if ($user->hasAnyRole(['HR', 'Finance Staff', 'Finance Manager', 'CRM', 'Manager', 'Staff'])) {
            return response()->json(['error' => 'ERP staff cannot add items to cart'], 403);
        }

        // ANTI-SPAM: Check if duplicate request within last 5 seconds
        $cacheKey = "cart_add_{$user->id}_" . md5(json_encode($request->only(['product_id', 'size', 'options'])));
        if (\Cache::has($cacheKey)) {
            \Log::warning('CartController::add() - DUPLICATE REQUEST BLOCKED', [
                'user_id' => $user->id,
                'cache_key' => $cacheKey
            ]);
            return response()->json([
                'error' => 'Please wait before clicking again',
                'success' => false
            ], 429);
        }
        \Cache::put($cacheKey, true, now()->addSeconds(5));

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'size' => 'nullable|string',
            'options' => 'nullable|array',
        ]);
        
        // LOG: After validation
        \Log::info('CartController::add() validated', [
            'user_id' => $user->id,
            'validated' => $validated
        ]);

        // Use database transaction to prevent race conditions
        return DB::transaction(function () use ($request, $user, $validated) {
            // Get product details
            $product = Product::findOrFail($validated['product_id']);

        // Extract variant details from options
        $options = $validated['options'] ?? [];
        $variantColor = isset($options['color']) ? $options['color'] : null;
        $variantImage = isset($options['image']) ? $options['image'] : null;
        
        // Normalize options to ensure consistent key order (prevents duplicates)
        $normalizedOptions = [];
        if ($variantColor) {
            $normalizedOptions['color'] = $variantColor;
        }
        if ($variantImage) {
            $normalizedOptions['image'] = $variantImage;
        }
        
        // Determine available stock - for products with variants, check variant-specific stock
        $availableStock = $product->stock_quantity;
        $variant = null;
        
        // If product has variants, check the specific variant stock
        if ($validated['size'] && $variantColor) {
            $variant = \App\Models\ProductVariant::where('product_id', $product->id)
                ->where('size', $validated['size'])
                ->where('color', $variantColor)
                ->first();
                
            if ($variant) {
                $availableStock = $variant->quantity;
            }
        }
        
        // Find existing cart item with same product, size, color, AND image variant
        // Use lockForUpdate to prevent race conditions from concurrent requests
        $cartItem = CartItem::where('user_id', $user->id)
            ->where('product_id', $validated['product_id'])
            ->where('size', $validated['size'])
            ->where(function ($query) use ($normalizedOptions) {
                if (!empty($normalizedOptions)) {
                    $query->where('options', json_encode($normalizedOptions));
                } else {
                    $query->whereNull('options');
                }
            })
            ->lockForUpdate()
            ->first();

        if ($cartItem) {
            // LOG: Existing cart item found
            \Log::info('CartController::add() - Existing cart item found', [
                'cart_item_id' => $cartItem->id,
                'existing_quantity' => $cartItem->quantity,
                'adding_quantity' => $validated['quantity'],
                'will_become' => $cartItem->quantity + $validated['quantity']
            ]);
            
            // Check if adding this quantity would exceed stock
            $newQuantity = $cartItem->quantity + $validated['quantity'];
            
            if ($availableStock < $newQuantity) {
                return response()->json([
                    'error' => 'Cannot add more items. You already have ' . $cartItem->quantity . ' in cart.',
                    'available' => $availableStock,
                    'in_cart' => $cartItem->quantity,
                    'max_additional' => max(0, $availableStock - $cartItem->quantity)
                ], 400);
            }
            
            $cartItem->quantity = $newQuantity;
            $cartItem->save();
            
            // LOG: After update
            \Log::info('CartController::add() - Cart item updated', [
                'cart_item_id' => $cartItem->id,
                'new_quantity' => $cartItem->quantity
            ]);
        } else {
            // LOG: Creating new cart item
            \Log::info('CartController::add() - Creating new cart item', [
                'quantity' => $validated['quantity'],
                'available_stock' => $availableStock
            ]);
            
            // Check if requested quantity exceeds available stock for NEW items
            if ($availableStock < $validated['quantity']) {
                return response()->json([
                    'error' => 'Insufficient stock available',
                    'available' => $availableStock,
                    'requested' => $validated['quantity']
                ], 400);
            }
            
            // Create new cart item
            // Determine which image to use for this cart item
            $cartImage = null;
            
            // Prefer variant image from options (represents selected color)
            if ($variantImage) {
                $cartImage = $variantImage;
            } else {
                // Fallback to main_image or first from additional_images
                $cartImage = $product->main_image;
                if (!$cartImage && $product->additional_images) {
                    $additionalImages = is_string($product->additional_images) 
                        ? json_decode($product->additional_images, true) 
                        : $product->additional_images;
                    if (is_array($additionalImages) && count($additionalImages) > 0) {
                        $cartImage = $additionalImages[0];
                    }
                }
            }
            
            $createData = [
                'user_id' => $user->id,
                'product_id' => $validated['product_id'],
                'size' => $validated['size'],
                'quantity' => $validated['quantity'],
                'price' => $product->price,
                'image' => $cartImage,
                'product_name' => $product->name,
                'stock_quantity' => $product->stock_quantity,
                'options' => !empty($normalizedOptions) ? $normalizedOptions : null,
            ];
            
            // LOG: Data being sent to create()
            \Log::info('CartController::add() - About to call CartItem::create()', [
                'create_data' => $createData
            ]);
            
            $cartItem = CartItem::create($createData);
            
            // LOG: After create, check what was actually saved
            \Log::info('CartController::add() - After CartItem::create()', [
                'cart_item_id' => $cartItem->id,
                'saved_quantity' => $cartItem->quantity,
                'fresh_from_db' => $cartItem->fresh()->quantity
            ]);
        }

        // Get updated cart count
        $totalCount = CartItem::where('user_id', $user->id)->sum('quantity');

        return response()->json([
            'success' => true,
            'message' => 'Item added to cart',
            'item' => $cartItem,
            'total_count' => $totalCount,
        ]);
        }); // End DB transaction
    }

    /**
     * Update cart item quantity
     */
    public function update(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
        ]);

        $cartItem = CartItem::where('user_id', $user->id)
            ->where('id', $validated['id'])
            ->firstOrFail();

        $product = Product::findOrFail($cartItem->product_id);

        // Check variant-specific stock if size and color are available
        $availableStock = $product->stock_quantity;
        $options = $cartItem->options ? (is_string($cartItem->options) ? json_decode($cartItem->options, true) : $cartItem->options) : [];
        $variantColor = $options['color'] ?? null;
        
        if ($cartItem->size && $variantColor) {
            $variant = \App\Models\ProductVariant::where('product_id', $product->id)
                ->where('size', $cartItem->size)
                ->where('color', $variantColor)
                ->first();
                
            if ($variant) {
                $availableStock = $variant->quantity;
            }
        }

        if ($availableStock < $validated['quantity']) {
            return response()->json([
                'error' => 'Insufficient stock. Only ' . $availableStock . ' available.',
                'available' => $availableStock
            ], 400);
        }

        $cartItem->quantity = $validated['quantity'];
        $cartItem->save();

        $totalCount = CartItem::where('user_id', $user->id)->sum('quantity');

        return response()->json([
            'success' => true,
            'message' => 'Cart updated',
            'item' => $cartItem,
            'total_count' => $totalCount,
        ]);
    }

    /**
     * Remove item from cart
     */
    public function remove(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'id' => 'required',
        ]);

        $cartItem = CartItem::where('user_id', $user->id)
            ->where('id', $validated['id'])
            ->firstOrFail();

        $cartItem->delete();

        $totalCount = CartItem::where('user_id', $user->id)->sum('quantity');

        return response()->json([
            'success' => true,
            'message' => 'Item removed from cart',
            'total_count' => $totalCount,
        ]);
    }

    /**
     * Clear entire cart
     */
    public function clear(): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        CartItem::where('user_id', $user->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cart cleared'
        ]);
    }

    /**
     * Sync localStorage cart to database after login
     */
    public function sync(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.pid' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.size' => 'nullable|string',
            'items.*.color' => 'nullable|string',
            'items.*.image' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            foreach ($validated['items'] as $item) {
                $product = Product::find($item['pid']);
                
                if (!$product) continue;
                
                // Skip if insufficient stock
                if ($product->stock_quantity < $item['qty']) {
                    continue;
                }

                // Build options array for variant identification
                $options = [];
                if (isset($item['color'])) {
                    $options['color'] = $item['color'];
                }
                if (isset($item['image'])) {
                    $options['image'] = $item['image'];
                }

                // Find or create cart item with matching size, color, and image
                $cartItem = CartItem::where('user_id', $user->id)
                    ->where('product_id', $item['pid'])
                    ->where('size', $item['size'] ?? null)
                    ->where(function ($query) use ($options) {
                        if (!empty($options)) {
                            $query->where('options', json_encode($options));
                        } else {
                            $query->whereNull('options');
                        }
                    })
                    ->first();

                if ($cartItem) {
                    // Update quantity (don't exceed stock)
                    $newQuantity = min($cartItem->quantity + $item['qty'], $product->stock_quantity);
                    $cartItem->quantity = $newQuantity;
                    $cartItem->save();
                } else {
                    // Create new cart item
                    // Use image from localStorage item (variant image) or fallback to product main_image
                    $image = $item['image'] ?? $product->main_image;
                    if (!$image && $product->additional_images) {
                        $additionalImages = is_string($product->additional_images) 
                            ? json_decode($product->additional_images, true) 
                            : $product->additional_images;
                        if (is_array($additionalImages) && count($additionalImages) > 0) {
                            $image = $additionalImages[0];
                        }
                    }
                    
                    CartItem::create([
                        'user_id' => $user->id,
                        'product_id' => $item['pid'],
                        'size' => $item['size'] ?? null,
                        'quantity' => min($item['qty'], $product->stock_quantity),
                        'price' => $product->price,
                        'image' => $image,
                        'product_name' => $product->name,
                        'stock_quantity' => $product->stock_quantity,
                        'options' => !empty($options) ? $options : null,
                    ]);
                }
            }

            DB::commit();

            // Return updated cart
            $cartItems = CartItem::where('user_id', $user->id)->get();
            $totalCount = $cartItems->sum('quantity');

            return response()->json([
                'success' => true,
                'message' => 'Cart synced successfully',
                'items' => $cartItems,
                'total_count' => $totalCount,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to sync cart'], 500);
        }
    }
}
