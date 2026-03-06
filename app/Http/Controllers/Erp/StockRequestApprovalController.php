<?php

namespace App\Http\Controllers\ERP;

use App\Http\Controllers\Controller;
use App\Models\StockRequestApproval;
use App\Models\InventoryItem;
use App\Http\Requests\ApproveStockRequestRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockRequestApprovalController extends Controller
{
    /**
     * Display a listing of stock request approvals.
     */
    public function index(Request $request)
    {
        // $this->authorize('viewAny', StockRequestApproval::class);

        $query = StockRequestApproval::query()
            ->with(['shopOwner', 'inventoryItem', 'requester', 'approver'])
            ->where('shop_owner_id', Auth::user()->shop_owner_id);

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('request_number', 'LIKE', "%{$search}%")
                    ->orWhere('product_name', 'LIKE', "%{$search}%")
                    ->orWhere('sku_code', 'LIKE', "%{$search}%")
                    ->orWhereHas('requester', function ($rq) use ($search) {
                        $rq->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Priority filter
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'requested_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $stockRequests = $query->paginate($request->get('per_page', 15));

        return response()->json($stockRequests);
    }

    /**
     * Display the specified stock request approval.
     */
    public function show($id)
    {
        $stockRequest = StockRequestApproval::with([
            'shopOwner', 
            'inventoryItem', 
            'requester', 
            'approver'
        ])->findOrFail($id);

        // $this->authorize('view', $stockRequest);

        return response()->json($stockRequest);
    }

    /**
     * Store a newly created stock request.
     */
    public function store(Request $request)
    {
        // $this->authorize('create', StockRequestApproval::class);

        $validated = $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'quantity_needed'   => 'required|integer|min:1',
            'priority'          => 'required|in:high,medium,low',
            'requested_size'    => 'nullable|string|max:20',
            'notes'             => 'nullable|string|max:1000',
        ]);

        $inventoryItem = InventoryItem::findOrFail($validated['inventory_item_id']);

        // Generate request number SR-YYYY-NNN
        $year = now()->year;
        $last = StockRequestApproval::where('request_number', 'LIKE', "SR-{$year}-%")
            ->orderBy('request_number', 'desc')
            ->first();
        $nextNum = $last ? intval(substr($last->request_number, -3)) + 1 : 1;
        $requestNumber = "SR-{$year}-" . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

        $user = Auth::user();

        $stockRequest = StockRequestApproval::create([
            'request_number'    => $requestNumber,
            'shop_owner_id'     => $user->shop_owner_id,
            'inventory_item_id' => $validated['inventory_item_id'],
            'product_name'      => $inventoryItem->name,
            'sku_code'          => $inventoryItem->sku ?? '',
            'quantity_needed'   => $validated['quantity_needed'],
            'requested_size'    => $validated['requested_size'] ?? null,
            'priority'          => $validated['priority'],
            'status'            => 'pending',
            'requested_by'      => $user->id,
            'requested_date'    => now(),
            'notes'             => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'message'       => 'Stock request submitted successfully.',
            'stock_request' => $stockRequest->load(['inventoryItem', 'requester']),
        ], 201);
    }
    public function approve(ApproveStockRequestRequest $request, $id)
    {
        $stockRequest = StockRequestApproval::findOrFail($id);
        
        // $this->authorize('approve', $stockRequest);

        if (!$stockRequest->canBeApproved()) {
            return response()->json([
                'message' => 'Stock request cannot be approved in its current state.'
            ], 403);
        }

        try {
            DB::beginTransaction();

            $stockRequest->approve(Auth::id(), $request->approval_notes);

            // TODO: Optionally auto-create PR if configured
            // if ($request->auto_create_pr) {
            //     // Create purchase request logic here
            // }

            DB::commit();

            return response()->json([
                'message' => 'Stock request approved successfully.',
                'stock_request' => $stockRequest->fresh(['shopOwner', 'inventoryItem', 'requester', 'approver'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to approve stock request.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject stock request.
     */
    public function reject(Request $request, $id)
    {
        $stockRequest = StockRequestApproval::findOrFail($id);
        
        // $this->authorize('reject', $stockRequest);

        if (!$stockRequest->canBeRejected()) {
            return response()->json([
                'message' => 'Stock request cannot be rejected in its current state.'
            ], 403);
        }

        $validatedData = $request->validate([
            'rejection_reason' => 'required|string|min:10',
        ]);

        try {
            $stockRequest->reject(Auth::id(), $validatedData['rejection_reason']);

            return response()->json([
                'message' => 'Stock request rejected successfully.',
                'stock_request' => $stockRequest->fresh(['shopOwner', 'inventoryItem', 'requester', 'approver'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to reject stock request.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Request additional details for stock request.
     */
    public function requestDetails(Request $request, $id)
    {
        $stockRequest = StockRequestApproval::findOrFail($id);
        
        // $this->authorize('approve', $stockRequest);

        $validatedData = $request->validate([
            'approval_notes' => 'required|string|min:10',
        ]);

        try {
            $stockRequest->requestDetails(Auth::id(), $validatedData['approval_notes']);

            return response()->json([
                'message' => 'Additional details requested successfully.',
                'stock_request' => $stockRequest->fresh(['shopOwner', 'inventoryItem', 'requester', 'approver'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to request additional details.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get stock request metrics.
     */
    public function getMetrics()
    {
        // $this->authorize('viewAny', StockRequestApproval::class);

        $shopOwnerId = Auth::user()->shop_owner_id;

        $metrics = [
            'total_stock_requests' => StockRequestApproval::where('shop_owner_id', $shopOwnerId)->count(),
            'pending_requests' => StockRequestApproval::where('shop_owner_id', $shopOwnerId)->pending()->count(),
            'accepted_requests' => StockRequestApproval::where('shop_owner_id', $shopOwnerId)->accepted()->count(),
            'rejected_requests' => StockRequestApproval::where('shop_owner_id', $shopOwnerId)->rejected()->count(),
            'needs_details' => StockRequestApproval::where('shop_owner_id', $shopOwnerId)->needsDetails()->count(),
        ];

        return response()->json($metrics);
    }
}
