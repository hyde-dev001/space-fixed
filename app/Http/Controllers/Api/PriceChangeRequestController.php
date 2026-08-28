<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\PriceChangeRequest;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use App\Enums\ApprovalStatus;
use App\Enums\PriceChangeStatus;
use App\Services\NotificationService;
use App\Services\PriceChangeApprovalService;
use App\Services\ShopOwnerApprovalPolicyService;
use App\Support\Finance\FinanceErrorResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PriceChangeRequestController extends Controller
{
    public function __construct(
        private PriceChangeApprovalService $priceChangeApprovalService,
        private ShopOwnerApprovalPolicyService $shopOwnerApprovalPolicyService
    ) {}
    /**
     * Calculate metrics for shop owner or finance
     */
    private function calculateMetrics($shopOwnerId, $role = 'finance')
    {
        $query = PriceChangeRequest::where('shop_owner_id', $shopOwnerId);

        if ($role === 'shop_owner') {
            $ownerPendingQuery = (clone $query)
                ->where('status', 'finance_approved')
                ->where(function ($q) {
                    // Legacy 2-step requests
                    $q->whereNull('approval_workflow_version')
                        ->orWhere('approval_workflow_version', '!=', 'v4_multi_level')
                        // 4-step requests: only level 2 belongs to shop owner
                        ->orWhere(function ($v4) {
                            $v4->where('approval_workflow_version', 'v4_multi_level')
                                ->where('current_approval_level', 2);
                        });
                });

            return [
                'pending' => $ownerPendingQuery->count(),
                'approved' => (clone $query)->where('status', 'owner_approved')->count(),
                'rejected' => (clone $query)->where('status', 'owner_rejected')->count(),
                'total' => $ownerPendingQuery->count()
                    + (clone $query)->where('status', 'owner_approved')->count()
                    + (clone $query)->where('status', 'owner_rejected')->count(),
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
        $actor = Auth::guard('user')->user() ?? Auth::user();
        
        // Get shop owner id from product
        $shopOwnerId = $product->shop_owner_id ?? auth()->user()->shop_owner_id;
        $requiresOwnerApproval = $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForPriceChange(
            (int) $shopOwnerId,
            (float) $request->current_price,
            (float) $request->proposed_price
        );

        DB::beginTransaction();
        try {
            // Check for existing pending or in-progress requests for this product
            $existingRequest = PriceChangeRequest::where('product_id', $productId)
                ->whereIn('status', ['pending', 'finance_approved'])
                ->first();

            if ($existingRequest) {
                $oldValues = [
                    'product_name' => $existingRequest->product_name,
                    'current_price' => $existingRequest->current_price,
                    'proposed_price' => $existingRequest->proposed_price,
                    'reason' => $existingRequest->reason,
                    'status' => $existingRequest->status,
                ];

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

                $expectedLevels = $requiresOwnerApproval ? 3 : 1;
                $currentApproval = $existingRequest->approval_id ? Approval::find($existingRequest->approval_id) : null;
                $needsWorkflowRefresh = !$currentApproval
                    || $existingRequest->approval_workflow_version !== 'v4_multi_level'
                    || (int) ($currentApproval->total_levels ?? 0) !== $expectedLevels;

                if ($needsWorkflowRefresh) {
                    if ($existingRequest->approval_id) {
                        $existingRequest->approval()->delete();
                        $existingRequest->update([
                            'approval_id' => null,
                            'approval_workflow_version' => null,
                            'current_approval_level' => null,
                        ]);
                    }

                    try {
                        $shopOwnerUser = $this->resolveShopOwnerApproverUser((int) $shopOwnerId);
                        if ($shopOwnerUser) {
                            $this->priceChangeApprovalService->createPriceChangeApproval(
                                $existingRequest,
                                $shopOwnerUser,
                                $actor,
                                $requiresOwnerApproval
                            );
                        }
                    } catch (\Exception $e) {
                        \Log::error('Failed to refresh price change approval workflow', [
                            'price_change_id' => $existingRequest->id,
                            'requires_owner_approval' => $requiresOwnerApproval,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                try {
                    $this->priceChangeApprovalService->notifyPriceChangeApprovalRequested($existingRequest->fresh());
                } catch (\Exception $e) {
                    \Log::error('Failed to notify finance about updated price change request', [
                        'price_change_id' => $existingRequest->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                activity()
                    ->causedBy($actor)
                    ->performedOn($existingRequest)
                    ->event('updated')
                    ->withProperties([
                        'old' => $oldValues,
                        'attributes' => [
                            'product_name' => $request->product_name,
                            'current_price' => $request->current_price,
                            'proposed_price' => $request->proposed_price,
                            'reason' => $request->reason,
                            'status' => 'pending',
                        ],
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ])
                    ->log('Price change request updated');

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

            activity()
                ->causedBy($actor)
                ->performedOn($priceChangeRequest)
                ->event('created')
                ->withProperties([
                    'attributes' => [
                        'product_id' => $productId,
                        'product_name' => $request->product_name,
                        'current_price' => $request->current_price,
                        'proposed_price' => $request->proposed_price,
                        'reason' => $request->reason,
                        'status' => 'pending',
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ])
                ->log('Price change request submitted');

            // Create approval workflow based on current owner-approval setting.
            try {
                $shopOwnerUser = $this->resolveShopOwnerApproverUser((int) $shopOwnerId);
                if ($shopOwnerUser) {
                    $this->priceChangeApprovalService->createPriceChangeApproval(
                        $priceChangeRequest,
                        $shopOwnerUser,
                        $actor,
                        $requiresOwnerApproval
                    );
                }
            } catch (\Exception $e) {
                \Log::error('Failed to create price change approval workflow', [
                    'price_change_id' => $priceChangeRequest->id,
                    'requires_owner_approval' => $requiresOwnerApproval,
                    'error' => $e->getMessage()
                ]);
                // Continue despite error - request is still created
            }

            try {
                $this->priceChangeApprovalService->notifyPriceChangeApprovalRequested($priceChangeRequest->fresh());
            } catch (\Exception $e) {
                \Log::error('Failed to notify finance about new price change request', [
                    'price_change_id' => $priceChangeRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Price change request submitted successfully',
                'request' => $priceChangeRequest->load(['product', 'requester']),
                'replaced' => false,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return FinanceErrorResponse::json($e, 'price_change.create', 500, ['record_id' => $productId, 'shop_id' => $shopOwnerId]);
        }
    }

    /**
     * Get all price change requests (for Finance)
     */
    public function index(Request $request)
    {
        $status = $request->query('status', 'all');
        $shopOwnerId = auth()->user()->shop_owner_id;

        $query = PriceChangeRequest::with(['product', 'requester', 'financeReviewer', 'ownerReviewer', 'approval'])
            ->where('shop_owner_id', $shopOwnerId)
            ->orderBy('created_at', 'desc');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $requests = $query->get();

        // Heal legacy finalized records where status moved to owner_approved
        // but product price was not yet applied.
        $requests->each(function ($request) {
            $this->reconcileFinalizedProductPrice($request);
        });

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
            ->where(function ($q) {
                // Legacy 2-step requests
                $q->whereNull('approval_workflow_version')
                    ->orWhere('approval_workflow_version', '!=', 'v4_multi_level')
                    // 4-step requests: only level 2 is owner action step
                    ->orWhere(function ($v4) {
                        $v4->where('approval_workflow_version', 'v4_multi_level')
                            ->where('current_approval_level', 2);
                    });
            })
            ->orderBy('finance_reviewed_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }

    public function ownerShow(int $id)
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        if (!$shopOwner) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $priceChangeRequest = PriceChangeRequest::query()
            ->with(['product', 'requester', 'financeReviewer', 'ownerReviewer', 'approval'])
            ->where('shop_owner_id', (int) $shopOwner->id)
            ->whereKey($id)
            ->first();

        if (!$priceChangeRequest) {
            return response()->json(['message' => 'Price change request not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $priceChangeRequest,
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
        $requests = PriceChangeRequest::with(['product', 'requester', 'financeReviewer', 'ownerReviewer', 'approval'])
            ->where('shop_owner_id', $shopOwner->id)
            ->where(function ($q) {
                // Keep approved/rejected history
                $q->whereIn('status', ['owner_approved', 'owner_rejected'])
                    // Pending owner-action bucket (legacy + v4 level 2 only)
                    ->orWhere(function ($pendingOwner) {
                        $pendingOwner->where('status', 'finance_approved')
                            ->where(function ($w) {
                                // Legacy 2-step workflow (no v4 markers)
                                $w->where(function ($legacy) {
                                    $legacy->whereNull('approval_workflow_version')
                                        ->orWhere('approval_workflow_version', '!=', 'v4_multi_level')
                                        ->whereNull('current_approval_level');
                                })
                                // v4 workflow: ONLY level 2 (Shop Owner pending approval)
                                // Exclude level >= 3 (those went back to Finance)
                                ->orWhere(function ($v4) {
                                    $v4->where('approval_workflow_version', 'v4_multi_level')
                                        ->where('current_approval_level', 2);
                                });
                            });
                    });
            })
            ->orderBy('created_at', 'desc')
            ->get();

        // Heal legacy finalized records where status moved to owner_approved
        // but product price was not yet applied.
        $requests->each(function ($request) {
            $this->reconcileFinalizedProductPrice($request);
        });

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
        $actor = Auth::guard('user')->user() ?? Auth::user();
        
        // Create approval workflow if missing (for old requests created before the fix)
        if (!$priceChangeRequest->approval_id) {
            try {
                $shopOwnerUser = $this->resolveShopOwnerApproverUser((int) $priceChangeRequest->shop_owner_id);
                if ($shopOwnerUser) {
                    $this->priceChangeApprovalService->createPriceChangeApproval(
                        $priceChangeRequest,
                        $shopOwnerUser,
                        $actor,
                        true
                    );
                    // Refresh to get the newly created approval
                    $priceChangeRequest->refresh();
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to create approval workflow for existing request', [
                    'price_change_id' => $priceChangeRequest->id,
                    'error' => $e->getMessage()
                ]);
                // Continue - will use legacy path if approval still not created
            }
        }

        // Use 4-step workflow if approval exists
        if ($priceChangeRequest->approval_id && $priceChangeRequest->approval_workflow_version === 'v4_multi_level') {
            DB::beginTransaction();
            try {
                $result = $this->priceChangeApprovalService->approvePriceChange(
                    $priceChangeRequest,
                    $actor,
                    $request->notes
                );

                if (!$result['success']) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => $result['message'] ?? 'Failed to approve price change',
                        'approval_level' => $priceChangeRequest->current_approval_level,
                    ], 400);
                }

                $isFinal = (bool) ($result['is_final'] ?? false);

                if ($isFinal) {
                    $product = Product::find($priceChangeRequest->product_id);
                    if (!$product) {
                        throw new \RuntimeException('Product not found for final price application');
                    }

                    $oldPrice = $product->price;
                    $product->update([
                        'price' => $priceChangeRequest->proposed_price,
                    ]);

                    activity()
                        ->causedBy($actor)
                        ->performedOn($product)
                        ->event('updated')
                        ->withProperties([
                            'product_name' => $product->product_name ?? $priceChangeRequest->product_name,
                            'old_price' => $oldPrice,
                            'new_price' => $priceChangeRequest->proposed_price,
                            'price_change_request_id' => $priceChangeRequest->id,
                            'approval_level' => 'Finance Final',
                            'notes' => $request->notes,
                        ])
                        ->log('Product price change final approval by Finance - price applied');
                }

                // Activity log for approval at specific level
                activity()
                    ->causedBy($actor)
                    ->performedOn($priceChangeRequest)
                    ->event('updated')
                    ->withProperties([
                        'approval_level' => $priceChangeRequest->current_approval_level,
                        'status' => $priceChangeRequest->status,
                        'is_final' => $isFinal,
                        'notes' => $request->notes,
                    ])
                    ->log('Price change approved at finance level ' . $priceChangeRequest->current_approval_level);

                DB::commit();

                $shopOwnerId = $priceChangeRequest->shop_owner_id;
                $metrics = $this->calculateMetrics($shopOwnerId, 'finance');

                return response()->json([
                    'success' => true,
                    'message' => $isFinal ? 'Price change approved and applied successfully' : 'Price change forwarded to next approver',
                    'data' => $priceChangeRequest->fresh()->load(['product', 'requester', 'approval']),
                    'metrics' => $metrics,
                    'is_final' => $isFinal,
                    'approval_level' => $priceChangeRequest->current_approval_level,
                ]);
            } catch (\Throwable $e) {
                DB::rollBack();
                return FinanceErrorResponse::json($e, 'price_change.finance_approve', 500, ['record_id' => $id, 'shop_id' => $priceChangeRequest->shop_owner_id]);
            }
        }

        // All price changes must use the 4-step approval workflow
        // If we get here, the request doesn't have a valid approval workflow
        return response()->json([
            'success' => false,
            'message' => 'This price change request does not have a valid approval workflow. Please contact system administrator.',
            'requires_workflow' => true,
        ], 400);
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
        $actor = Auth::guard('user')->user() ?? Auth::user();

        // New 4-step workflow
        if ($priceChangeRequest->approval_id && $priceChangeRequest->approval_workflow_version === 'v4_multi_level') {
            $result = $this->priceChangeApprovalService->rejectPriceChange(
                $priceChangeRequest,
                $actor,
                $request->reason
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Failed to reject price change',
                    'rejection_level' => $priceChangeRequest->current_approval_level,
                ], 400);
            }

            activity()
                ->causedBy($actor)
                ->performedOn($priceChangeRequest)
                ->event('updated')
                ->withProperties([
                    'rejection_level' => $priceChangeRequest->current_approval_level,
                    'status' => $priceChangeRequest->status,
                    'reason' => $request->reason,
                ])
                ->log('Price change rejected at finance level ' . $priceChangeRequest->current_approval_level);

            $shopOwnerId = $priceChangeRequest->shop_owner_id;
            $metrics = $this->calculateMetrics($shopOwnerId, 'finance');

            return response()->json([
                'success' => true,
                'message' => 'Price change request rejected',
                'data' => $priceChangeRequest->fresh()->load(['product', 'requester', 'approval']),
                'metrics' => $metrics,
                'rejection_level' => $priceChangeRequest->current_approval_level,
            ]);
        }

        // Legacy 2-step workflow

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

        if ($priceChangeRequest->status !== PriceChangeStatus::PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending requests can be rejected',
            ], 400);
        }

        $priceChangeRequest->update([
            'status' => PriceChangeStatus::FINANCE_REJECTED,
            'finance_reviewed_by' => Auth::id(),
            'finance_reviewed_at' => now(),
            'finance_rejection_reason' => $request->reason,
        ]);

        // Activity log with rejection reason
        activity()
            ->causedBy(Auth::user())
            ->performedOn($priceChangeRequest)
            ->withProperties([
                'product_name' => $priceChangeRequest->product_name,
                'current_price' => $priceChangeRequest->current_price,
                'proposed_price' => $priceChangeRequest->proposed_price,
                'rejection_reason' => $request->reason,
                'rejected_by_name' => Auth::user()->name,
                'rejected_by_role' => Auth::user()->role ?? 'Finance Staff',
            ])
            ->log('Product price change rejected by Finance');

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
     * Shop owner gives approval (at level 2 in 4-step, or final in legacy)
     */
    public function ownerApprove($id)
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        if (! $shopOwner instanceof ShopOwner) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $priceChangeRequest = PriceChangeRequest::query()
            ->where('shop_owner_id', $shopOwner->id)
            ->findOrFail($id);

        // New 4-step workflow
        if ($priceChangeRequest->approval_id && $priceChangeRequest->approval_workflow_version === 'v4_multi_level') {
            $approver = $this->resolveShopOwnerApproverUser((int) $shopOwner->id);
            if (!$approver) {
                return response()->json([
                    'success' => false,
                    'message' => 'Shop owner user record not found',
                ], 400);
            }

            $result = $this->priceChangeApprovalService->approvePriceChange(
                $priceChangeRequest,
                $approver,
                null
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Failed to approve price change',
                    'approval_level' => $priceChangeRequest->current_approval_level,
                ], 400);
            }

            activity()
                ->causedBy($shopOwner)
                ->performedOn($priceChangeRequest)
                ->event('updated')
                ->withProperties([
                    'approval_level' => $priceChangeRequest->current_approval_level,
                    'status' => $priceChangeRequest->status,
                    'is_final' => $result['is_final'] ?? false,
                ])
                ->log('Price change approved at shop owner level ' . $priceChangeRequest->current_approval_level);

            $metrics = $this->calculateMetrics($shopOwner->id, 'shop_owner');

            return response()->json([
                'success' => true,
                'message' => $result['is_final'] ?? false ? 'Approval forwarded to Finance final review' : 'Price change approved by Shop Owner',
                'data' => $priceChangeRequest->fresh()->load(['product', 'requester', 'approval']),
                'metrics' => $metrics,
                'is_final' => $result['is_final'] ?? false,
                'approval_level' => $priceChangeRequest->current_approval_level,
            ]);
        }

        // Legacy 2-step workflow

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
        if ($priceChangeRequest->status !== PriceChangeStatus::FINANCE_APPROVED) {
            $statusMessages = [
                PriceChangeStatus::PENDING->value => 'This request is still pending Finance review',
                PriceChangeStatus::FINANCE_REJECTED->value => 'This request was rejected by Finance',
            ];
            
            return response()->json([
                'success' => false,
                'message' => $statusMessages[$priceChangeRequest->status->value] ?? 'This request cannot be approved at this time',
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
            $oldPrice = $product->price;
            $product->update([
                'price' => $priceChangeRequest->proposed_price,
            ]);

            // Update request status
            $priceChangeRequest->update([
                'status' => 'owner_approved',
                'owner_reviewed_by' => $shopOwner->id,
                'owner_reviewed_at' => now(),
            ]);

            // Activity log for final approval and price change application
            activity()
                ->causedBy($shopOwner)
                ->performedOn($product)
                ->withProperties([
                    'product_name' => $product->name,
                    'old_price' => $oldPrice,
                    'new_price' => $priceChangeRequest->proposed_price,
                    'price_change_request_id' => $priceChangeRequest->id,
                    'approved_by_name' => $shopOwner->name,
                    'approval_level' => 'Shop Owner (Final)',
                ])
                ->log('Product price change approved and applied by Shop Owner');

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
            return FinanceErrorResponse::json($e, 'price_change.owner_approve', 500, ['record_id' => $id, 'shop_id' => $shopOwner->id]);
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
        if (! $shopOwner instanceof ShopOwner) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $priceChangeRequest = PriceChangeRequest::query()
            ->where('shop_owner_id', $shopOwner->id)
            ->findOrFail($id);

        // New 4-step workflow
        if ($priceChangeRequest->approval_id && $priceChangeRequest->approval_workflow_version === 'v4_multi_level') {
            $approver = $this->resolveShopOwnerApproverUser((int) $shopOwner->id);
            if (!$approver) {
                return response()->json([
                    'success' => false,
                    'message' => 'Shop owner user record not found',
                ], 400);
            }

            $result = $this->priceChangeApprovalService->rejectPriceChange(
                $priceChangeRequest,
                $approver,
                $request->reason
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Failed to reject price change',
                    'rejection_level' => $priceChangeRequest->current_approval_level,
                ], 400);
            }

            activity()
                ->causedBy($shopOwner)
                ->performedOn($priceChangeRequest)
                ->event('updated')
                ->withProperties([
                    'rejection_level' => $priceChangeRequest->current_approval_level,
                    'status' => $priceChangeRequest->status,
                    'reason' => $request->reason,
                ])
                ->log('Price change rejected at shop owner level ' . $priceChangeRequest->current_approval_level);

            $metrics = $this->calculateMetrics($shopOwner->id, 'shop_owner');

            return response()->json([
                'success' => true,
                'message' => 'Price change request rejected',
                'data' => $priceChangeRequest->fresh()->load(['product', 'requester', 'approval']),
                'metrics' => $metrics,
                'rejection_level' => $priceChangeRequest->current_approval_level,
            ]);
        }

        // Legacy 2-step workflow

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
            $statusKey = $priceChangeRequest->status instanceof PriceChangeStatus
                ? $priceChangeRequest->status->value
                : (string) $priceChangeRequest->status;
            
            return response()->json([
                'success' => false,
                'message' => $statusMessages[$statusKey] ?? 'This request cannot be rejected at this time',
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

        // Activity log for final rejection
        activity()
            ->causedBy($shopOwner)
            ->performedOn($priceChangeRequest)
            ->withProperties([
                'product_name' => $priceChangeRequest->product_name,
                'current_price' => $priceChangeRequest->current_price,
                'proposed_price' => $priceChangeRequest->proposed_price,
                'rejection_reason' => $request->reason,
                'rejected_by_name' => $shopOwner->name,
                'rejection_level' => 'Shop Owner (Final)',
            ])
            ->log('Product price change rejected by Shop Owner (Final Decision)');

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

    /**
     * Cancel a price change request (staff only)
     */
    public function cancelRequest($id)
    {
        $user = Auth::user();
        $priceChangeRequest = PriceChangeRequest::findOrFail($id);

        // Verify the request belongs to this user
        if ($priceChangeRequest->requested_by !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - You can only cancel your own requests',
            ], 403);
        }

        $status = $priceChangeRequest->status instanceof PriceChangeStatus
            ? $priceChangeRequest->status->value
            : (string) $priceChangeRequest->status;

        // Can only cancel requests that are still awaiting final decision
        if (!in_array($status, [PriceChangeStatus::PENDING->value, PriceChangeStatus::FINANCE_APPROVED->value], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel - request is already finalized',
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Delete the request
            $priceChangeRequest->delete();

            // Log the cancellation
            activity()
                ->causedBy($user)
                ->performedOn($priceChangeRequest)
                ->event('deleted')
                ->withProperties([
                    'attributes' => [
                        'product_name' => $priceChangeRequest->product_name,
                        'current_price' => $priceChangeRequest->current_price,
                        'proposed_price' => $priceChangeRequest->proposed_price,
                        'reason' => $priceChangeRequest->reason,
                    ],
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ])
                ->log('Price change request cancelled by staff');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Price change request cancelled successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error cancelling price change request: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel request',
            ], 500);
        }
    }

    private function resolveShopOwnerApproverUser(int $shopOwnerId): ?User
    {
        $shopOwner = ShopOwner::query()->find($shopOwnerId);
        if (! $shopOwner) {
            return null;
        }

        // Prefer an explicitly linked owner account. Never infer ownership
        // from a numeric User::id == ShopOwner::id collision.
        $roleScopedOwner = User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where(function ($query) {
                $query->whereHas('roles', function ($roles) {
                    $roles->whereIn('name', ['shop-owner', 'Shop Owner', 'SHOP_OWNER', 'shop_owner']);
                })->orWhereIn('role', ['shop-owner', 'Shop Owner', 'SHOP_OWNER', 'shop_owner']);
            })
            ->orderByDesc('id')
            ->first();

        if ($roleScopedOwner) {
            return $roleScopedOwner;
        }

        $ownerEmail = strtolower(trim((string) $shopOwner->email));
        if ($ownerEmail !== '') {
            $mappedByEmail = User::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->whereRaw('LOWER(email) = ?', [$ownerEmail])
                ->orderByDesc('id')
                ->first();

            if ($mappedByEmail) {
                return $mappedByEmail;
            }
        }

        // Keep generic Approval's User foreign keys usable when the
        // authenticated ShopOwner has no ERP mirror yet. The deterministic
        // proxy is linked only to this owner and is never selected by ID.
        $fallbackEmail = 'shopowner+' . $shopOwnerId . '@solespace.local';
        $existingProxy = User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($fallbackEmail)])
            ->where(function ($query) use ($shopOwnerId) {
                $query->whereNull('shop_owner_id')
                    ->orWhere('shop_owner_id', $shopOwnerId);
            })
            ->first();

        if ($existingProxy) {
            $existingProxy->forceFill([
                'shop_owner_id' => $shopOwnerId,
                'role' => $existingProxy->role ?: 'Shop Owner',
                'email_verified_at' => $existingProxy->email_verified_at ?: now(),
            ])->save();

            try {
                if (! $existingProxy->hasRole('Shop Owner')) {
                    $existingProxy->assignRole('Shop Owner');
                }
            } catch (\Throwable) {
                // The legacy role table may not be installed in all environments.
            }

            return $existingProxy;
        }

        $firstName = trim((string) ($shopOwner->first_name ?? 'Shop')) ?: 'Shop';
        $lastName = trim((string) ($shopOwner->last_name ?? 'Owner')) ?: 'Owner';

        try {
            $ownerProxy = User::query()->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => trim($firstName . ' ' . $lastName),
                'email' => $fallbackEmail,
                'password' => Hash::make(Str::random(40)),
                'shop_owner_id' => $shopOwnerId,
                'role' => 'Shop Owner',
                'email_verified_at' => now(),
            ]);

            try {
                $ownerProxy->assignRole('Shop Owner');
            } catch (\Throwable) {
                // The legacy role table may not be installed in all environments.
            }

            return $ownerProxy;
        } catch (\Throwable) {
            return null;
        }
    }

    private function reconcileFinalizedProductPrice($priceChangeRequest): void
    {
        if (!$priceChangeRequest instanceof PriceChangeRequest) {
            return;
        }

        $statusValue = $priceChangeRequest->status instanceof PriceChangeStatus
            ? $priceChangeRequest->status->value
            : (string) $priceChangeRequest->status;

        $approval = null;
        if ($priceChangeRequest->approval_id) {
            $approval = $priceChangeRequest->relationLoaded('approval')
                ? $priceChangeRequest->approval
                : Approval::find($priceChangeRequest->approval_id);
        }

        $approvalStatusValue = $approval?->status instanceof ApprovalStatus
            ? $approval->status->value
            : (string) ($approval?->status ?? '');

        $totalLevels = (int) ($approval?->total_levels ?? 0);
        $currentLevel = (int) ($priceChangeRequest->current_approval_level ?? ($approval?->current_level ?? 0));

        $isApprovalFinal = $approvalStatusValue === ApprovalStatus::APPROVED->value
            || ($totalLevels > 0 && $currentLevel >= $totalLevels);

        $isFinalizedV4 = $priceChangeRequest->approval_workflow_version === 'v4_multi_level'
            && $isApprovalFinal
            && $statusValue === 'owner_approved';

        if (!$isFinalizedV4) {
            return;
        }

        // Never let older finalized requests overwrite a newer approved price.
        $hasNewerFinalizedForProduct = PriceChangeRequest::query()
            ->where('product_id', $priceChangeRequest->product_id)
            ->where('status', 'owner_approved')
            ->where('id', '>', $priceChangeRequest->id)
            ->exists();

        if ($hasNewerFinalizedForProduct) {
            return;
        }

        $product = $priceChangeRequest->product ?: Product::find($priceChangeRequest->product_id);
        if (!$product) {
            return;
        }

        if ((float) $product->price === (float) $priceChangeRequest->proposed_price) {
            return;
        }

        $product->update([
            'price' => $priceChangeRequest->proposed_price,
        ]);
    }
}
