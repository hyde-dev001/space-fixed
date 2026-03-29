<?php

namespace App\Http\Controllers\ERP;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Http\Requests\StorePurchaseRequestRequest;
use App\Http\Requests\ApprovePurchaseRequestRequest;
use App\Http\Requests\RejectPurchaseRequestRequest;
use App\Models\InventoryItem;
use App\Models\StockRequestApproval;
use App\Services\ShopOwnerApprovalPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class PurchaseRequestController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private ShopOwnerApprovalPolicyService $shopOwnerApprovalPolicyService
    ) {}

    /**
     * Display a listing of purchase requests with filters.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', PurchaseRequest::class);

        $purchaseRequestRelations = [
            'shopOwner',
            'supplier',
            'inventoryItem',
            'inventoryItem.sizes',
            'inventoryItem.colorVariants.sizes',
            'requester',
            'reviewer',
            'approver',
        ];

        $query = PurchaseRequest::query()
            ->with($purchaseRequestRelations)
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
            $statusFilter = array_values(array_filter(array_map('trim', explode(',', (string) $request->status))));
            if (count($statusFilter) > 1) {
                $query->whereIn('status', $statusFilter);
            } elseif (count($statusFilter) === 1) {
                $query->where('status', $statusFilter[0]);
            }
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

            // Evaluate requires_owner_approval for each item and return explicit arrays
            $purchaseRequests->setCollection(
                $purchaseRequests->getCollection()->map(function ($purchaseRequest) {
                    $recalculatedTotalCost = $this->calculatePurchaseRequestTotalCost(
                        [
                            'inventory_item_id' => $purchaseRequest->inventory_item_id,
                            'requested_size' => $purchaseRequest->requested_size,
                            'requested_color' => $purchaseRequest->requested_color,
                            'quantity' => $purchaseRequest->quantity,
                            'unit_cost' => $purchaseRequest->unit_cost,
                        ],
                        (int) $purchaseRequest->shop_owner_id
                    );

                    $requiresOwnerApproval = $this->shopOwnerApprovalPolicyService
                        ->requiresOwnerApprovalForPurchaseRequest(
                            (int) $purchaseRequest->shop_owner_id,
                            $recalculatedTotalCost
                        );

                    $payload = $purchaseRequest->toArray();
                    $payload['total_cost'] = $recalculatedTotalCost;
                    $payload['requires_owner_approval'] = $requiresOwnerApproval;
                    $payload['approval_stage'] = $purchaseRequest->status === 'pending_finance_final'
                        ? 'finance_final'
                        : ($purchaseRequest->status === 'pending_finance' ? 'finance_initial' : null);

                    return $payload;
                })
            );

        return response()->json($purchaseRequests);
    }

    /**
     * Store a newly created purchase request.
     */
    public function store(StorePurchaseRequestRequest $request)
    {
        $this->authorize('create', PurchaseRequest::class);

        try {
            DB::beginTransaction();

            $data = $request->validated();
            $shopOwnerId = (int) Auth::user()->shop_owner_id;
            $stockRequestId = isset($data['stock_request_id']) ? (int) $data['stock_request_id'] : null;
            $sourceStockRequest = null;

            if ($stockRequestId) {
                $sourceStockRequest = StockRequestApproval::query()
                    ->where('id', $stockRequestId)
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('status', 'accepted')
                    ->first();

                if (!$sourceStockRequest) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Selected stock request is not available for purchase request creation.',
                    ], 422);
                }

                $sourceMarker = "[stock_request_id:{$stockRequestId}]";
                $alreadyProcessed = PurchaseRequest::query()
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('status', '!=', 'rejected')
                    ->where('notes', 'LIKE', "%{$sourceMarker}%")
                    ->exists();

                if ($alreadyProcessed) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'This approved stock request has already been processed into a purchase request.'
                    ], 422);
                }
            }

            if ($sourceStockRequest) {
                $sourceNumber = $sourceStockRequest->request_number ?: (string) $sourceStockRequest->id;
                $sourceMarker = "[stock_request_id:{$sourceStockRequest->id}]";
                $sourceSummary = "Source Stock Request: {$sourceNumber}";
                $existingNotes = trim((string) ($data['notes'] ?? ''));
                $data['notes'] = trim(implode("\n", array_filter([$existingNotes, $sourceSummary, $sourceMarker])));
            }

            unset($data['stock_request_id']);

            $data['shop_owner_id'] = $shopOwnerId;
            $data['requested_by'] = Auth::id();
            $data['requested_date'] = now();
            $data['total_cost'] = $this->calculatePurchaseRequestTotalCost($data, (int) $data['shop_owner_id']);
            
            // Generate PR number
            $data['pr_number'] = $this->generatePRNumber();

            // Set status
            $data['status'] = $request->submit_to_finance ? 'pending_finance' : 'draft';

            $purchaseRequest = PurchaseRequest::create($data);

            DB::commit();

            return response()->json([
                'message' => 'Purchase request created successfully.',
                'purchase_request' => $purchaseRequest->load([
                    'shopOwner',
                    'supplier',
                    'inventoryItem',
                    'inventoryItem.sizes',
                    'inventoryItem.colorVariants.sizes',
                    'requester',
                ])
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
            'inventoryItem.sizes',
            'inventoryItem.colorVariants.sizes',
            'requester', 
            'reviewer', 
            'approver',
            'purchaseOrders'
        ])->findOrFail($id);

        $this->authorize('view', $purchaseRequest);

        $purchaseRequest->total_cost = $this->calculatePurchaseRequestTotalCost(
            [
                'inventory_item_id' => $purchaseRequest->inventory_item_id,
                'requested_size' => $purchaseRequest->requested_size,
                'requested_color' => $purchaseRequest->requested_color,
                'quantity' => $purchaseRequest->quantity,
                'unit_cost' => $purchaseRequest->unit_cost,
            ],
            (int) $purchaseRequest->shop_owner_id
        );

        return response()->json($purchaseRequest);
    }

    /**
     * Update the specified purchase request.
     */
    public function update(StorePurchaseRequestRequest $request, $id)
    {
        $purchaseRequest = PurchaseRequest::findOrFail($id);
        
        $this->authorize('update', $purchaseRequest);

        // Can only update if draft
        if ($purchaseRequest->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft purchase requests can be updated.'
            ], 403);
        }

        try {
            DB::beginTransaction();

            $data = $request->validated();
            $data['total_cost'] = $this->calculatePurchaseRequestTotalCost($data, (int) $purchaseRequest->shop_owner_id);
            
            if ($request->submit_to_finance && $purchaseRequest->status === 'draft') {
                $data['status'] = 'pending_finance';
            }

            $purchaseRequest->update($data);

            DB::commit();

            return response()->json([
                'message' => 'Purchase request updated successfully.',
                'purchase_request' => $purchaseRequest->load([
                    'shopOwner',
                    'supplier',
                    'inventoryItem',
                    'inventoryItem.sizes',
                    'inventoryItem.colorVariants.sizes',
                    'requester',
                ])
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
        
        $this->authorize('delete', $purchaseRequest);

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
        
        $this->authorize('submitToFinance', $purchaseRequest);

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
        
        $this->authorize('approve', $purchaseRequest);

        if (!$purchaseRequest->canBeApproved()) {
            return response()->json([
                'message' => 'Purchase request cannot be approved in its current state.'
            ], 403);
        }

        try {
            $recalculatedTotalCost = $this->calculatePurchaseRequestTotalCost(
                [
                    'inventory_item_id' => $purchaseRequest->inventory_item_id,
                    'requested_size' => $purchaseRequest->requested_size,
                    'requested_color' => $purchaseRequest->requested_color,
                    'quantity' => $purchaseRequest->quantity,
                    'unit_cost' => $purchaseRequest->unit_cost,
                ],
                (int) $purchaseRequest->shop_owner_id
            );

            if (round((float) $purchaseRequest->total_cost, 2) !== round($recalculatedTotalCost, 2)) {
                $purchaseRequest->total_cost = $recalculatedTotalCost;
                $purchaseRequest->save();
            }

            $isFinanceFinalStage = $purchaseRequest->status === 'pending_finance_final';
            $requiresOwnerApproval = $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForPurchaseRequest(
                (int) $purchaseRequest->shop_owner_id,
                $recalculatedTotalCost
            );

            if ($isFinanceFinalStage) {
                $purchaseRequest->approve(Auth::id(), $request->approval_notes, 'finance_final', false);
            } else {
                $purchaseRequest->approve(Auth::id(), $request->approval_notes, 'finance_initial', $requiresOwnerApproval);
            }

            $freshRequest = $purchaseRequest->fresh(['shopOwner', 'supplier', 'inventoryItem', 'requester', 'approver']);

            $payload = $freshRequest->toArray();
            $payload['requires_owner_approval'] = $requiresOwnerApproval;
            $payload['approval_stage'] = $freshRequest->status === 'pending_finance_final'
                ? 'finance_final'
                : ($freshRequest->status === 'pending_finance' ? 'finance_initial' : null);

            return response()->json([
                'message' => $isFinanceFinalStage
                    ? 'Purchase request finalized by Finance successfully.'
                    : 'Purchase request approved successfully.',
                'purchase_request' => $payload
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
        
        $this->authorize('reject', $purchaseRequest);

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
     * Calculate PR total cost with support for all-size requests.
     *
     * For blank/all requested_size values, quantity is treated per size row.
     */
    private function calculatePurchaseRequestTotalCost(array $data, int $shopOwnerId): float
    {
        $quantity = (int) ($data['quantity'] ?? 0);
        $unitCost = (float) ($data['unit_cost'] ?? 0);

        if ($quantity <= 0 || $unitCost < 0) {
            return 0;
        }

        $requestedSize = trim((string) ($data['requested_size'] ?? ''));
        $requestedColor = trim((string) ($data['requested_color'] ?? ''));

        // Specific size keeps the original formula.
        if (!$this->isAllSizesRequest($requestedSize)) {
            return round($quantity * $unitCost, 2);
        }

        $inventoryItemId = $data['inventory_item_id'] ?? null;
        if (!$inventoryItemId) {
            return round($quantity * $unitCost, 2);
        }

        $inventoryItem = InventoryItem::query()
            ->whereKey($inventoryItemId)
            ->where('shop_owner_id', $shopOwnerId)
            ->first();

        if (!$inventoryItem) {
            return round($quantity * $unitCost, 2);
        }

        $sizeRowsQuery = $inventoryItem->sizes();

        if ($requestedColor !== '') {
            $targetColorVariant = $inventoryItem->colorVariants()
                ->whereRaw('LOWER(color_name) = ?', [strtolower($requestedColor)])
                ->first();

            if ($targetColorVariant) {
                $sizeRowsQuery->where('inventory_color_variant_id', $targetColorVariant->id);
            }
        }

        $sizeRowCount = $sizeRowsQuery->count();
        $effectiveQuantity = $quantity * max(1, $sizeRowCount);

        return round($effectiveQuantity * $unitCost, 2);
    }

    private function isAllSizesRequest(?string $requestedSize): bool
    {
        $normalized = strtolower(trim((string) $requestedSize));
        if ($normalized === '') {
            return true;
        }

        $normalized = preg_replace('/[\s-]+/', '_', $normalized) ?? $normalized;
        return in_array($normalized, ['all', 'all_sizes', 'all_size', 'any'], true);
    }

    /**
     * Get procurement metrics.
     */
    public function getMetrics()
    {
        $this->authorize('viewAny', PurchaseRequest::class);

        $shopOwnerId = Auth::user()->shop_owner_id;

        $metrics = [
            'total_purchase_requests' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)->count(),
            'pending_finance' => PurchaseRequest::where('shop_owner_id', $shopOwnerId)
                ->whereIn('status', ['pending_finance', 'pending_finance_final'])
                ->count(),
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
        $this->authorize('viewAny', PurchaseRequest::class);

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
