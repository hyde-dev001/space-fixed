<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Http\Requests\StorePurchaseRequestRequest;
use App\Http\Requests\ApprovePurchaseRequestRequest;
use App\Http\Requests\RejectPurchaseRequestRequest;
use App\Models\StockRequestApproval;
use App\Services\PurchaseRequestService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class PurchaseRequestController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private PurchaseRequestService $purchaseRequestService) {}

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

                    $payload = $purchaseRequest->toArray();
                    $payload['total_cost'] = $recalculatedTotalCost;
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
        $submitToFinance = $request->boolean('submit_to_finance');
        if ($submitToFinance) {
            abort_unless($request->user()->can('procurement.submit_purchase_requests'), 403);
        }

        try {
            DB::beginTransaction();

            $data = $request->validated();
            $shopOwnerId = (int) Auth::user()->shop_owner_id;
            if ($shopOwnerId <= 0) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Unable to resolve shop owner context for this account. Please contact support.'
                ], 422);
            }

            $stockRequestId = (int) $data['stock_request_id'];
            $sourceStockRequest = StockRequestApproval::query()
                ->where('id', $stockRequestId)
                ->where('shop_owner_id', $shopOwnerId)
                ->where('status', 'accepted')
                ->lockForUpdate()
                ->first();

            if (!$sourceStockRequest || PurchaseRequest::where('stock_request_id', $stockRequestId)->exists()) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Selected stock request is not available for purchase request creation.',
                ], 422);
            }

            if (Schema::hasColumn('purchase_requests', 'notes')) {
                $sourceNumber = $sourceStockRequest->request_number ?: (string) $sourceStockRequest->id;
                $sourceSummary = "Source Stock Request: {$sourceNumber}";
                $existingNotes = trim((string) ($data['notes'] ?? ''));
                $data['notes'] = trim(implode("\n", array_filter([$existingNotes, $sourceSummary])));
            }

            $data = $this->sanitizePurchaseRequestPayloadForSchema($data);

            $data['product_name'] = $sourceStockRequest->product_name;
            $data['inventory_item_id'] = $sourceStockRequest->inventory_item_id;
            $data['requested_size'] = $sourceStockRequest->requested_size;
            $data['requested_color'] = $sourceStockRequest->requested_color;
            $data['quantity'] = $sourceStockRequest->quantity_needed;
            $data['priority'] = $sourceStockRequest->priority;

            $data['shop_owner_id'] = $shopOwnerId;
            $data['requested_by'] = Auth::id();
            $data['requested_date'] = now();
            $data['total_cost'] = $this->calculatePurchaseRequestTotalCost($data, (int) $data['shop_owner_id']);

            $purchaseRequest = $this->purchaseRequestService->createPurchaseRequest($data, $submitToFinance);

            DB::commit();

            return response()->json([
                'message' => 'Purchase request created successfully.',
                'data' => $purchaseRequest->load([
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

            Log::error('Failed to create purchase request', [
                'user_id' => Auth::id(),
                'shop_owner_id' => (int) (Auth::user()->shop_owner_id ?? 0),
                'error' => $e->getMessage(),
            ]);

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
        ])->where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);

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
        $purchaseRequest = PurchaseRequest::where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);
        
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
            $data = $this->sanitizePurchaseRequestPayloadForSchema($data);
            $data['total_cost'] = $this->calculatePurchaseRequestTotalCost($data, (int) $purchaseRequest->shop_owner_id);
            
            if ($request->submit_to_finance && $purchaseRequest->status === 'draft') {
                $data['status'] = 'pending_finance';
            }

            $purchaseRequest->update($data);

            DB::commit();

            return response()->json([
                'message' => 'Purchase request updated successfully.',
                'data' => $purchaseRequest->load([
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
        $purchaseRequest = PurchaseRequest::where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);
        
        $this->authorize('delete', $purchaseRequest);

        // Can only delete if draft
        if ($purchaseRequest->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft purchase requests can be deleted.'
            ], 403);
        }

        $purchaseRequest->delete();

        return response()->json([
            'message' => 'Purchase request deleted successfully.',
            'data' => $purchaseRequest
        ]);
    }

    /**
     * Submit purchase request to finance.
     */
    public function submitToFinance($id)
    {
        $purchaseRequest = PurchaseRequest::where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);
        
        $this->authorize('submitToFinance', $purchaseRequest);

        if ($purchaseRequest->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft purchase requests can be submitted to finance.'
            ], 403);
        }

        $purchaseRequest = $this->purchaseRequestService->submitToFinance((int) $purchaseRequest->id);

        return response()->json([
            'message' => 'Purchase request submitted to finance successfully.',
            'data' => $purchaseRequest->fresh()
        ]);
    }

    /**
     * Approve purchase request.
     */
    public function approve(ApprovePurchaseRequestRequest $request, $id)
    {
        $purchaseRequest = PurchaseRequest::where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);
        
        $this->authorize('approve', $purchaseRequest);

        $isFinanceFinalStage = $purchaseRequest->status === 'pending_finance_final';
        $freshRequest = ($isFinanceFinalStage
            ? $this->purchaseRequestService->releaseByFinance((int) $purchaseRequest->id, Auth::user(), $request->approval_notes)
            : $this->purchaseRequestService->reviewByFinance((int) $purchaseRequest->id, Auth::user(), $request->approval_notes)
        )->load(['shopOwner', 'supplier', 'inventoryItem', 'requester', 'reviewer', 'approver', 'shopOwnerApprover']);

        return response()->json([
            'message' => $isFinanceFinalStage
                ? 'Purchase request released by Finance successfully.'
                : 'Purchase request sent to the Shop Owner successfully.',
            'data' => $freshRequest,
        ]);
    }

    /**
     * Reject purchase request.
     */
    public function reject(RejectPurchaseRequestRequest $request, $id)
    {
        $purchaseRequest = PurchaseRequest::where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);
        
        $this->authorize('reject', $purchaseRequest);

        $purchaseRequest = $this->purchaseRequestService->rejectByFinance(
            (int) $purchaseRequest->id,
            Auth::user(),
            $request->rejection_reason
        );

        return response()->json([
            'message' => 'Purchase request rejected successfully.',
            'data' => $purchaseRequest->fresh(['shopOwner', 'supplier', 'inventoryItem', 'requester', 'reviewer'])
        ]);
    }

    private function calculatePurchaseRequestTotalCost(array $data, int $shopOwnerId): float
    {
        $quantity = (int) ($data['quantity'] ?? 0);
        $unitCost = (float) ($data['unit_cost'] ?? 0);

        return $quantity > 0 && $unitCost >= 0 ? round($quantity * $unitCost, 2) : 0;
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
     * Remove optional fields that do not exist in the current DB schema.
     * This avoids 500s on production nodes with pending migrations.
     */
    private function sanitizePurchaseRequestPayloadForSchema(array $data): array
    {
        $optionalColumns = [
            'inventory_item_id',
            'requested_size',
            'requested_color',
            'notes',
        ];

        foreach ($optionalColumns as $column) {
            if (array_key_exists($column, $data) && !Schema::hasColumn('purchase_requests', $column)) {
                unset($data[$column]);
            }
        }

        return $data;
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
            ->whereDoesntHave('purchaseOrderItems.purchaseOrder', function ($query) {
                $query->where('status', '!=', 'cancelled');
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
        $year = (int) date('Y');
        $maxSequence = 0;

        $existingPrNumbers = PurchaseRequest::query()
            ->where('pr_number', 'LIKE', "PR-{$year}-%")
            ->pluck('pr_number');

        foreach ($existingPrNumbers as $prNumber) {
            if (preg_match('/^PR-(\d{4})-(\d+)$/', (string) $prNumber, $matches) !== 1) {
                continue;
            }

            if ((int) $matches[1] !== $year) {
                continue;
            }

            $maxSequence = max($maxSequence, (int) $matches[2]);
        }

        return sprintf('PR-%d-%03d', $year, $maxSequence + 1);
    }

    private function incrementPrNumber(string $prNumber): string
    {
        if (preg_match('/^PR-(\d{4})-(\d+)$/', $prNumber, $matches) !== 1) {
            return $this->generatePRNumber();
        }

        $year = (int) $matches[1];
        $sequence = (int) $matches[2] + 1;

        return sprintf('PR-%d-%03d', $year, $sequence);
    }

    private function isPrNumberDuplicateException(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'duplicate entry')
            && str_contains($message, 'pr_number');
    }
}
