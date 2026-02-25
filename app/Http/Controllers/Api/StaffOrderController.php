<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Services\NotificationService;

class StaffOrderController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user has STAFF or MANAGER role
        if (!in_array($user->role, ['STAFF', 'MANAGER'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get the shop owner ID for this STAFF user
        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        // Fetch orders ONLY for this shop with their items and related data
        $orders = Order::with(['items.product', 'customer', 'shopOwner'])
            ->where('shop_owner_id', $shopOwnerId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->customer_name ?? $order->customer?->name ?? 'Guest',
                    'customer_email' => $order->customer_email ?? $order->customer?->email ?? '',
                    'customer_phone' => $order->customer_phone ?? '',
                    'shipping_address' => $order->customer_address ?? '',
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
                    'items' => $order->items->map(function ($item) {
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
                    'shop' => $order->shopOwner ? [
                        'id' => $order->shopOwner->id,
                        'shop_name' => $order->shopOwner->shop_name,
                    ] : null,
                ];
            });

        return response()->json($orders);
    }

    public function show($id)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user has STAFF or MANAGER role
        if (!in_array($user->role, ['STAFF', 'MANAGER'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get the shop owner ID for this STAFF user
        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        // Fetch order ONLY if it belongs to this shop
        $order = Order::with(['items.product', 'customer', 'shopOwner'])
            ->where('shop_owner_id', $shopOwnerId)
            ->where('id', $id)
            ->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json([
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name ?? $order->customer?->name ?? 'Guest',
            'customer_email' => $order->customer_email ?? $order->customer?->email ?? '',
            'customer_phone' => $order->customer_phone ?? '',
            'shipping_address' => $order->customer_address ?? '',
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
            'items' => $order->items->map(function ($item) {
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
            'shop' => $order->shopOwner ? [
                'id' => $order->shopOwner->id,
                'shop_name' => $order->shopOwner->shop_name,
            ] : null,
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:pending,processing,shipped,completed,cancelled',
                'tracking_number' => 'nullable|string|max:255',
                'carrier_company' => 'nullable|string|max:255',
                'carrier_name' => 'nullable|string|max:255',
                'carrier_phone' => 'nullable|string|max:50',
                'tracking_link' => 'nullable|url|max:500',
                'eta' => 'nullable|string|max:255',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed for order status update', [
                'errors' => $e->errors(),
                'input' => $request->all(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user has STAFF or MANAGER role
        if (!in_array($user->role, ['STAFF', 'MANAGER'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get the shop owner ID for this STAFF user
        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        // Fetch order ONLY if it belongs to this shop
        $order = Order::where('shop_owner_id', $shopOwnerId)
            ->where('id', $id)
            ->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        // Store the old status for comparison (convert enum to string value)
        $oldStatus = $order->status->value;

        // Update order status and shipping info
        $order->status = $validated['status'];
        
        if (isset($validated['tracking_number'])) {
            $order->tracking_number = $validated['tracking_number'];
        }
        
        if (isset($validated['carrier_company'])) {
            $order->carrier_company = $validated['carrier_company'];
        }
        
        if (isset($validated['carrier_name'])) {
            $order->carrier_name = $validated['carrier_name'];
        }
        
        if (isset($validated['carrier_phone'])) {
            $order->carrier_phone = $validated['carrier_phone'];
        }
        
        if (isset($validated['tracking_link'])) {
            $order->tracking_link = $validated['tracking_link'];
        }
        
        if (isset($validated['eta'])) {
            $order->eta = $validated['eta'];
        }
        
        $order->save();

        // Log the status change with business context
        activity()
            ->causedBy($user)
            ->performedOn($order)
            ->withProperties([
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name ?? 'N/A',
                'old_status' => $oldStatus,
                'new_status' => $validated['status'],
                'total_amount' => $order->total_amount,
                'updated_by_name' => $user->name,
                'updated_by_role' => $user->role,
                'tracking_number' => $validated['tracking_number'] ?? null,
                'carrier_company' => $validated['carrier_company'] ?? null,
            ])
            ->log("Order status updated from {$oldStatus} to {$validated['status']}");

        // Auto-assign to staff when status changes to 'processing' (if not already assigned)
        if ($validated['status'] === 'processing' && !$order->assigned_staff_id) {
            $this->autoAssignStaff($order);
        }

        Log::info('Order status updated', [
            'order_id' => $id,
            'order_number' => $order->order_number,
            'old_status' => $oldStatus,
            'new_status' => $validated['status'],
            'user_id' => $user->id,
            'user_role' => $user->role,
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

    public function complete(Request $request, $id)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user has STAFF or MANAGER role
        if (!in_array($user->role, ['STAFF', 'MANAGER'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get the shop owner ID for this STAFF user
        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        // Fetch order ONLY if it belongs to this shop
        $order = Order::with(['items', 'customer', 'shopOwner'])
            ->where('shop_owner_id', $shopOwnerId)
            ->where('id', $id)
            ->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        // Update order status to completed
        $order->status = 'completed';
        $order->save();

        Log::info('Order completed', [
            'order_id' => $id,
            'order_number' => $order->order_number,
            'total_amount' => $order->total_amount,
            'user_id' => $user->id,
            'user_role' => $user->role,
        ]);

        // TODO: Generate invoice if needed
        // You can implement invoice generation logic here

        return response()->json([
            'success' => true,
            'message' => 'Order completed successfully',
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name ?? $order->customer?->name ?? 'Guest',
                'customer_email' => $order->customer_email ?? $order->customer?->email ?? '',
                'total_amount' => $order->total_amount,
                'status' => 'completed',
            ],
        ]);
    }

    /**
     * Activate pickup confirmation for an order
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function activatePickup(Request $request, $id)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            // Check if user has STAFF or MANAGER role
            if (!in_array($user->role, ['STAFF', 'MANAGER'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $order = Order::find($id);
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }
            
            // Get the shop owner ID for this STAFF user
            $shopOwnerId = $user->shop_owner_id ?? $user->id;
            
            // Verify order belongs to this shop
            if ($order->shop_owner_id != $shopOwnerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order does not belong to your shop'
                ], 403);
            }
            
            // Check if status is shipped
            $currentStatus = $order->status instanceof \App\Enums\OrderStatus ? $order->status->value : $order->status;
            if ($currentStatus !== 'shipped') {
                return response()->json([
                    'success' => false,
                    'message' => 'Pickup can only be activated when order is shipped. Current status: ' . $currentStatus
                ], 400);
            }
            
            // Check if pickup is already enabled
            if ($order->pickup_enabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pickup confirmation is already activated'
                ], 400);
            }
            
            // Enable pickup confirmation
            $order->update([
                'pickup_enabled' => true,
                'pickup_enabled_at' => now(),
                'pickup_enabled_by' => $user->id,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Pickup confirmation activated. Customer can now confirm they received their order.',
                'order' => $order->fresh()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error activating pickup for order: ' . $e->getMessage(), [
                'order_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate pickup confirmation'
            ], 500);
        }
    }

    /**
     * Auto-assign order to staff based on active workload
     * Similar to the repairer auto-assignment system
     * 
     * @param Order $order
     * @return bool
     */
    private function autoAssignStaff(Order $order): bool
    {
        try {
            Log::info('Starting auto-assignment for order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'shop_owner_id' => $order->shop_owner_id,
            ]);

            // Find staff member with least active workload
            $staff = User::where('shop_owner_id', $order->shop_owner_id)
                ->whereHas('roles', function($query) {
                    $query->where('name', 'Staff');
                })
                ->withCount(['assignedOrders as active_orders_count' => function($query) {
                    // Count only active orders (pending and processing)
                    $query->whereIn('status', ['pending', 'processing']);
                }])
                ->having('active_orders_count', '<', 10) // Max capacity: 10 active orders
                ->orderBy('active_orders_count', 'asc')
                ->first();

            // Fallback: if all staff at capacity, assign to staff with least orders anyway
            if (!$staff) {
                Log::warning('All staff at capacity, assigning to least busy staff anyway', [
                    'order_id' => $order->id,
                ]);

                $staff = User::where('shop_owner_id', $order->shop_owner_id)
                    ->whereHas('roles', function($query) {
                        $query->where('name', 'Staff');
                    })
                    ->withCount(['assignedOrders as active_orders_count' => function($query) {
                        $query->whereIn('status', ['pending', 'processing']);
                    }])
                    ->orderBy('active_orders_count', 'asc')
                    ->first();
            }

            if (!$staff) {
                Log::error('No staff members available for assignment', [
                    'order_id' => $order->id,
                    'shop_owner_id' => $order->shop_owner_id,
                ]);
                return false;
            }

            // Assign the order to the staff member
            $order->update([
                'assigned_staff_id' => $staff->id,
                'assigned_at' => now(),
                'assignment_method' => 'auto',
            ]);

            Log::info('Order auto-assigned successfully', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'staff_id' => $staff->id,
                'staff_name' => $staff->name,
                'active_orders_count' => $staff->active_orders_count ?? 0,
            ]);

            // Notify the assigned staff member
            $notificationService = app(NotificationService::class);
            $notificationService->notifyAssignedStaff($order, $staff);

            return true;

        } catch (\Exception $e) {
            Log::error('Error during auto-assignment', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }
}

