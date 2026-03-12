<?php

namespace App\Http\Controllers\ERP;

use App\Http\Controllers\Controller;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Models\StockMovement;
use App\Models\Finance\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupplierOrderController extends Controller
{
    /**
     * Display a listing of supplier orders
     */
    public function index(Request $request)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $orders = SupplierOrder::with(['supplier', 'items'])
            ->where('shop_owner_id', $shopOwnerId)
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();
        
        return response()->json($orders);
    }

    /**
     * Store a newly created supplier order
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'po_number' => 'nullable|string|max:100|unique:supplier_orders,po_number',
            'order_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after:order_date',
            'total_amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:3',
            'payment_status' => 'nullable|in:unpaid,partial,paid',
            'remarks' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'nullable|exists:inventory_items,id',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.sku' => 'nullable|string|max:100',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string'
        ]);
        
        $shopOwnerId = $request->user()->shop_owner_id;
        
        // Generate PO number if not provided
        if (empty($validated['po_number'])) {
            $validated['po_number'] = $this->generatePONumber($shopOwnerId);
        }
        
        DB::beginTransaction();
        try {
            // Create supplier order
            $order = SupplierOrder::create([
                'shop_owner_id' => $shopOwnerId,
                'supplier_id' => $validated['supplier_id'],
                'po_number' => $validated['po_number'],
                'status' => 'draft',
                'order_date' => $validated['order_date'],
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'total_amount' => $validated['total_amount'] ?? null,
                'currency' => $validated['currency'] ?? 'PHP',
                'payment_status' => $validated['payment_status'] ?? 'unpaid',
                'remarks' => $validated['remarks'] ?? null,
                'created_by' => $request->user()->id
            ]);
            
            // Create order items
            foreach ($validated['items'] as $itemData) {
                $totalPrice = isset($itemData['unit_price']) 
                    ? $itemData['quantity'] * $itemData['unit_price']
                    : null;
                
                SupplierOrderItem::create([
                    'supplier_order_id' => $order->id,
                    'inventory_item_id' => $itemData['inventory_item_id'] ?? null,
                    'product_name' => $itemData['product_name'],
                    'sku' => $itemData['sku'] ?? null,
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'] ?? null,
                    'total_price' => $totalPrice,
                    'notes' => $itemData['notes'] ?? null
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Supplier order created successfully',
                'order' => $order->load(['supplier', 'items'])
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error creating supplier order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified supplier order
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
     * Update the specified supplier order
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'expected_delivery_date' => 'nullable|date',
            'total_amount' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|in:unpaid,partial,paid',
            'remarks' => 'nullable|string',
            'items' => 'nullable|array'
        ]);
        
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $order = SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        // Don't allow updating completed or cancelled orders
        if (in_array($order->status, ['completed', 'cancelled'])) {
            return response()->json([
                'message' => 'Cannot update ' . $order->status . ' orders'
            ], 422);
        }
        
        DB::beginTransaction();
        try {
            $order->update(array_merge($validated, [
                'updated_by' => $request->user()->id
            ]));
            
            // Update items if provided
            if (isset($validated['items'])) {
                // Delete existing items
                $order->items()->delete();
                
                // Create new items
                foreach ($validated['items'] as $itemData) {
                    $totalPrice = isset($itemData['unit_price']) 
                        ? $itemData['quantity'] * $itemData['unit_price']
                        : null;
                    
                    SupplierOrderItem::create([
                        'supplier_order_id' => $order->id,
                        'inventory_item_id' => $itemData['inventory_item_id'] ?? null,
                        'product_name' => $itemData['product_name'],
                        'sku' => $itemData['sku'] ?? null,
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'] ?? null,
                        'total_price' => $totalPrice,
                        'notes' => $itemData['notes'] ?? null
                    ]);
                }
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Supplier order updated successfully',
                'order' => $order->fresh(['supplier', 'items'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error updating supplier order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified supplier order
     */
    public function destroy($id)
    {
        $shopOwnerId = request()->user()->shop_owner_id;
        
        $order = SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        // Only allow deleting draft orders
        if ($order->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft orders can be deleted. Cancel the order instead.'
            ], 422);
        }
        
        $order->delete();
        
        return response()->json([
            'message' => 'Supplier order deleted successfully'
        ]);
    }
    
    /**
     * Generate unique PO number
     */
    public function generatePO(Request $request)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        $poNumber = $this->generatePONumber($shopOwnerId);
        
        return response()->json([
            'po_number' => $poNumber
        ]);
    }
    
    /**
     * Receive supplier order and update stock
     */
    public function receiveOrder(Request $request, $id)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:supplier_order_items,id',
            'items.*.quantity_received' => 'required|integer|min:0'
        ]);
        
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $order = SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);
        
        DB::transaction(function () use ($order, $validated, $request) {
            foreach ($validated['items'] as $itemData) {
                $orderItem = SupplierOrderItem::where('supplier_order_id', $order->id)
                    ->findOrFail($itemData['id']);
                
                $orderItem->quantity_received = $itemData['quantity_received'];
                $orderItem->save();
                
                // Update inventory if item is linked
                if ($orderItem->inventory_item_id && $itemData['quantity_received'] > 0) {
                    $inventoryItem = $orderItem->inventoryItem;
                    $quantityBefore = $inventoryItem->available_quantity;
                    $quantityAfter = $quantityBefore + $itemData['quantity_received'];
                    
                    $inventoryItem->available_quantity = $quantityAfter;
                    $inventoryItem->save();
                    
                    // Create stock movement
                    StockMovement::create([
                        'inventory_item_id' => $inventoryItem->id,
                        'movement_type' => 'stock_in',
                        'quantity_change' => $itemData['quantity_received'],
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $quantityAfter,
                        'reference_type' => 'supplier_order',
                        'reference_id' => $order->id,
                        'notes' => "Received from PO: {$order->po_number}",
                        'performed_by' => $request->user()->id,
                        'performed_at' => now()
                    ]);
                }
            }
            
            // Update order status
            $order->status = 'delivered';
            $order->actual_delivery_date = now();
            $order->updated_by = $request->user()->id;
            $order->save();

            // Auto-create a draft Finance expense so Finance can review and post it
            // Only create if one doesn't already exist for this PO
            if (! $order->expense()->exists()) {
                $order->loadMissing('supplier');
                Expense::create([
                    'reference'           => 'PO-EXP-' . $order->po_number,
                    'date'                => now()->toDateString(),
                    'category'            => 'Procurement',
                    'vendor'              => $order->supplier?->name ?? null,
                    'description'         => 'Auto-generated from Purchase Order: ' . $order->po_number,
                    'amount'              => $order->total_amount ?? 0,
                    'tax_amount'          => 0,
                    'status'              => 'draft',
                    'shop_id'             => $order->shop_owner_id,
                    'purchase_order_id'   => $order->id,
                    'meta'                => [
                        'source'          => 'purchase_order',
                        'po_number'       => $order->po_number,
                        'created_by'      => $request->user()->id,
                    ],
                ]);
            }
        });
        
        return response()->json([
            'message' => 'Order received and stock updated successfully',
            'order' => $order->fresh(['supplier', 'items'])
        ]);
    }
    
    /**
     * Generate PO number helper
     */
    protected function generatePONumber($shopOwnerId)
    {
        $prefix = 'PO-' . date('Ymd');
        $count = SupplierOrder::where('shop_owner_id', $shopOwnerId)
            ->where('po_number', 'like', $prefix . '%')
            ->count();
        
        return $prefix . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }
}
