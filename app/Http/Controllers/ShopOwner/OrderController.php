<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    /**
     * Get all orders for shop owner
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        
        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $query = Order::where('shop_owner_id', $shopOwner->id)
            ->with(['items.product', 'customer']);

        // Filter by status if provided
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Search by order number or customer name
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Paginate
        $perPage = $request->get('per_page', 15);
        $orders = $query->paginate($perPage);

        return response()->json([
            'data' => $orders->map(function($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->customer_name ?? $order->customer?->name ?? 'Guest',
                    'customer_email' => $order->customer_email ?? $order->customer?->email ?? '',
                    'customer_phone' => $order->customer_phone ?? '',
                    'shipping_address' => $order->shipping_address ?? '',
                    'total_amount' => $order->total_amount,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status ?? 'pending',
                    'payment_method' => $order->payment_method ?? '',
                    'tracking_number' => $order->tracking_number ?? '',
                    'carrier_company' => $order->carrier_company ?? '',
                    'eta' => $order->eta ?? null,
                    'created_at' => $order->created_at->toISOString(),
                    'updated_at' => $order->updated_at->toISOString(),
                    'items' => $order->items->map(function($item) {
                        return [
                            'id' => $item->id,
                            'product_id' => $item->product_id,
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
                ];
            }),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Get a single order by ID
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        
        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $order = Order::where('shop_owner_id', $shopOwner->id)
            ->with(['items.product', 'customer'])
            ->find($id);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json([
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name ?? $order->customer?->name ?? 'Guest',
            'customer_email' => $order->customer_email ?? $order->customer?->email ?? '',
            'customer_phone' => $order->customer_phone ?? '',
            'shipping_address' => $order->shipping_address ?? '',
            'total_amount' => $order->total_amount,
            'status' => $order->status,
            'payment_status' => $order->payment_status ?? 'pending',
            'payment_method' => $order->payment_method ?? '',
            'tracking_number' => $order->tracking_number ?? '',
            'carrier_company' => $order->carrier_company ?? '',
            'carrier_name' => $order->carrier_name ?? '',
            'carrier_phone' => $order->carrier_phone ?? '',
            'tracking_link' => $order->tracking_link ?? '',
            'eta' => $order->eta ?? null,
            'created_at' => $order->created_at->toISOString(),
            'updated_at' => $order->updated_at->toISOString(),
            'items' => $order->items->map(function($item) {
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
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
        ]);
    }

    /**
     * Update order status
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, $id)
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        
        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $request->validate([
            'status' => 'required|in:pending,processing,shipped,completed,cancelled',
            'tracking_number' => 'nullable|string|max:255',
            'carrier_company' => 'nullable|string|max:255',
            'carrier_name' => 'nullable|string|max:255',
            'carrier_phone' => 'nullable|string|max:50',
            'tracking_link' => 'nullable|url|max:500',
            'eta' => 'nullable|date',
        ]);

        $order = Order::where('shop_owner_id', $shopOwner->id)->find($id);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        // Update order status and shipping info
        $order->status = $request->status;
        
        if ($request->has('tracking_number')) {
            $order->tracking_number = $request->tracking_number;
        }
        
        if ($request->has('carrier_company')) {
            $order->carrier_company = $request->carrier_company;
        }
        
        if ($request->has('carrier_name')) {
            $order->carrier_name = $request->carrier_name;
        }
        
        if ($request->has('carrier_phone')) {
            $order->carrier_phone = $request->carrier_phone;
        }
        
        if ($request->has('tracking_link')) {
            $order->tracking_link = $request->tracking_link;
        }
        
        if ($request->has('eta')) {
            $order->eta = $request->eta;
        }
        
        $order->save();

        Log::info('Shop owner updated order status', [
            'order_id' => $id,
            'order_number' => $order->order_number,
            'new_status' => $request->status,
            'shop_owner_id' => $shopOwner->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'tracking_number' => $order->tracking_number,
                'updated_at' => $order->updated_at->toISOString(),
            ],
        ]);
    }
}
