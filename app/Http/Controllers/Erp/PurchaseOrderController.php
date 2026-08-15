<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Requests\UpdatePurchaseOrderStatusRequest;
use App\Http\Requests\CancelPurchaseOrderRequest;
use App\Events\PurchaseOrderCompleted;
use App\Events\PurchaseOrderSent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Services\PurchaseOrderService;

class PurchaseOrderController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private PurchaseOrderService $purchaseOrderService) {}

    /**
     * Display a listing of purchase orders with filters.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $query = PurchaseOrder::query()
            ->with(['items.purchaseRequest', 'items.inventoryItem.sizes', 'receipts.items', 'shopOwner', 'supplier', 'orderer'])
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
        $this->authorize('create', PurchaseOrder::class);

        $purchaseOrder = $this->purchaseOrderService->createPurchaseOrder([
            ...$request->validated(),
            'shop_owner_id' => Auth::user()->shop_owner_id,
            'ordered_by' => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Purchase order created.',
            'data' => $purchaseOrder,
        ], 201);
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
            'completer',
            'items.purchaseRequest',
            'items.inventoryItem.sizes',
            'receipts.items',
        ])->where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);

        $this->authorize('view', $purchaseOrder);

        return response()->json($purchaseOrder);
    }

    /**
     * Update the specified purchase order.
     */
    public function update(Request $request, $id)
    {
        $purchaseOrder = PurchaseOrder::where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);
        
        $this->authorize('update', $purchaseOrder);

        // Can only update if draft
        if ($purchaseOrder->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft purchase orders can be updated.'
            ], 403);
        }

        $validatedData = $request->validate([
            'expected_delivery_date' => 'nullable|date|after_or_equal:today',
            'payment_terms' => 'sometimes|string|max:255',
            'notes' => 'nullable|string',
        ]);

        try {
            $purchaseOrder->update($validatedData);

            return response()->json([
                'message' => 'Purchase order updated successfully.',
                'data' => $purchaseOrder->fresh(['items.purchaseRequest', 'supplier', 'orderer'])
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
        $purchaseOrder = PurchaseOrder::where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);
        
        $this->authorize('delete', $purchaseOrder);

        // Can only delete if draft
        if ($purchaseOrder->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft purchase orders can be deleted.'
            ], 403);
        }

        $purchaseOrder->delete();

        return response()->json([
            'message' => 'Purchase order deleted successfully.',
            'data' => $purchaseOrder
        ]);
    }

    /**
     * Update purchase order status.
     */
    public function updateStatus(UpdatePurchaseOrderStatusRequest $request, $id)
    {
        $purchaseOrder = PurchaseOrder::where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);
        
        $this->authorize($request->status === 'completed' ? 'complete' : 'updateStatus', $purchaseOrder);

        $purchaseOrder = $this->purchaseOrderService->updateStatus(
            $purchaseOrder->id,
            $request->status,
            (int) Auth::id(),
            $request->only('notes')
        );

        if ($request->status === 'completed') {
            event(new PurchaseOrderCompleted($purchaseOrder));
        } elseif ($request->status === 'sent') {
            event(new PurchaseOrderSent($purchaseOrder));
        }

        return response()->json([
            'message' => "Purchase order marked as {$request->status} successfully.",
            'data' => $purchaseOrder->load(['items.purchaseRequest', 'supplier']),
        ]);
    }

    /**
     * Send purchase order to supplier.
     */
    public function sendToSupplier($id)
    {
        $purchaseOrder = PurchaseOrder::where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);
        
        $this->authorize('updateStatus', $purchaseOrder);

        if ($purchaseOrder->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft purchase orders can be sent to supplier.'
            ], 403);
        }

        try {
            $purchaseOrder = $this->purchaseOrderService->sendToSupplier($purchaseOrder->id);
            event(new PurchaseOrderSent($purchaseOrder));

            return response()->json([
                'message' => 'Purchase order sent to supplier successfully.',
                'data' => $purchaseOrder->fresh()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to send purchase order.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel purchase order.
     */
    public function cancel(CancelPurchaseOrderRequest $request, $id)
    {
        $purchaseOrder = PurchaseOrder::where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);
        
        $this->authorize('cancel', $purchaseOrder);

        $purchaseOrder = $this->purchaseOrderService->cancelPurchaseOrder(
            $purchaseOrder->id,
            (int) Auth::id(),
            $request->cancellation_reason
        );

        return response()->json([
            'message' => 'Purchase order cancelled successfully.',
            'data' => $purchaseOrder,
        ]);
    }

    /**
     * Get purchase order metrics.
     */
    public function getMetrics()
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $shopOwnerId = Auth::user()->shop_owner_id;

        return response()->json($this->purchaseOrderService->getMetrics((int) $shopOwnerId));
    }

}
