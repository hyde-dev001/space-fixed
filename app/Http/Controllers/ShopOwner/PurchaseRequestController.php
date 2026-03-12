<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PurchaseRequestController extends Controller
{
    /**
     * Get the currently authenticated shop owner.
     */
    private function shopOwner()
    {
        return Auth::guard('shop_owner')->user();
    }

    /**
     * List purchase requests belonging to this shop owner.
     * Supports ?status=pending_shop_owner filter.
     */
    public function index(Request $request)
    {
        $shopOwner = $this->shopOwner();

        $query = PurchaseRequest::query()
            ->with(['supplier', 'inventoryItem', 'requester', 'reviewer', 'approver'])
            ->where('shop_owner_id', $shopOwner->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('pr_number', 'LIKE', "%{$search}%")
                    ->orWhere('product_name', 'LIKE', "%{$search}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'LIKE', "%{$search}%"));
            });
        }

        $sortBy    = $request->get('sort_by', 'requested_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $purchaseRequests = $query->paginate($request->get('per_page', 50));

        return response()->json($purchaseRequests);
    }

    /**
     * Shop owner final approval for a purchase request.
     */
    public function approve(Request $request, $id)
    {
        $shopOwner = $this->shopOwner();

        $purchaseRequest = PurchaseRequest::where('shop_owner_id', $shopOwner->id)
            ->findOrFail($id);

        if ($purchaseRequest->status !== 'pending_shop_owner') {
            return response()->json([
                'message' => 'Only requests pending shop owner approval can be approved.',
                'current_status' => $purchaseRequest->status,
            ], 403);
        }

        $request->validate([
            'approval_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $purchaseRequest->approve(
                $shopOwner->id,
                $request->approval_notes,
                'shop_owner'
            );

            return response()->json([
                'message' => 'Purchase request approved by Shop Owner. Ready for procurement.',
                'purchase_request' => $purchaseRequest->fresh(['supplier', 'inventoryItem', 'requester', 'reviewer', 'approver']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to approve purchase request.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Shop owner rejection of a purchase request.
     */
    public function reject(Request $request, $id)
    {
        $shopOwner = $this->shopOwner();

        $purchaseRequest = PurchaseRequest::where('shop_owner_id', $shopOwner->id)
            ->findOrFail($id);

        if (!in_array($purchaseRequest->status, ['pending_shop_owner', 'pending_finance'])) {
            return response()->json([
                'message' => 'This request cannot be rejected in its current state.',
                'current_status' => $purchaseRequest->status,
            ], 403);
        }

        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        try {
            $purchaseRequest->reject($shopOwner->id, $request->rejection_reason);

            return response()->json([
                'message' => 'Purchase request rejected and returned to procurement.',
                'purchase_request' => $purchaseRequest->fresh(['supplier', 'inventoryItem', 'requester', 'reviewer']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to reject purchase request.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
