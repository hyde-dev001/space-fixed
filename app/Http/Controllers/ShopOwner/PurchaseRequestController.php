<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PurchaseRequestController extends Controller
{
    public function __construct(
        private PurchaseRequestService $purchaseRequestService
    ) {}

    private const PURCHASE_REQUEST_RELATIONS = [
        'supplier',
        'inventoryItem',
        'inventoryItem.sizes',
        'inventoryItem.colorVariants.sizes',
        'requester',
        'reviewer',
        'approver',
        'shopOwnerApprover',
    ];

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
            ->with(self::PURCHASE_REQUEST_RELATIONS)
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

        $purchaseRequests->setCollection(
            $purchaseRequests->getCollection()->map(function ($purchaseRequest) use ($shopOwner) {
                $payload = $purchaseRequest->toArray();
                $payload['total_cost'] = $this->calculatePurchaseRequestTotalCost(
                    [
                        'inventory_item_id' => $purchaseRequest->inventory_item_id,
                        'requested_size' => $purchaseRequest->requested_size,
                        'requested_color' => $purchaseRequest->requested_color,
                        'quantity' => $purchaseRequest->quantity,
                        'unit_cost' => $purchaseRequest->unit_cost,
                    ],
                    (int) $shopOwner->id
                );

                return $payload;
            })
        );

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
            $purchaseRequest = $this->purchaseRequestService->approveByShopOwner(
                (int) $purchaseRequest->id,
                $shopOwner,
                $request->approval_notes
            );

            $fresh = $purchaseRequest->fresh(self::PURCHASE_REQUEST_RELATIONS);
            $payload = $fresh->toArray();
            $payload['total_cost'] = $this->calculatePurchaseRequestTotalCost(
                [
                    'inventory_item_id' => $fresh->inventory_item_id,
                    'requested_size' => $fresh->requested_size,
                    'requested_color' => $fresh->requested_color,
                    'quantity' => $fresh->quantity,
                    'unit_cost' => $fresh->unit_cost,
                ],
                (int) $shopOwner->id
            );

            return response()->json([
                'message' => 'Purchase request acknowledged by the Shop Owner and sent to Finance for final release.',
                'data' => $payload,
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

        if ($purchaseRequest->status !== 'pending_shop_owner') {
            return response()->json([
                'message' => 'This request cannot be rejected in its current state.',
                'current_status' => $purchaseRequest->status,
            ], 403);
        }

        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        try {
            $purchaseRequest = $this->purchaseRequestService->rejectByShopOwner(
                (int) $purchaseRequest->id,
                $shopOwner,
                $request->rejection_reason
            );

            $fresh = $purchaseRequest->fresh(self::PURCHASE_REQUEST_RELATIONS);
            $payload = $fresh->toArray();
            $payload['total_cost'] = $this->calculatePurchaseRequestTotalCost(
                [
                    'inventory_item_id' => $fresh->inventory_item_id,
                    'requested_size' => $fresh->requested_size,
                    'requested_color' => $fresh->requested_color,
                    'quantity' => $fresh->quantity,
                    'unit_cost' => $fresh->unit_cost,
                ],
                (int) $shopOwner->id
            );

            return response()->json([
                'message' => 'Purchase request rejected and returned to procurement.',
                'data' => $payload,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to reject purchase request.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function calculatePurchaseRequestTotalCost(array $data, int $shopOwnerId): float
    {
        $quantity = (int) ($data['quantity'] ?? 0);
        $unitCost = (float) ($data['unit_cost'] ?? 0);

        return $quantity > 0 && $unitCost >= 0 ? round($quantity * $unitCost, 2) : 0;
    }
}
