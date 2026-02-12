<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceChangeRequest;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PriceChangeRequestController extends Controller
{
    /**
     * Calculate metrics for shop owner or finance
     */
    private function calculateMetrics($shopOwnerId, $role = 'finance')
    {
        $query = PriceChangeRequest::where('shop_owner_id', $shopOwnerId);

        if ($role === 'shop_owner') {
            return [
                'pending' => (clone $query)->where('status', 'finance_approved')->count(),
                'approved' => (clone $query)->where('status', 'owner_approved')->count(),
                'rejected' => (clone $query)->where('status', 'owner_rejected')->count(),
                'total' => $query->whereIn('status', ['finance_approved', 'owner_approved', 'owner_rejected'])->count(),
            ];
        }

        // Finance metrics
        return [
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'finance_approved' => (clone $query)->where('status', 'finance_approved')->count(),
            'owner_approved' => (clone $query)->where('status', 'owner_approved')->count(),
            'rejected' => (clone $query)->whereIn('status', ['finance_rejected', 'owner_rejected'])->count(),
            'total' => $query->count(),
        ];
    }

    /**
     * Create a new price change request
     * If a pending request exists for this product, it will be replaced
     */
    public function store(Request $request, $productId)
    {
        $request->validate([
            'product_name' => 'required|string',
            'current_price' => 'required|numeric|min:0',
            'proposed_price' => 'required|numeric|min:0',
            'reason' => 'required|string|max:1000',
        ]);

        $product = Product::findOrFail($productId);
        
        // Get shop owner id from product
        $shopOwnerId = $product->shop_owner_id ?? auth()->user()->shop_owner_id;

        DB::beginTransaction();
        try {
            // Check for existing pending or in-progress requests for this product
            $existingRequest = PriceChangeRequest::where('product_id', $productId)
                ->whereIn('status', ['pending', 'finance_approved'])
                ->first();

            if ($existingRequest) {
                // Update existing request instead of creating new one
                $existingRequest->update([
                    'product_name' => $request->product_name,
                    'current_price' => $request->current_price,
                    'proposed_price' => $request->proposed_price,
                    'reason' => $request->reason,
                    'requested_by' => Auth::id(),
                    'status' => 'pending', // Reset to pending
                    'finance_reviewed_by' => null,
                    'finance_reviewed_at' => null,
                    'owner_reviewed_by' => null,
                    'owner_reviewed_at' => null,
                    'created_at' => now(), // Update timestamp
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Price change request updated successfully',
                    'request' => $existingRequest->load(['product', 'requester']),
                    'replaced' => true,
                ], 200);
            }

            // Create new request if no existing one
            $priceChangeRequest = PriceChangeRequest::create([
                'product_id' => $productId,
                'product_name' => $request->product_name,
                'current_price' => $request->current_price,
                'proposed_price' => $request->proposed_price,
                'reason' => $request->reason,
                'requested_by' => Auth::id(),
                'shop_owner_id' => $shopOwnerId,
                'status' => 'pending',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Price change request submitted successfully',
                'request' => $priceChangeRequest->load(['product', 'requester']),
                'replaced' => false,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error creating/updating price change request: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit price change request',
            ], 500);
        }
    }

    /**
     * Get all price change requests (for Finance)
     */
    public function index(Request $request)
    {
        $status = $request->query('status', 'all');
        $shopOwnerId = auth()->user()->shop_owner_id;

        $query = PriceChangeRequest::with(['product', 'requester', 'financeReviewer', 'ownerReviewer'])
            ->where('shop_owner_id', $shopOwnerId)
            ->orderBy('created_at', 'desc');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $requests = $query->get();

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }

    /**
     * Get pending requests for owner approval
     */
    public function ownerPending()
    {
        // Get authenticated shop owner
        $shopOwner = Auth::guard('shop_owner')->user();
        
        if (!$shopOwner) {
            \Log::error('Shop Owner not authenticated');
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Shop Owner authentication required',
            ], 401);
        }

        \Log::info('Shop Owner authenticated', [
            'shop_owner_id' => $shopOwner->id,
        ]);

        $requests = PriceChangeRequest::with(['product', 'requester', 'financeReviewer'])
            ->where('shop_owner_id', $shopOwner->id)
            ->where('status', 'finance_approved')
            ->orderBy('finance_reviewed_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }

    /**
     * Get all owner-relevant requests (for metrics calculation)
     */
    public function ownerAll()
    {
        // Get authenticated shop owner
        $shopOwner = Auth::guard('shop_owner')->user();
        
        if (!$shopOwner) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Shop Owner authentication required',
            ], 401);
        }

        // Fetch all requests that have been reviewed by Finance (approved/rejected by owner or pending owner review)
        $requests = PriceChangeRequest::with(['product', 'requester', 'financeReviewer', 'ownerReviewer'])
            ->where('shop_owner_id', $shopOwner->id)
            ->whereIn('status', ['finance_approved', 'owner_approved', 'owner_rejected'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }

    /**
     * Finance staff approves a price change request
     */
    public function financeApprove(Request $request, $id)
    {
        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $priceChangeRequest = PriceChangeRequest::findOrFail($id);

        // Check if already finalized
        if ($priceChangeRequest->status === 'finance_approved') {
            return response()->json([
                'success' => false,
                'message' => 'This request has already been approved by Finance and forwarded to Shop Owner',
                'already_finalized' => true,
            ], 400);
        }

        if ($priceChangeRequest->status === 'owner_approved') {
            return response()->json([
                'success' => false,
                'message' => 'This price change has already been fully approved and applied',
                'already_finalized' => true,
            ], 400);
        }

        if ($priceChangeRequest->status === 'finance_rejected') {
            return response()->json([
                'success' => false,
                'message' => 'This request has already been rejected by Finance',
                'already_finalized' => true,
            ], 400);
        }

        if ($priceChangeRequest->status === 'owner_rejected') {
            return response()->json([
                'success' => false,
                'message' => 'This request has already been rejected by Shop Owner',
                'already_finalized' => true,
            ], 400);
        }

        if ($priceChangeRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This request cannot be approved at this time',
            ], 400);
        }

        $priceChangeRequest->update([
            'status' => 'finance_approved',
            'finance_reviewed_by' => Auth::id(),
            'finance_reviewed_at' => now(),
            'finance_notes' => $request->notes,
        ]);

        $shopOwnerId = $priceChangeRequest->shop_owner_id;
        $metrics = $this->calculateMetrics($shopOwnerId, 'finance');

        return response()->json([
            'success' => true,
            'message' => 'Price change request approved and forwarded to Shop Owner',
            'data' => $priceChangeRequest->fresh()->load(['product', 'requester', 'financeReviewer']),
            'metrics' => $metrics,
        ]);
    }

    /**
     * Finance staff rejects a price change request
     */
    public function financeReject(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $priceChangeRequest = PriceChangeRequest::findOrFail($id);

        // Check if already finalized
        if ($priceChangeRequest->status === 'finance_approved') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot reject - this request has already been approved by Finance and forwarded to Shop Owner',
                'already_finalized' => true,
            ], 400);
        }

        if ($priceChangeRequest->status === 'owner_approved') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot reject - this price change has already been fully approved and applied',
                'already_finalized' => true,
            ], 400);
        }

        if ($priceChangeRequest->status === 'finance_rejected') {
            return response()->json([
                'success' => false,
                'message' => 'This request has already been rejected by Finance',
                'already_finalized' => true,
            ], 400);
        }

        if ($priceChangeRequest->status === 'owner_rejected') {
            return response()->json([
                'success' => false,
                'message' => 'This request has already been rejected by Shop Owner',
                'already_finalized' => true,
            ], 400);
        }

        if ($priceChangeRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This request cannot be rejected at this time',
            ], 400);
        }

        $priceChangeRequest->update([
            'status' => 'finance_rejected',
            'finance_reviewed_by' => Auth::id(),
            'finance_reviewed_at' => now(),
            'finance_rejection_reason' => $request->reason,
        ]);

        $shopOwnerId = $priceChangeRequest->shop_owner_id;
        $metrics = $this->calculateMetrics($shopOwnerId, 'finance');

        return response()->json([
            'success' => true,
            'message' => 'Price change request rejected',
            'data' => $priceChangeRequest->fresh()->load(['product', 'requester', 'financeReviewer']),
            'metrics' => $metrics,
        ]);
    }

    /**
     * Shop owner gives final approval (applies price change)
     */
    public function ownerApprove($id)
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        $priceChangeRequest = PriceChangeRequest::findOrFail($id);

        // Check if already finalized
        if ($priceChangeRequest->status === 'owner_approved') {
            return response()->json([
                'success' => false,
                'message' => 'This price change has already been approved and applied',
                'already_finalized' => true,
            ], 400);
        }

        if ($priceChangeRequest->status === 'owner_rejected') {
            return response()->json([
                'success' => false,
                'message' => 'This request has already been rejected',
                'already_finalized' => true,
            ], 400);
        }

        // Check if needs finance approval first
        if ($priceChangeRequest->status !== 'finance_approved') {
            $statusMessages = [
                'pending' => 'This request is still pending Finance review',
                'finance_rejected' => 'This request was rejected by Finance',
            ];
            
            return response()->json([
                'success' => false,
                'message' => $statusMessages[$priceChangeRequest->status] ?? 'This request cannot be approved at this time',
                'needs_finance_approval' => true,
            ], 400);
        }

        // Verify this request belongs to the shop owner
        if ($priceChangeRequest->shop_owner_id !== $shopOwner->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - This request does not belong to your shop',
            ], 403);
        }

        DB::beginTransaction();
        try {
            // Update product price
            $product = Product::findOrFail($priceChangeRequest->product_id);
            $product->update([
                'price' => $priceChangeRequest->proposed_price,
            ]);

            // Update request status
            $priceChangeRequest->update([
                'status' => 'owner_approved',
                'owner_reviewed_by' => $shopOwner->id,
                'owner_reviewed_at' => now(),
            ]);

            DB::commit();

            $metrics = $this->calculateMetrics($shopOwner->id, 'shop_owner');

            return response()->json([
                'success' => true,
                'message' => 'Price change approved and applied successfully',
                'data' => [
                    'request' => $priceChangeRequest->fresh()->load(['product', 'requester', 'financeReviewer', 'ownerReviewer']),
                    'product' => $product->fresh(),
                ],
                'metrics' => $metrics,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to apply price change: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Shop owner rejects a price change request
     */
    public function ownerReject(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $shopOwner = Auth::guard('shop_owner')->user();
        $priceChangeRequest = PriceChangeRequest::findOrFail($id);

        // Check if already finalized
        if ($priceChangeRequest->status === 'owner_approved') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot reject - this price change has already been approved and applied',
                'already_finalized' => true,
            ], 400);
        }

        if ($priceChangeRequest->status === 'owner_rejected') {
            return response()->json([
                'success' => false,
                'message' => 'This request has already been rejected',
                'already_finalized' => true,
            ], 400);
        }

        if ($priceChangeRequest->status !== 'finance_approved') {
            $statusMessages = [
                'pending' => 'This request is still pending Finance review',
                'finance_rejected' => 'This request was already rejected by Finance',
            ];
            
            return response()->json([
                'success' => false,
                'message' => $statusMessages[$priceChangeRequest->status] ?? 'This request cannot be rejected at this time',
            ], 400);
        }

        // Verify this request belongs to the shop owner
        if ($priceChangeRequest->shop_owner_id !== $shopOwner->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - This request does not belong to your shop',
            ], 403);
        }

        $priceChangeRequest->update([
            'status' => 'owner_rejected',
            'owner_reviewed_by' => $shopOwner->id,
            'owner_reviewed_at' => now(),
            'owner_rejection_reason' => $request->reason,
        ]);

        $metrics = $this->calculateMetrics($shopOwner->id, 'shop_owner');

        return response()->json([
            'success' => true,
            'message' => 'Price change request rejected',
            'data' => $priceChangeRequest->fresh()->load(['product', 'requester', 'financeReviewer', 'ownerReviewer']),
            'metrics' => $metrics,
        ]);
    }

    /**
     * Get pending price change requests for the current user's products
     * Used by staff to see their pending requests
     */
    public function myPending()
    {
        $user = Auth::user();
        $shopOwnerId = $user->shop_owner_id;

        if (!$shopOwnerId) {
            return response()->json([
                'success' => false,
                'message' => 'Shop owner ID not found',
                'requests' => []
            ], 200);
        }

        // Get all pending and finance-approved requests for this shop
        $requests = PriceChangeRequest::with(['product', 'requester', 'financeReviewer'])
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', ['pending', 'finance_approved'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'requests' => $requests,
        ]);
    }
}
