<?php

namespace App\Services;

use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Models\StockMovement;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SupplierOrderService
{
    /**
     * Create a new supplier order
     */
    public function createOrder($data)
    {
        return DB::transaction(function () use ($data) {
            // Generate PO number if not provided
            if (empty($data['po_number'])) {
                $data['po_number'] = $this->generatePONumber($data['shop_owner_id']);
            }
            
            // Create order
            $order = SupplierOrder::create([
                'shop_owner_id' => $data['shop_owner_id'],
                'supplier_id' => $data['supplier_id'],
                'po_number' => $data['po_number'],
                'status' => $data['status'] ?? 'draft',
                'order_date' => $data['order_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'total_amount' => $data['total_amount'] ?? null,
                'currency' => $data['currency'] ?? 'PHP',
                'payment_status' => $data['payment_status'] ?? 'unpaid',
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $data['created_by']
            ]);
            
            // Create order items
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    SupplierOrderItem::create([
                        'supplier_order_id' => $order->id,
                        'inventory_item_id' => $itemData['inventory_item_id'] ?? null,
                        'product_name' => $itemData['product_name'],
                        'sku' => $itemData['sku'] ?? null,
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'] ?? null,
                        'total_price' => isset($itemData['unit_price']) 
                            ? $itemData['quantity'] * $itemData['unit_price'] 
                            : null,
                        'notes' => $itemData['notes'] ?? null
                    ]);
                }
            }
            
            return $order->load(['supplier', 'items']);
        });
    }
    
    /**
     * Update supplier order status
     */
    public function updateOrderStatus($orderId, $status, $userId, $remarks = null)
    {
        return DB::transaction(function () use ($orderId, $status, $userId, $remarks) {
            $order = SupplierOrder::lockForUpdate()->findOrFail($orderId);
            
            $oldStatus = $order->status;
            $order->status = $status;
            
            if ($remarks) {
                $order->remarks = ($order->remarks ? $order->remarks . "\n\n" : '') . 
                    now()->format('Y-m-d H:i') . ' - ' . $remarks;
            }
            
            // Set actual delivery date when status is delivered
            if ($status === 'delivered' && !$order->actual_delivery_date) {
                $order->actual_delivery_date = now();
            }
            
            $order->updated_by = $userId;
            $order->save();
            
            // Auto-generate stock movements when order is delivered
            if ($status === 'delivered' && $oldStatus !== 'delivered') {
                $this->generateStockMovementsFromOrder($order, $userId);
            }
            
            return $order->fresh(['supplier', 'items']);
        });
    }
    
    /**
     * Calculate days to delivery for an order
     */
    public function calculateDaysToDelivery($orderId)
    {
        $order = SupplierOrder::findOrFail($orderId);
        
        if (!$order->expected_delivery_date) {
            return null;
        }
        
        if ($order->actual_delivery_date) {
            return 0; // Already delivered
        }
        
        $expectedDate = Carbon::parse($order->expected_delivery_date);
        $today = Carbon::today();
        
        return $today->diffInDays($expectedDate, false);
    }
    
    /**
     * Get overdue orders
     */
    public function getOverdueOrders($shopOwnerId)
    {
        return SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->overdue()
            ->with(['supplier', 'items'])
            ->orderBy('expected_delivery_date', 'asc')
            ->get();
    }
    
    /**
     * Get orders due today
     */
    public function getDueToday($shopOwnerId)
    {
        return SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->dueToday()
            ->with(['supplier', 'items'])
            ->get();
    }
    
    /**
     * Get orders arriving soon
     */
    public function getArrivingSoon($shopOwnerId, $days = 3)
    {
        return SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->arrivingSoon($days)
            ->with(['supplier', 'items'])
            ->orderBy('expected_delivery_date', 'asc')
            ->get();
    }
    
    /**
     * Receive supplier order and update inventory
     */
    public function receiveOrder($orderId, $receivedItems, $userId)
    {
        return DB::transaction(function () use ($orderId, $receivedItems, $userId) {
            $order = SupplierOrder::lockForUpdate()->findOrFail($orderId);
            
            foreach ($receivedItems as $itemData) {
                $orderItem = SupplierOrderItem::where('supplier_order_id', $order->id)
                    ->findOrFail($itemData['id']);
                
                $quantityReceived = $itemData['quantity_received'];
                $orderItem->quantity_received = $quantityReceived;
                $orderItem->save();
                
                // Update inventory if item is linked
                if ($orderItem->inventory_item_id && $quantityReceived > 0) {
                    $inventoryItem = InventoryItem::lockForUpdate()
                        ->find($orderItem->inventory_item_id);
                    
                    if ($inventoryItem) {
                        $quantityBefore = $inventoryItem->available_quantity;
                        $quantityAfter = $quantityBefore + $quantityReceived;
                        
                        $inventoryItem->available_quantity = $quantityAfter;
                        $inventoryItem->updated_by = $userId;
                        $inventoryItem->save();
                        
                        // Create stock movement
                        StockMovement::create([
                            'inventory_item_id' => $inventoryItem->id,
                            'movement_type' => 'stock_in',
                            'quantity_change' => $quantityReceived,
                            'quantity_before' => $quantityBefore,
                            'quantity_after' => $quantityAfter,
                            'reference_type' => 'supplier_order',
                            'reference_id' => $order->id,
                            'notes' => "Received from PO: {$order->po_number}",
                            'performed_by' => $userId,
                            'performed_at' => now()
                        ]);
                        
                        // Check for alerts
                        app(InventoryService::class)->checkAndCreateAlerts($inventoryItem->id);
                    }
                }
            }
            
            // Update order status
            $order->status = 'delivered';
            $order->actual_delivery_date = now();
            $order->updated_by = $userId;
            $order->save();
            
            return $order->fresh(['supplier', 'items']);
        });
    }
    
    /**
     * Generate unique PO number
     */
    public function generatePONumber($shopOwnerId)
    {
        $prefix = 'PO-' . date('Ymd');
        
        $count = SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->where('po_number', 'like', $prefix . '%')
            ->count();
        
        return $prefix . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * Notify about overdue orders
     */
    public function notifyOverdueOrders()
    {
        $overdueOrders = SupplierOrder::overdue()
            ->with(['supplier', 'shopOwner.user'])
            ->get();
        
        $notificationsSent = 0;
        
        foreach ($overdueOrders as $order) {
            // Here you would send notifications
            // For now, we'll just count them
            // This would integrate with your notification system
            $notificationsSent++;
        }
        
        return [
            'total_overdue' => $overdueOrders->count(),
            'notifications_sent' => $notificationsSent
        ];
    }
    
    /**
     * Generate stock movements from order
     */
    protected function generateStockMovementsFromOrder($order, $userId)
    {
        foreach ($order->items as $item) {
            if ($item->inventory_item_id && $item->quantity > 0) {
                $inventoryItem = InventoryItem::lockForUpdate()
                    ->find($item->inventory_item_id);
                
                if ($inventoryItem) {
                    $quantityBefore = $inventoryItem->available_quantity;
                    $quantityAfter = $quantityBefore + $item->quantity;
                    
                    StockMovement::create([
                        'inventory_item_id' => $inventoryItem->id,
                        'movement_type' => 'stock_in',
                        'quantity_change' => $item->quantity,
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $quantityAfter,
                        'reference_type' => 'supplier_order',
                        'reference_id' => $order->id,
                        'notes' => "Auto-generated from PO: {$order->po_number}",
                        'performed_by' => $userId,
                        'performed_at' => now()
                    ]);
                    
                    $inventoryItem->available_quantity = $quantityAfter;
                    $inventoryItem->save();
                }
            }
        }
    }
    
    /**
     * Get supplier performance metrics
     */
    public function getSupplierPerformance($supplierId, $months = 6)
    {
        $startDate = Carbon::now()->subMonths($months);
        
        $orders = SupplierOrder::where('supplier_id', $supplierId)
            ->where('created_at', '>=', $startDate)
            ->get();
        
        $totalOrders = $orders->count();
        $completedOrders = $orders->where('status', 'completed')->count();
        $cancelledOrders = $orders->where('status', 'cancelled')->count();
        
        // Calculate on-time delivery
        $deliveredOrders = $orders->whereNotNull('actual_delivery_date');
        $onTimeDeliveries = $deliveredOrders->filter(function ($order) {
            return $order->actual_delivery_date <= $order->expected_delivery_date;
        })->count();
        
        $onTimeRate = $deliveredOrders->count() > 0 
            ? ($onTimeDeliveries / $deliveredOrders->count()) * 100 
            : 0;
        
        // Calculate average delivery time
        $avgDeliveryDays = $deliveredOrders->map(function ($order) {
            $orderDate = Carbon::parse($order->order_date);
            $deliveryDate = Carbon::parse($order->actual_delivery_date);
            return $orderDate->diffInDays($deliveryDate);
        })->avg();
        
        return [
            'period_months' => $months,
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'cancelled_orders' => $cancelledOrders,
            'completion_rate' => $totalOrders > 0 ? ($completedOrders / $totalOrders) * 100 : 0,
            'on_time_deliveries' => $onTimeDeliveries,
            'on_time_rate' => round($onTimeRate, 2),
            'avg_delivery_days' => round($avgDeliveryDays ?? 0, 1),
            'total_value' => $orders->sum('total_amount')
        ];
    }
    
    /**
     * Cancel supplier order
     */
    public function cancelOrder($orderId, $reason, $userId)
    {
        return DB::transaction(function () use ($orderId, $reason, $userId) {
            $order = SupplierOrder::lockForUpdate()->findOrFail($orderId);
            
            // Only allow cancelling non-delivered orders
            if (in_array($order->status, ['delivered', 'completed'])) {
                throw new \Exception('Cannot cancel delivered or completed orders');
            }
            
            $order->status = 'cancelled';
            $order->remarks = ($order->remarks ? $order->remarks . "\n\n" : '') . 
                now()->format('Y-m-d H:i') . " - CANCELLED: " . $reason;
            $order->updated_by = $userId;
            $order->save();
            
            return $order;
        });
    }
    
    /**
     * Get order fulfillment rate
     */
    public function getOrderFulfillmentRate($shopOwnerId, $startDate, $endDate)
    {
        $orders = SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->whereBetween('order_date', [$startDate, $endDate])
            ->with('items')
            ->get();
        
        $totalItemsOrdered = 0;
        $totalItemsReceived = 0;
        
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $totalItemsOrdered += $item->quantity;
                $totalItemsReceived += $item->quantity_received;
            }
        }
        
        $fulfillmentRate = $totalItemsOrdered > 0 
            ? ($totalItemsReceived / $totalItemsOrdered) * 100 
            : 0;
        
        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'total_orders' => $orders->count(),
            'total_items_ordered' => $totalItemsOrdered,
            'total_items_received' => $totalItemsReceived,
            'fulfillment_rate' => round($fulfillmentRate, 2),
            'pending_items' => $totalItemsOrdered - $totalItemsReceived
        ];
    }
}
