<?php

namespace App\Http\Controllers\ERP;

use App\Http\Controllers\Controller;
use App\Models\SupplierOrder;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierOrderMonitoringController extends Controller
{
    /**
     * List all supplier orders with filters
     */
    public function index(Request $request)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $query = SupplierOrder::with(['supplier', 'items.inventoryItem'])
            ->where('shop_owner_id', $shopOwnerId);
        
        // Filter by status
        if ($request->status) {
            $query->where('status', $request->status);
        }
        
        // Filter by supplier
        if ($request->supplier_id) {
            $query->where('supplier_id', $request->supplier_id);
        }
        
        // Filter overdue orders
        if ($request->filter === 'overdue') {
            $query->overdue();
        }
        
        // Filter due today
        if ($request->filter === 'due_today') {
            $query->dueToday();
        }
        
        // Filter arriving soon
        if ($request->filter === 'arriving_soon') {
            $query->arrivingSoon(3);
        }
        
        // Search by PO number
        if ($request->search) {
            $query->where('po_number', 'like', "%{$request->search}%");
        }
        
        $orders = $query->orderBy('expected_delivery_date', 'asc')
            ->paginate($request->per_page ?? 20)
            ->withQueryString();
        
        return response()->json($orders);
    }
    
    /**
     * Show supplier order details
     */
    public function show($id)
    {
        $shopOwnerId = request()->user()->shop_owner_id;
        
        $order = SupplierOrder::with(['supplier', 'items.inventoryItem', 'creator', 'updater'])
            ->where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        return response()->json($order);
    }
    
    /**
     * Update order status
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:sent,confirmed,in_transit,delivered,completed,cancelled',
            'remarks' => 'nullable|string|max:1000'
        ]);
        
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $order = SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        DB::transaction(function () use ($order, $validated, $request) {
            $oldStatus = $order->status;
            $order->status = $validated['status'];
            
            if ($validated['remarks']) {
                $order->remarks = ($order->remarks ? $order->remarks . "\n\n" : '') . 
                    now()->format('Y-m-d H:i') . ' - ' . $validated['remarks'];
            }
            
            // Set actual delivery date when status is delivered
            if ($validated['status'] === 'delivered' && !$order->actual_delivery_date) {
                $order->actual_delivery_date = now();
            }
            
            $order->updated_by = $request->user()->id;
            $order->save();
            
            // Auto-generate stock movements when order is delivered
            if ($validated['status'] === 'delivered' && $oldStatus !== 'delivered') {
                $this->generateStockMovements($order, $request->user()->id);
            }
        });
        
        return response()->json([
            'message' => 'Order status updated successfully',
            'order' => $order->fresh(['supplier', 'items'])
        ]);
    }
    
    /**
     * Get order monitoring metrics
     */
    public function getMetrics(Request $request)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $activeOrders = SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', ['sent', 'confirmed', 'in_transit'])
            ->count();
        
        $dueToday = SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->dueToday()
            ->count();
        
        $overdue = SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->overdue()
            ->count();
        
        $arrivingSoon = SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->arrivingSoon(3)
            ->count();
        
        return response()->json([
            'active_orders' => $activeOrders,
            'due_today' => $dueToday,
            'overdue' => $overdue,
            'arriving_soon' => $arrivingSoon
        ]);
    }
    
    /**
     * Generate stock movements from supplier order
     */
    protected function generateStockMovements($order, $userId)
    {
        foreach ($order->items as $item) {
            if ($item->inventory_item_id && $item->quantity > 0) {
                $inventoryItem = $item->inventoryItem;
                
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
                    'notes' => "Received from PO: {$order->po_number}",
                    'performed_by' => $userId,
                    'performed_at' => now()
                ]);
                
                $inventoryItem->available_quantity = $quantityAfter;
                $inventoryItem->save();
            }
        }
    }
}
