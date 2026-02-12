<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    /**
     * Display user's orders
     */
    public function index(): Response
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return redirect()->route('login');
        }
        
        $orders = Order::where('customer_id', $user->id)
            ->with(['items', 'shopOwner'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status ?? 'pending',
                    'payment_method' => $order->payment_method ?? 'paymongo',
                    'total_amount' => $order->total_amount,
                    'created_at' => $order->created_at->format('Y-m-d H:i:s'),
                    'shop_name' => $order->shopOwner->business_name ?? 'Unknown Shop',
                    'items_count' => $order->items->count(),
                    'items' => $order->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'product_name' => $item->product_name,
                            'product_slug' => $item->product_slug,
                            'product_image' => $item->product_image,
                            'price' => $item->price,
                            'quantity' => $item->quantity,
                            'subtotal' => $item->subtotal,
                            'size' => $item->size,
                            'color' => $item->color,
                        ];
                    }),
                    'shipping_address' => $order->shipping_address,
                    'customer_name' => $order->customer_name,
                    'customer_email' => $order->customer_email,
                    'customer_phone' => $order->customer_phone,
                    'tracking_number' => $order->tracking_number,
                    'carrier_company' => $order->carrier_company,
                    'tracking_link' => $order->tracking_link,
                    'eta' => $order->eta,
                ];
            });

        return Inertia::render('UserSide/MyOrders', [
            'orders' => $orders,
        ]);
    }

    /**
     * Confirm order delivery
     */
    public function confirmDelivery(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|integer',
        ]);

        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $order = Order::where('id', $validated['order_id'])
            ->where('customer_id', $user->id)
            ->firstOrFail();

        // Only allow confirmation if order is shipped
        if (!in_array($order->status, ['shipped', 'to_ship'])) {
            return response()->json([
                'success' => false,
                'message' => 'Can only confirm orders that have been shipped',
            ], 400);
        }

        $order->status = 'delivered';
        $order->save();

        return response()->json([
            'success' => true,
            'message' => 'Order confirmed as delivered',
        ]);
    }
    
    /**
     * Cancel order
     */
    public function cancel(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'reason' => 'nullable|string|max:500',
        ]);

        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $order = Order::where('id', $validated['order_id'])
                ->where('customer_id', $user->id)
                ->with('items')
                ->lockForUpdate()
                ->firstOrFail();

            // Only allow cancellation of pending/processing orders
            if (!in_array($order->status, ['pending', 'processing'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot cancel orders that are already shipped or completed',
                ], 400);
            }

            // Restore inventory for each item
            foreach ($order->items as $item) {
                $product = \App\Models\Product::find($item->product_id);
                if ($product) {
                    // Restore product stock
                    $product->increment('stock_quantity', $item->quantity);
                    
                    // Restore variant stock if applicable
                    if ($item->size && $item->color) {
                        $variant = \App\Models\ProductVariant::where('product_id', $product->id)
                            ->where('size', $item->size)
                            ->where('color', $item->color)
                            ->first();
                        
                        if ($variant) {
                            $variant->increment('quantity', $item->quantity);
                        }
                    }
                }
            }

            $order->status = 'cancelled';
            $order->save();

            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order cancelled successfully. Inventory has been restored.',
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Order cancellation failed', [
                'error' => $e->getMessage(),
                'order_id' => $validated['order_id'],
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel order. Please try again.',
            ], 500);
        }
    }
}
