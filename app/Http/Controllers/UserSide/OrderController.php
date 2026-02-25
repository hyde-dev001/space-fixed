<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Order;
use App\Enums\OrderStatus;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }
    /**
     * Display user's orders
     */
    public function index(): Response
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return redirect()->route('login');
        }

        Notification::query()
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->whereIn('type', [
                'order_placed',
                'order_confirmed',
                'order_shipped',
                'order_delivered',
                'order_cancelled',
                'order_status_update',
            ])
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        
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
                    'shop_id' => $order->shopOwner ? $order->shopOwner->id : null,
                    'shop_name' => $order->shopOwner->business_name ?? 'Unknown Shop',
                    'shop_address' => $order->shopOwner->business_address ?? $order->shopOwner->city_state,
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
                    'carrier_name' => $order->carrier_name,
                    'tracking_link' => $order->tracking_link,
                    'eta' => $order->eta,
                    'pickup_enabled' => $order->pickup_enabled ?? false,
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
        if (!in_array($order->status, [OrderStatus::SHIPPED])) {
            return response()->json([
                'success' => false,
                'message' => 'Can only confirm orders that have been shipped',
            ], 400);
        }

        $order->status = OrderStatus::DELIVERED;
        $order->save();

        // Notify shop owner about successful delivery
        try {
            $this->notificationService->sendToShopOwner(
                shopOwnerId: $order->shop_owner_id,
                type: \App\Enums\NotificationType::ORDER_DELIVERED,
                title: 'Order Delivered Successfully',
                message: "Order #{$order->order_number} has been delivered to customer",
                data: [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->customer_name,
                    'total' => number_format($order->total_amount, 2),
                ],
                actionUrl: '/shop-owner/job-orders-retail'
            );
            Log::info('Shop owner notified of successful delivery', [
                'shop_owner_id' => $order->shop_owner_id,
                'order_number' => $order->order_number,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send delivery notification to shop owner', [
                'shop_owner_id' => $order->shop_owner_id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

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
            'order_item_id' => 'nullable|integer',
            'reason' => 'nullable|string|max:500',
            'note' => 'nullable|string|max:1000',
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
            if (!in_array($order->status, [OrderStatus::PENDING, OrderStatus::PROCESSING])) {
                \Illuminate\Support\Facades\DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot cancel orders that are already shipped or completed',
                ], 400);
            }

            if (!empty($validated['order_item_id'])) {
                $item = $order->items->firstWhere('id', (int) $validated['order_item_id']);

                if (!$item) {
                    \Illuminate\Support\Facades\DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected order item was not found for this order',
                    ], 404);
                }

                $product = \App\Models\Product::find($item->product_id);
                if ($product) {
                    $product->increment('stock_quantity', $item->quantity);

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

                $item->delete();

                $remainingCount = $order->items()->count();
                $remainingTotal = (float) $order->items()->sum('subtotal');

                $order->total_amount = $remainingTotal;
                if ($remainingCount === 0) {
                    $order->status = OrderStatus::CANCELLED;
                }
                $order->save();

                \Illuminate\Support\Facades\DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => $remainingCount > 0
                        ? 'Order item cancelled successfully. Remaining items are still active.'
                        : 'Order cancelled successfully. Inventory has been restored.',
                    'order_cancelled' => $remainingCount === 0,
                ]);
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

            $order->status = OrderStatus::CANCELLED;
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
                'order_item_id' => $validated['order_item_id'] ?? null,
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel order. Please try again.',
            ], 500);
        }
    }
}
