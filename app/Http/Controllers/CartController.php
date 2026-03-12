<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    /**
     * Get all cart items for the authenticated user
     */
    public function index()
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['items' => [], 'count' => 0]);
        }

        $cartItems = CartItem::where('user_id', $user->id)
            ->with('product:id,name,price,stock_quantity,primary')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $item->product_name,
                    'price' => $item->price,
                    'size' => $item->size,
                    'quantity' => $item->quantity,
                    'image' => $item->image,
                    'stock_quantity' => $item->stock_quantity,
                    'options' => $item->options,
                ];
            });

        return response()->json([
            'items' => $cartItems,
            'count' => $cartItems->sum('quantity'),
        ]);
    }

    /**
     * Add item to cart
     */
    public function store(Request $request)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check if user is ERP staff
        $userRole = strtoupper($user->role ?? '');
        $erpRoles = ['HR', 'FINANCE_STAFF', 'FINANCE_MANAGER', 'FINANCE', 'CRM', 'MANAGER', 'STAFF'];
        if (in_array($userRole, $erpRoles)) {
            return response()->json(['error' => 'ERP staff cannot add items to cart'], 403);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'size' => 'nullable|string',
            'options' => 'nullable|array',
        ]);

        // Get product details
        $product = Product::findOrFail($validated['product_id']);

        // Check stock
        if ($product->stock_quantity < $validated['quantity']) {
            return response()->json([
                'error' => 'Insufficient stock',
                'available' => $product->stock_quantity
            ], 400);
        }

        // Create unique identifier for cart item (product + size + options)
        $options = $validated['options'] ?? null;
        
        // Find existing cart item
        $cartItem = CartItem::where('user_id', $user->id)
            ->where('product_id', $validated['product_id'])
            ->where('size', $validated['size'])
            ->where(function ($query) use ($options) {
                if ($options) {
                    $query->where('options', json_encode($options));
                } else {
                    $query->whereNull('options');
                }
            })
            ->first();

        if ($cartItem) {
            // Update quantity
            $newQuantity = $cartItem->quantity + $validated['quantity'];
            
            if ($product->stock_quantity < $newQuantity) {
                return response()->json([
                    'error' => 'Stock limit reached',
                    'available' => $product->stock_quantity
                ], 400);
            }
            
            $cartItem->quantity = $newQuantity;
            $cartItem->save();
        } else {
            // Create new cart item
            $cartItem = CartItem::create([
                'user_id' => $user->id,
                'product_id' => $validated['product_id'],
                'size' => $validated['size'],
                'quantity' => $validated['quantity'],
                'price' => $product->price,
                'image' => $product->primary ?? ($product->images[0] ?? null),
                'product_name' => $product->name,
                'stock_quantity' => $product->stock_quantity,
                'options' => $options,
            ]);
        }

        // Get updated cart count
        $totalCount = CartItem::where('user_id', $user->id)->sum('quantity');

        return response()->json([
            'message' => 'Item added to cart',
            'item' => $cartItem,
            'total_count' => $totalCount,
        ]);
    }

    /**
     * Update cart item quantity
     */
    public function update(Request $request, $id)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $cartItem = CartItem::where('user_id', $user->id)
            ->where('id', $id)
            ->firstOrFail();

        $product = Product::findOrFail($cartItem->product_id);

        if ($product->stock_quantity < $validated['quantity']) {
            return response()->json([
                'error' => 'Insufficient stock',
                'available' => $product->stock_quantity
            ], 400);
        }

        $cartItem->quantity = $validated['quantity'];
        $cartItem->save();

        $totalCount = CartItem::where('user_id', $user->id)->sum('quantity');

        return response()->json([
            'message' => 'Cart updated',
            'item' => $cartItem,
            'total_count' => $totalCount,
        ]);
    }

    /**
     * Remove item from cart
     */
    public function destroy($id)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $cartItem = CartItem::where('user_id', $user->id)
            ->where('id', $id)
            ->firstOrFail();

        $cartItem->delete();

        $totalCount = CartItem::where('user_id', $user->id)->sum('quantity');

        return response()->json([
            'message' => 'Item removed from cart',
            'total_count' => $totalCount,
        ]);
    }

    /**
     * Clear entire cart
     */
    public function clear()
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        CartItem::where('user_id', $user->id)->delete();

        return response()->json(['message' => 'Cart cleared']);
    }

    /**
     * Sync localStorage cart to database after login
     */
    public function sync(Request $request)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.size' => 'nullable|string',
            'items.*.options' => 'nullable|array',
        ]);

        DB::beginTransaction();
        try {
            foreach ($validated['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);
                
                // Skip if insufficient stock
                if ($product->stock_quantity < $item['quantity']) {
                    continue;
                }

                $options = $item['options'] ?? null;

                // Find or create cart item
                $cartItem = CartItem::where('user_id', $user->id)
                    ->where('product_id', $item['product_id'])
                    ->where('size', $item['size'] ?? null)
                    ->where(function ($query) use ($options) {
                        if ($options) {
                            $query->where('options', json_encode($options));
                        } else {
                            $query->whereNull('options');
                        }
                    })
                    ->first();

                if ($cartItem) {
                    // Update quantity (don't exceed stock)
                    $newQuantity = min($cartItem->quantity + $item['quantity'], $product->stock_quantity);
                    $cartItem->quantity = $newQuantity;
                    $cartItem->save();
                } else {
                    // Create new cart item
                    CartItem::create([
                        'user_id' => $user->id,
                        'product_id' => $item['product_id'],
                        'size' => $item['size'] ?? null,
                        'quantity' => min($item['quantity'], $product->stock_quantity),
                        'price' => $product->price,
                        'image' => $product->primary ?? ($product->images[0] ?? null),
                        'product_name' => $product->name,
                        'stock_quantity' => $product->stock_quantity,
                        'options' => $options,
                    ]);
                }
            }

            DB::commit();

            // Return updated cart
            $cartItems = CartItem::where('user_id', $user->id)->get();
            $totalCount = $cartItems->sum('quantity');

            return response()->json([
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
