<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CustomerOrderController extends Controller
{
    /**
     * Get all orders for the authenticated customer
     */
    public function index(Request $request)
    {
        try {
            // Get authenticated user (customer)
            $user = Auth::guard('web')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            // Fetch orders for this customer
            $orders = DB::table('orders')
                ->where('customer_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            // Transform orders to include items
            $ordersWithItems = $orders->map(function ($order) {
                // Get order items from order_items table if it exists
                // For now, we'll parse from a JSON field or create a simple structure
                $items = DB::table('order_items')
                    ->where('order_id', $order->id)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'name' => $item->product_name ?? 'Product',
                            'price' => (float) ($item->price ?? 0),
                            'qty' => (int) ($item->quantity ?? 1),
                            'size' => $item->size ?? null,
                            'image' => $item->image ?? null,
                        ];
                    });

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'total_amount' => (float) $order->total_amount,
                    'status' => $order->status,
                    'created_at' => $order->created_at,
                    'items' => $items->toArray(),
                ];
            });

            return response()->json([
                'success' => true,
                'orders' => $ordersWithItems,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching customer orders', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to fetch orders',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Confirm delivery of an order
     */
    public function confirmDelivery(Request $request, $id)
    {
        try {
            $user = Auth::guard('web')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            // Find the order
            $order = DB::table('orders')
                ->where('id', $id)
                ->where('customer_id', $user->id)
                ->first();

            if (!$order) {
                return response()->json(['error' => 'Order not found'], 404);
            }

            // Check if order is in shipped status
            if ($order->status !== 'shipped') {
                return response()->json([
                    'error' => 'Order must be in shipped status to confirm delivery',
                ], 400);
            }

            // Update order status to delivered
            DB::table('orders')
                ->where('id', $id)
                ->update([
                    'status' => 'delivered',
                    'delivered_at' => now(),
                    'updated_at' => now(),
                ]);

            Log::info('Order delivery confirmed', [
                'order_id' => $id,
                'order_number' => $order->order_number,
                'customer_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order marked as delivered',
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => 'delivered',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error confirming delivery', [
                'order_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to confirm delivery',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
