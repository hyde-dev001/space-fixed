<?php

namespace App\Http\Controllers\ERP;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Http\Requests\StorePurchaseRequestRequest;
use App\Http\Requests\ApprovePurchaseRequestRequest;
use App\Http\Requests\RejectPurchaseRequestRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class PurchaseRequestController extends Controller
{
    use AuthorizesRequests;
    /**
     * Display a listing of purchase requests with filters.
     */
    public function index(Request $request)
    {
        // $this->authorize('viewAny', PurchaseRequest::class);

        $query = PurchaseRequest::query()
            ->with(['shopOwner', 'supplier', 'inventoryItem', 'requester', 'reviewer', 'approver'])
            ->where('shop_owner_id', Auth::user()->shop_owner_id);

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('pr_number', 'LIKE', "%{$search}%")
                    ->orWhere('product_name', 'LIKE', "%{$search}%")
                    ->orWhereHas('supplier', function ($sq) use ($search) {
                        $sq->where('name', 'LIKE', "%{$search}%");
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

        // Supplier filter
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->whereDate('requested_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('requested_date', '<=', $request->date_to);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'requested_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $purchaseRequests = $query->paginate($request->get('per_page', 15));

        return response()->json($purchaseRequests);
    }

    /**
     * Store a newly created purchase request.
     */
    public function store(StorePurchaseRequestRequest $request)
    {
        // $this->authorize('create', PurchaseRequest::class);

        try {
            DB::beginTransaction();

            $data = $request->validated();
            $data['shop_owner_id'] = Auth::user()->shop_owner_id;
            $data['requested_by'] = Auth::id();
            $data['requested_date'] = now();
            $data['total_cost'] = $data['quantity'] * $data['unit_cost'];
            
            // Generate PR number
            $data['pr_number'] = $this->generatePRNumber();

            // Set status
            $data['status'] = $request->submit_to_finance ? 'pending_finance' : 'draft';

            $purchaseRequest = PurchaseRequest::create($data);

            DB::commit();

            return response()->json([
                'message' => 'Purchase request created successfully.',
                'purchase_request' => $purchaseRequest->load(['shopOwner', 'supplier', 'inventoryItem', 'requester'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create purchase request.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified purchase request.
     */
    public function show($id)
    {
        $purchaseRequest = PurchaseRequest::with([
            'shopOwner', 
            'supplier', 
            'inventoryItem', 
            'requester', 
            'reviewer', 
            'approver',
            'purchaseOrders'
        ])->findOrFail($id);

        // $this->authorize('view', $purchaseRequest);

        return response()->json($purchaseRequest);
    }

    /**
     * Update the specified purchase request.
     */
    public function update(StorePurchaseRequestRequest $request, $id)
    {
        $purchaseRequest = PurchaseRequest::findOrFail($id);
        
        // $this->authorize('update', $purchaseRequest);

        // Can only update if draft
        if ($purchaseRequest->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft purchase requests can be updated.'
            ], 403);
        }

        try {
            DB::beginTransaction();

            $data = $request->validated();
            $data['total_cost'] = $data['quantity'] * $data['unit_cost'];
            
            if ($request->submit_to_finance && $purchaseRequest->status === 'draft') {
                $data['status'] = 'pending_finance';
            }

            $purchaseRequest->update($data);

            DB::commit();

            return response()->json([
                'message' => 'Purchase request updated successfully.',
                'purchase_request' => $purchaseRequest->load(['shopOwner', 'supplier', 'inventoryItem', 'requester'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update purchase request.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified purchase request.
     */
    public function destroy($id)
    {
        $purchaseRequest = PurchaseRequest::findOrFail($id);
        
        // $this->authorize('delete', $purchaseRequest);

        // Can only delete if draft
        if ($purchaseRequest->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft purchase requests can be deleted.'
            ], 403);
        }

        $purchaseRequest->delete();

        return response()->json([
            'message' => 'Purchase request deleted successfully.'
        ]);
    }

    /**
     * Submit purchase request to finance.
     */
    public function submitToFinance($id)
    {
        $purchaseRequest = PurchaseRequest::findOrFail($id);
        
        // $this->authorize('submitToFinance', $purchaseRequest);

        if ($purchaseRequest->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft purchase requests can be submitted to finance.'
            ], 403);
        }

        $purchaseRequest->submitToFinance();

        return response()->json([
            'message' => 'Purchase request submitted to finance successfully.',
            'purchase_request' => $purchaseRequest->fresh()
        ]);
    }

    /**
     * Approve purchase request.
     */
    public function approve(ApprovePurchaseRequestRequest $request, $id)
    {
        $purchaseRequest = PurchaseRequest::findOrFail($id);
        
        // $this->authorize('approve', $purchaseRequest);

        if (!$purchaseRequest->canBeApproved()) {
            return response()->json([
                'message' => 'Purchase request cannot be approved in its current state.'
            ], 403);
        }

        try {
            $purchaseRequest->approve(Auth::id(), $request->approval_notes);

            return response()->json([
                'message' => 'Purchase request approved successfully.',
                'purchase_request' => $purchaseRequest->fresh(['shopOwner', 'supplier', 'inventoryItem', 'requester', 'approver'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to approve purchase request.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject purchase request.
     */
    public function reject(RejectPurchaseRequestRequest $request, $id)
    {
        $purchaseRequest = PurchaseRequest::findOrFail($id);
        
        // $this->authorize('reject', $purchaseRequest);

        if (!$purchaseRequest->canBeRejected()) {
            return response()->json([
                'message' => 'Purchase request cannot be rejected in its current state.'
            ], 403);
        }

        try {
            $purchaseRequest->reject(Auth::id(), $request->rejection_reason);

            return response()->json([
                'message' => 'Purchase request rejected successfully.',
                'purchase_request' => $purchaseRequest->fresh(['shopOwner', 'supplier', 'inventoryItem', 'requester', 'reviewer'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to reject purchase request.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get procurement metrics.
     */
    public function getMetrics()
    {
        // $this->authorize('viewAny', PurchaseRequest::class);

        $shopOwnerId = Auth::user()->shop_owner_id;

        $metrics = [
            'total_purchase_requests' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->count(),
            'pending_finance' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->pendingFinance()->count(),
            'approved_requests' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->approved()->count(),
            'rejected_requests' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->rejected()->count(),
            'draft_requests' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->draft()->count(),
            'total_value' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->sum('total_cost'),
            'approved_value' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->approved()->sum('total_cost'),
        ];

        return response()->json($metrics);
    }

    /**
     * Get approved purchase requests for PO creation.
     */
    public function getApprovedPRs()
    {
        // $this->authorize('viewAny', PurchaseRequest::class);

        $approvedPRs = PurchaseRequest::with(['supplier', 'inventoryItem', 'requester'])
            ->where('shop_owner_id', Auth::user()->shop_owner_id)
            ->approved()
            ->whereDoesntHave('purchaseOrders', function ($query) {
                $query->whereNotIn('status', ['cancelled']);
            })
            ->orderBy('approved_date', 'desc')
            ->get();

        return response()->json($approvedPRs);
    }

    /**
     * Generate unique PR number.
     */
    private function generatePRNumber()
    {
        $year = date('Y');
        $shopOwnerId = Auth::user()->shop_owner_id;
        
        $lastPR = PurchaseRequest::where('shop_owner_id', $shopOwnerId)
            ->where('pr_number', 'LIKE', "PR-{$year}-%")
            ->orderBy('pr_number', 'desc')
            ->first();

        if ($lastPR) {
            $lastNumber = intval(substr($lastPR->pr_number, -3));
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }

        return "PR-{$year}-{$newNumber}";
    }
}
