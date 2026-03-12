<?php

namespace App\Http\Controllers\ERP;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Requests\UpdatePurchaseOrderStatusRequest;
use App\Http\Requests\CancelPurchaseOrderRequest;
use App\Events\PurchaseOrderCompleted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    /**
     * Display a listing of purchase orders with filters.
     */
    public function index(Request $request)
    {
        // $this->authorize('viewAny', PurchaseOrder::class);

        $query = PurchaseOrder::query()
            ->with(['purchaseRequest', 'shopOwner', 'supplier', 'inventoryItem', 'orderer'])
            ->where('shop_owner_id', Auth::user()->shop_owner_id);

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'LIKE', "%{$search}%")
                    ->orWhere('product_name', 'LIKE', "%{$search}%")
                    ->orWhereHas('supplier', function ($sq) use ($search) {
                        $sq->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('purchaseRequest', function ($prq) use ($search) {
                        $prq->where('pr_number', 'LIKE', "%{$search}%");
                    });
            });
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Supplier filter
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->whereDate('ordered_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('ordered_date', '<=', $request->date_to);
        }

        // Overdue filter
        if ($request->boolean('overdue_only')) {
            $query->overdue();
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'ordered_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $purchaseOrders = $query->paginate($request->get('per_page', 15));

        return response()->json($purchaseOrders);
    }

    /**
     * Store a newly created purchase order.
     */
    public function store(StorePurchaseOrderRequest $request)
    {
        // $this->authorize('create', PurchaseOrder::class);

        try {
            DB::beginTransaction();

            $purchaseRequest = PurchaseRequest::findOrFail($request->pr_id);

            // Verify PR is approved
            if ($purchaseRequest->status !== 'approved') {
                return response()->json([
                    'message' => 'Purchase request must be approved before creating a purchase order.'
                ], 403);
            }

            $data = $request->validated();
            $data['shop_owner_id'] = Auth::user()->shop_owner_id;
            $data['supplier_id'] = $purchaseRequest->supplier_id;
            $data['product_name'] = $purchaseRequest->product_name;
            $data['inventory_item_id'] = $purchaseRequest->inventory_item_id;
            $data['requested_size'] = $purchaseRequest->requested_size;
            $data['quantity'] = $purchaseRequest->quantity;
            $data['unit_cost'] = $purchaseRequest->unit_cost;
            $data['total_cost'] = $purchaseRequest->total_cost;
            $data['ordered_by'] = Auth::id();
            $data['ordered_date'] = now();
            $data['status'] = 'draft';
            
            // Generate PO number
            $data['po_number'] = $this->generatePONumber();

            $purchaseOrder = PurchaseOrder::create($data);

            DB::commit();

            return response()->json([
                'message' => 'Purchase order created successfully.',
                'purchase_order' => $purchaseOrder->load(['purchaseRequest', 'shopOwner', 'supplier', 'inventoryItem', 'orderer'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create purchase order.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified purchase order.
     */
    public function show($id)
    {
        $purchaseOrder = PurchaseOrder::with([
            'purchaseRequest.requester',
            'purchaseRequest.approver',
            'shopOwner', 
            'supplier', 
            'inventoryItem', 
            'orderer',
            'confirmer',
            'deliverer',
            'completer'
        ])->findOrFail($id);

        // $this->authorize('view', $purchaseOrder);

        return response()->json($purchaseOrder);
    }

    /**
     * Update the specified purchase order.
     */
    public function update(Request $request, $id)
    {
        $purchaseOrder = PurchaseOrder::findOrFail($id);
        
        // $this->authorize('update', $purchaseOrder);

        // Can only update if draft
        if ($purchaseOrder->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft purchase orders can be updated.'
            ], 403);
        }

        $validatedData = $request->validate([
            'expected_delivery_date' => 'nullable|date|after:today',
            'payment_terms' => 'sometimes|string|max:255',
            'notes' => 'nullable|string',
        ]);

        try {
            $purchaseOrder->update($validatedData);

            return response()->json([
                'message' => 'Purchase order updated successfully.',
                'purchase_order' => $purchaseOrder->fresh(['purchaseRequest', 'shopOwner', 'supplier', 'inventoryItem', 'orderer'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update purchase order.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified purchase order.
     */
    public function destroy($id)
    {
        $purchaseOrder = PurchaseOrder::findOrFail($id);
        
        // $this->authorize('delete', $purchaseOrder);

        // Can only delete if draft
        if ($purchaseOrder->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft purchase orders can be deleted.'
            ], 403);
        }

        $purchaseOrder->delete();

        return response()->json([
            'message' => 'Purchase order deleted successfully.'
        ]);
    }

    /**
     * Update purchase order status.
     */
    public function updateStatus(UpdatePurchaseOrderStatusRequest $request, $id)
    {
        $purchaseOrder = PurchaseOrder::findOrFail($id);
        
        // $this->authorize('updateStatus', $purchaseOrder);

        if (!$purchaseOrder->canProgressStatus()) {
            return response()->json([
                'message' => 'Purchase order cannot progress from its current status.'
            ], 403);
        }

        try {
            DB::beginTransaction();

            $status = $request->status;
            $userId = Auth::id();

            switch ($status) {
                case 'sent':
                    $purchaseOrder->sendToSupplier();
                    break;
                case 'confirmed':
                    $purchaseOrder->markAsConfirmed($userId);
                    break;
                case 'in_transit':
                    $purchaseOrder->markAsInTransit($userId);
                    break;
                case 'delivered':
                    $purchaseOrder->markAsDelivered($userId, $request->actual_delivery_date ?? now()->toDateString());
                    $purchaseOrder->updateInventoryOnDelivery();
                    break;
                case 'completed':
                    $purchaseOrder->markAsCompleted($userId);
                    event(new PurchaseOrderCompleted($purchaseOrder));
                    break;
                default:
                    return response()->json(['message' => 'Invalid status.'], 400);
            }

            if ($request->filled('notes')) {
                $purchaseOrder->notes = $request->notes;
                $purchaseOrder->save();
            }

            DB::commit();

            return response()->json([
                'message' => "Purchase order marked as {$status} successfully.",
                'purchase_order' => $purchaseOrder->fresh(['purchaseRequest', 'shopOwner', 'supplier', 'inventoryItem'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update purchase order status.',
                'error' => $e->getMessage()
                ], 500);
        }
    }

    /**
     * Send purchase order to supplier.
     */
    public function sendToSupplier($id)
    {
        $purchaseOrder = PurchaseOrder::findOrFail($id);
        
        // $this->authorize('updateStatus', $purchaseOrder);

        if ($purchaseOrder->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft purchase orders can be sent to supplier.'
            ], 403);
        }

        try {
            $purchaseOrder->sendToSupplier();

            return response()->json([
                'message' => 'Purchase order sent to supplier successfully.',
                'purchase_order' => $purchaseOrder->fresh()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to send purchase order.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark purchase order as delivered.
     */
    public function markAsDelivered(Request $request, $id)
    {
        $purchaseOrder = PurchaseOrder::findOrFail($id);
        
        // $this->authorize('updateStatus', $purchaseOrder);

        $validatedData = $request->validate([
            'actual_delivery_date' => 'required|date',
            'received_quantity'    => 'required|integer|min:0',
            'defective_quantity'   => 'required|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validatedData['defective_quantity'] > $validatedData['received_quantity']) {
            return response()->json(['message' => 'Defective quantity cannot exceed received quantity.'], 422);
        }

        if ($purchaseOrder->status !== 'in_transit') {
            return response()->json([
                'message' => 'Only in-transit purchase orders can be marked as delivered.'
            ], 403);
        }

        try {
            DB::beginTransaction();

            $purchaseOrder->received_quantity  = $validatedData['received_quantity'];
            $purchaseOrder->defective_quantity  = $validatedData['defective_quantity'];
            $purchaseOrder->save();

            $purchaseOrder->markAsDelivered(Auth::id(), $validatedData['actual_delivery_date']);
            $purchaseOrder->updateInventoryOnDelivery();

            if (isset($validatedData['notes'])) {
                $purchaseOrder->notes = $validatedData['notes'];
                $purchaseOrder->save();
            }

            DB::commit();

            return response()->json([
                'message' => 'Purchase order marked as delivered successfully.',
                'purchase_order' => $purchaseOrder->fresh(['purchaseRequest', 'shopOwner', 'supplier', 'inventoryItem'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to mark purchase order as delivered.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel purchase order.
     */
    public function cancel(CancelPurchaseOrderRequest $request, $id)
    {
        $purchaseOrder = PurchaseOrder::findOrFail($id);
        
        // $this->authorize('cancel', $purchaseOrder);

        if (in_array($purchaseOrder->status, ['delivered', 'completed', 'cancelled'])) {
            return response()->json([
                'message' => 'Purchase order cannot be cancelled in its current state.'
            ], 403);
        }

        try {
            $purchaseOrder->cancel(Auth::id(), $request->cancellation_reason);

            return response()->json([
                'message' => 'Purchase order cancelled successfully.',
                'purchase_order' => $purchaseOrder->fresh()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to cancel purchase order.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get purchase order metrics.
     */
    public function getMetrics()
    {
        // $this->authorize('viewAny', PurchaseOrder::class);

        $shopOwnerId = Auth::user()->shop_owner_id;

        $metrics = [
            'total_purchase_orders' => PurchaseOrder::where('shop_owner_id', $shopOwnerId)->count(),
            'active_orders' => PurchaseOrder::where('shop_owner_id', $shopOwnerId)->active()->count(),
            'completed_orders' => PurchaseOrder::where('shop_owner_id', $shopOwnerId)->completed()->count(),
            'cancelled_orders' => PurchaseOrder::where('shop_owner_id', $shopOwnerId)->cancelled()->count(),
            'overdue_orders' => PurchaseOrder::where('shop_owner_id', $shopOwnerId)->overdue()->count(),
            'total_value' => PurchaseOrder::where('shop_owner_id', $shopOwnerId)->sum('total_cost'),
            'completed_value' => PurchaseOrder::where('shop_owner_id', $shopOwnerId)->completed()->sum('total_cost'),
        ];

        return response()->json($metrics);
    }

    /**
     * Generate unique PO number.
     */
    private function generatePONumber()
    {
        $year = date('Y');
        $shopOwnerId = Auth::user()->shop_owner_id;
        
        $lastPO = PurchaseOrder::where('shop_owner_id', $shopOwnerId)
            ->where('po_number', 'LIKE', "PO-{$year}-%")
            ->orderBy('po_number', 'desc')
            ->first();

        if ($lastPO) {
            $lastNumber = intval(substr($lastPO->po_number, -3));
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }

        return "PO-{$year}-{$newNumber}";
    }
}
