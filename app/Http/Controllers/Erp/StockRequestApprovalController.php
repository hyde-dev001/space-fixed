<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Models\StockRequestApproval;
use App\Models\InventoryItem;
use App\Http\Requests\ApproveStockRequestRequest;
use App\Services\StockRequestApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;

class StockRequestApprovalController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private StockRequestApprovalService $stockRequestApprovalService
    ) {}

    private function isInventoryWorkflow(Request $request): bool
    {
        $routeName = (string) optional($request->route())->getName();
        return str_starts_with($routeName, 'inventory.request-material-approvals.');
    }

    private function isProcurementWorkflow(Request $request): bool
    {
        $routeName = (string) optional($request->route())->getName();
        return str_starts_with($routeName, 'procurement.stock-requests.')
            || str_starts_with($routeName, 'procurement.replenishment-requests.');
    }

    private function isCanonicalInventoryStockRequest(Request $request): bool
    {
        return $request->routeIs('inventory.stock-requests.store');
    }

    private function normalizeRequestedSize(?string $requestedSize): ?string
    {
        $trimmed = trim((string) $requestedSize);
        if ($trimmed === '') {
            return null;
        }

        $normalized = strtolower((string) preg_replace('/[\s-]+/', '_', $trimmed));

        return in_array($normalized, ['all', 'all_size', 'all_sizes', 'any'], true)
            ? null
            : $trimmed;
    }

    /**
     * Return the configured size rows used to normalize a new per-size request.
     * Stock-on-hand is deliberately not part of eligibility; a configured size
     * with zero stock still needs to be replenished.
     */
    private function configuredSizesForPerSizeRequest(InventoryItem $inventoryItem, ?string $requestedColor): Collection|JsonResponse
    {
        $colorVariants = $inventoryItem->colorVariants()->get();
        $normalizedColor = strtolower(trim((string) $requestedColor));

        if ($colorVariants->isNotEmpty()) {
            if ($normalizedColor === '') {
                return response()->json([
                    'message' => 'Select a color before requesting all shoe sizes.',
                ], 422);
            }

            $variant = $colorVariants->first(
                fn ($candidate) => strtolower(trim((string) $candidate->color_name)) === $normalizedColor
            );

            if (! $variant) {
                return response()->json([
                    'message' => 'The selected color is not configured for this item.',
                ], 422);
            }

            return $inventoryItem->sizes()
                ->where('inventory_color_variant_id', $variant->id)
                ->get();
        }

        if ($normalizedColor !== '') {
            return response()->json([
                'message' => 'This item has no configured color variants.',
            ], 422);
        }

        return $inventoryItem->sizes()->get();
    }

    private function applyWorkflowVisibility($query, Request $request): void
    {
        if ($this->isInventoryWorkflow($request)) {
            $query->where('request_source', 'repair');
            return;
        }

        if ($this->isProcurementWorkflow($request)) {
            $query->where(function ($workflowQuery) {
                $workflowQuery->where('request_source', 'manual')
                    ->orWhere(function ($repairQuery) {
                        $repairQuery->where('request_source', 'repair')
                            ->whereNotNull('inventory_approved_date');
                    });
            });
        }
    }

    /**
     * Display a listing of stock request approvals.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', StockRequestApproval::class);

        $query = StockRequestApproval::query()
            ->with(['shopOwner', 'inventoryItem.sizes', 'inventoryItem.colorVariants.sizes', 'requester', 'approver'])
            ->where('shop_owner_id', Auth::user()->shop_owner_id);

        $this->applyWorkflowVisibility($query, $request);

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

        if ($request->boolean('available_for_purchase_request')) {
            $query->whereDoesntHave('purchaseRequest');
        }

        // Priority filter
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        // Request source filter (manual | repair)
        if ($request->filled('request_source') && in_array($request->request_source, ['manual', 'repair'], true)) {
            $query->where('request_source', $request->request_source);

            if ($this->isProcurementWorkflow($request) && $request->request_source === 'repair') {
                $query->whereNotNull('inventory_approved_date');
            }
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
            'inventoryItem.sizes',
            'inventoryItem.colorVariants.sizes',
            'requester',
            'approver'
        ])->where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);

        $this->authorize('view', $stockRequest);

        return response()->json($stockRequest);
    }

    /**
     * Store a newly created stock request.
     */
    public function store(Request $request)
    {
        $ability = $request->routeIs('inventory.stock-requests.store')
            ? 'createFromInventory'
            : 'create';
        $this->authorize($ability, StockRequestApproval::class);

        $shopOwnerId = (int) Auth::user()->shop_owner_id;

        $validated = $request->validate([
            'inventory_item_id' => ['required', Rule::exists('inventory_items', 'id')->where('shop_owner_id', $shopOwnerId)],
            'quantity_needed'   => 'required|integer|min:1',
            'quantity_basis'    => 'nullable|in:total,per_size',
            'priority'          => 'required|in:high,medium,low',
            'requested_size'    => 'nullable|string|max:20',
            'requested_color'   => 'nullable|string|max:50',
            'notes'             => 'nullable|string|max:1000',
            'request_source'    => 'nullable|in:manual,repair',
            'repair_request_id' => ['nullable', Rule::exists('repair_requests', 'id')->where('shop_owner_id', $shopOwnerId)],
        ]);

        $inventoryItem = InventoryItem::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($validated['inventory_item_id']);

        $requestedSize = $this->normalizeRequestedSize($validated['requested_size'] ?? null);
        $quantityNeeded = (int) $validated['quantity_needed'];
        $quantityBasis = $validated['quantity_basis'] ?? 'total';

        if ($quantityBasis === 'per_size') {
            if (! $this->isCanonicalInventoryStockRequest($request)) {
                return response()->json([
                    'message' => 'Per-size quantity is only available from the Inventory stock request flow.',
                ], 422);
            }

            if (($validated['request_source'] ?? 'manual') !== 'manual') {
                return response()->json([
                    'message' => 'Per-size quantity is only available for manual Inventory requests.',
                ], 422);
            }

            if (strtolower(trim((string) $inventoryItem->category)) !== 'shoes') {
                return response()->json([
                    'message' => 'Per-size quantity is only available for shoe items.',
                ], 422);
            }

            if ($requestedSize !== null) {
                return response()->json([
                    'message' => 'Per-size quantity requires an All Sizes request.',
                ], 422);
            }

            $configuredSizes = $this->configuredSizesForPerSizeRequest(
                $inventoryItem,
                $validated['requested_color'] ?? null,
            );

            if ($configuredSizes instanceof JsonResponse) {
                return $configuredSizes;
            }

            $configuredSizeCount = $configuredSizes
                ->filter(fn ($size) => trim((string) $size->size) !== '')
                ->unique(fn ($size) => strtolower((string) ($size->size_system ?? '')) . '|' . trim((string) $size->size))
                ->count();

            if ($configuredSizeCount < 1) {
                return response()->json([
                    'message' => 'No configured sizes are available for the selected color.',
                ], 422);
            }

            $quantityNeeded *= $configuredSizeCount;
        }

        if (($validated['request_source'] ?? 'manual') === 'repair' && (string) $inventoryItem->category !== 'repair_materials') {
            return response()->json([
                'message' => 'Only repair materials can be requested when request source is repair.',
            ], 422);
        }

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
            'repair_request_id' => $validated['repair_request_id'] ?? null,
            'product_name'      => $inventoryItem->name,
            'sku_code'          => $inventoryItem->sku ?? '',
            'quantity_needed'   => $quantityNeeded,
            'requested_size'    => $requestedSize,
            'requested_color'   => $validated['requested_color'] ?? null,
            'priority'          => $validated['priority'],
            'request_source'    => $validated['request_source'] ?? 'manual',
            'status'            => 'pending',
            'requested_by'      => $user->id,
            'requested_date'    => now(),
            'notes'             => $validated['notes'] ?? null,
        ]);

        $this->stockRequestApprovalService->notifyStockRequestSubmitted($stockRequest->fresh());

        return response()->json([
            'message'       => 'Stock request submitted successfully.',
            'stock_request' => $stockRequest->load(['inventoryItem', 'requester']),
        ], 201);
    }
    public function approve(ApproveStockRequestRequest $request, $id)
    {
        $stockRequest = StockRequestApproval::where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);
        $isInventoryWorkflow = $this->isInventoryWorkflow($request);
        $isProcurementWorkflow = $this->isProcurementWorkflow($request);
        
        $this->authorize('approve', $stockRequest);

        if (!$stockRequest->canBeApproved()) {
            return response()->json([
                'message' => 'Stock request cannot be approved in its current state.'
            ], 403);
        }

        if ($stockRequest->request_source === 'repair' && $isInventoryWorkflow) {
            if ($stockRequest->inventory_approved_date) {
                return response()->json([
                    'message' => 'Repair material request is already approved by inventory and forwarded to procurement.',
                ], 409);
            }
        }

        if ($stockRequest->request_source === 'repair' && $isProcurementWorkflow && !$stockRequest->inventory_approved_date) {
            return response()->json([
                'message' => 'Repair material request must be approved by Inventory first before Procurement approval.',
            ], 422);
        }

        try {
            if ($stockRequest->request_source === 'repair' && $isInventoryWorkflow) {
                $stockRequest->inventory_approved_by = Auth::id();
                $stockRequest->inventory_approved_date = now();
                $stockRequest->inventory_approval_notes = $request->approval_notes;

                // Move needs-details requests back to pending once inventory signs off.
                if ($stockRequest->status === 'needs_details') {
                    $stockRequest->status = 'pending';
                }

                $stockRequest->save();
                $this->stockRequestApprovalService->notifyStockRequestForwardedToProcurement($stockRequest->fresh(), (int) Auth::id());
            } else {
                $stockRequest = $this->stockRequestApprovalService->approveStockRequest(
                    (int) $id,
                    (int) Auth::id(),
                    $request->approval_notes
                );
            }

            // TODO: Optionally auto-create PR if configured
            // if ($request->auto_create_pr) {
            //     // Create purchase request logic here
            // }

            return response()->json([
                'message' => ($stockRequest->request_source === 'repair' && $isInventoryWorkflow)
                    ? 'Repair material request approved by inventory and forwarded to procurement.'
                    : 'Stock request approved successfully.',
                'stock_request' => $stockRequest->fresh(['shopOwner', 'inventoryItem', 'requester', 'approver'])
            ]);

        } catch (\Exception $e) {
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
        $stockRequest = StockRequestApproval::where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);
        $isInventoryWorkflow = $this->isInventoryWorkflow($request);
        $isProcurementWorkflow = $this->isProcurementWorkflow($request);
        
        $this->authorize('reject', $stockRequest);

        if (!$stockRequest->canBeRejected()) {
            return response()->json([
                'message' => 'Stock request cannot be rejected in its current state.'
            ], 403);
        }

        if ($stockRequest->request_source === 'repair' && $isInventoryWorkflow && $stockRequest->inventory_approved_date) {
            return response()->json([
                'message' => 'Inventory cannot reject this request after it has already been forwarded to Procurement.',
            ], 409);
        }

        if ($stockRequest->request_source === 'repair' && $isProcurementWorkflow && !$stockRequest->inventory_approved_date) {
            return response()->json([
                'message' => 'Repair material request must be approved by Inventory first before Procurement review.',
            ], 422);
        }

        $validatedData = $request->validate([
            'rejection_reason' => 'required|string|min:10',
        ]);

        try {
            $stockRequest = $this->stockRequestApprovalService->rejectStockRequest(
                (int) $id,
                (int) Auth::id(),
                $validatedData['rejection_reason']
            );

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
        $stockRequest = StockRequestApproval::where('shop_owner_id', Auth::user()->shop_owner_id)->findOrFail($id);
        $isInventoryWorkflow = $this->isInventoryWorkflow($request);
        $isProcurementWorkflow = $this->isProcurementWorkflow($request);
        
        $this->authorize('requestDetails', $stockRequest);

        $validatedData = $request->validate([
            'approval_notes' => 'nullable|string|min:10|required_without:response_notes',
            'response_notes' => 'nullable|string|min:10|required_without:approval_notes',
        ]);

        $approvalNotes = $validatedData['approval_notes'] ?? $validatedData['response_notes'] ?? null;

        if (!$approvalNotes) {
            return response()->json([
                'message' => 'Approval notes are required.'
            ], 422);
        }

        if ($stockRequest->request_source === 'repair' && $isInventoryWorkflow && $stockRequest->inventory_approved_date) {
            return response()->json([
                'message' => 'Inventory cannot request more details after forwarding to Procurement.',
            ], 409);
        }

        if ($stockRequest->request_source === 'repair' && $isProcurementWorkflow && !$stockRequest->inventory_approved_date) {
            return response()->json([
                'message' => 'Repair material request must be approved by Inventory first before Procurement review.',
            ], 422);
        }

        try {
            $stockRequest = $this->stockRequestApprovalService->requestDetails(
                (int) $id,
                (int) Auth::id(),
                $approvalNotes
            );

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
    public function getMetrics(Request $request)
    {
        $this->authorize('viewAny', StockRequestApproval::class);

        $shopOwnerId = Auth::user()->shop_owner_id;

        $baseQuery = StockRequestApproval::where('shop_owner_id', $shopOwnerId);
        $this->applyWorkflowVisibility($baseQuery, $request);
        $requestSource = $request->get('request_source');

        if (in_array($requestSource, ['manual', 'repair'], true)) {
            $baseQuery->where('request_source', $requestSource);
        }

        $metrics = [
            'total_stock_requests' => (clone $baseQuery)->count(),
            'pending_requests' => (clone $baseQuery)->pending()->count(),
            'accepted_requests' => (clone $baseQuery)->accepted()->count(),
            'rejected_requests' => (clone $baseQuery)->rejected()->count(),
            'needs_details' => (clone $baseQuery)->needsDetails()->count(),
        ];

        return response()->json($metrics);
    }
}
