<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\RepairRequest;
use App\Models\RepairMaterialPlanItem;
use App\Models\RepairMaterialUsage;
use App\Models\RepairPackage;
use App\Models\RepairService;
use App\Models\InventoryItem;
use App\Models\StockRequestApproval;
use App\Models\User;
use App\Models\ShopOwner;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceItem;
use App\Events\LowStockAlert;
use App\Services\RepairMaterialPlanningService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationService;
use App\Services\PaymentSettlementService;
use App\Services\ShopOwnerApprovalPolicyService;

class RepairWorkflowController extends Controller
{
    private function userHasManagerReviewAccess($user): bool
    {
        if (!$user) {
            return false;
        }

        return $user->hasRole('Manager')
            || $user->can('access-repair-reject-review')
            || $user->can('access-manager-dashboard');
    }

    protected $notificationService;

    public function __construct(
        NotificationService $notificationService,
        private ShopOwnerApprovalPolicyService $shopOwnerApprovalPolicyService,
        private PaymentSettlementService $paymentSettlementService
    )
    {
        $this->notificationService = $notificationService;
    }
    /**
     * Auto-assign repair request to available repairer
     */
    public function assignToRepairer($requestId)
    {
        try {
            $repairRequest = RepairRequest::findOrFail($requestId);

            $selection = $this->resolveRepairerAssignmentCandidate($repairRequest);
            $this->logAssignmentDecision($repairRequest, $selection, 'auto_assign');
            $repairer = $selection['repairer'];
            
            if ($repairer) {
                $repairRequest->update([
                    'assigned_repairer_id' => $repairer->id,
                    'status' => 'assigned_to_repairer'
                ]);
                
                // Send notification to repairer
                $this->notificationService->notifyRepairerAssignment(
                    $repairer->id,
                    [
                        'order_number' => $repairRequest->request_id,
                        'repair_id' => $repairRequest->id,
                        'customer_name' => $repairRequest->customer_name,
                        'service_type' => $repairRequest->delivery_method,
                    ],
                    $repairRequest->shop_owner_id
                );
                
                return response()->json([
                    'success' => true,
                    'message' => 'Repair request assigned successfully',
                    'repairer_id' => $repairer->id,
                    'assignment_strategy' => $selection['strategy'],
                    'required_skill_count' => 0,
                    'matched_required_skill_count' => $selection['matched_skill_count'],
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'No available repairer found',
                'assignment_strategy' => $selection['strategy'],
            ], 404);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign repairer: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Calculate if request is high value and requires owner approval
     */
    public function calculateHighValue($requestId)
    {
        try {
            $repairRequest = RepairRequest::with('shopOwner')->findOrFail($requestId);
            $shopOwner = $repairRequest->shopOwner;
            
            if (!$shopOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Shop owner not found'
                ], 404);
            }
            
            $isHighValue = $repairRequest->total >= $shopOwner->high_value_threshold;
            $requiresOwnerApprovalByPolicy = $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForRepairReject(
                (int) $shopOwner->id,
                (float) $repairRequest->total
            );
            $requiresOwnerApproval = $shopOwner->require_two_way_approval && $requiresOwnerApprovalByPolicy;

            if ($requiresOwnerApprovalByPolicy) {
                $isHighValue = true;
            }
            
            $repairRequest->update([
                'is_high_value' => $isHighValue,
                'requires_owner_approval' => $requiresOwnerApproval
            ]);
            
            return response()->json([
                'success' => true,
                'is_high_value' => $isHighValue,
                'requires_owner_approval' => $requiresOwnerApproval,
                'threshold' => $shopOwner->high_value_threshold
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate high value: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get workflow status for a repair request
     */
    public function getWorkflowStatus($requestId)
    {
        try {
            $repairRequest = RepairRequest::with([
                'user',
                'repairer',
                'manager',
                'shopOwner',
                'conversation',
                'repairerRejectedBy',
                'managerReviewedBy',
                'ownerReviewedBy'
            ])->findOrFail($requestId);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'request_id' => $repairRequest->request_id,
                    'status' => $repairRequest->status,
                    'is_high_value' => $repairRequest->is_high_value,
                    'requires_owner_approval' => $repairRequest->requires_owner_approval,
                    'customer_confirmed_at' => $repairRequest->customer_confirmed_at,
                    'assigned_repairer' => $repairRequest->repairer ? [
                        'id' => $repairRequest->repairer->id,
                        'name' => $repairRequest->repairer->name,
                    ] : null,
                    'assigned_manager' => $repairRequest->manager ? [
                        'id' => $repairRequest->manager->id,
                        'name' => $repairRequest->manager->name,
                    ] : null,
                    'rejection_info' => $repairRequest->repairer_rejection_reason ? [
                        'reason' => $repairRequest->repairer_rejection_reason,
                        'rejected_at' => $repairRequest->repairer_rejected_at,
                        'rejected_by' => $repairRequest->repairerRejectedBy ? $repairRequest->repairerRejectedBy->name : null,
                    ] : null,
                    'manager_review' => $repairRequest->manager_review_notes ? [
                        'notes' => $repairRequest->manager_review_notes,
                        'decision' => $repairRequest->manager_decision,
                        'reviewed_at' => $repairRequest->manager_reviewed_at,
                        'reviewed_by' => $repairRequest->managerReviewedBy ? $repairRequest->managerReviewedBy->name : null,
                    ] : null,
                    'owner_review' => $repairRequest->owner_approval_notes ? [
                        'notes' => $repairRequest->owner_approval_notes,
                        'decision' => $repairRequest->owner_decision,
                        'reviewed_at' => $repairRequest->owner_reviewed_at,
                        'reviewed_by' => $repairRequest->ownerReviewedBy ? $repairRequest->ownerReviewedBy->business_name : null,
                    ] : null,
                    'conversation_id' => $repairRequest->conversation_id,
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get workflow status: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Find shop manager for escalation
     */
    protected function findShopManager($shopOwnerId)
    {
        return User::where('shop_owner_id', $shopOwnerId)
            ->whereHas('roles', function($query) {
                $query->where('name', 'Manager');
            })
            ->where('status', 'active')
            ->first();
    }
    
    /**
     * Get repairs assigned to current repairer (Phase 3)
     */
    public function myAssignedRepairs(Request $request)
    {
        try {
            $jobOrderVisibleRepairs = static function ($query): void {
                $query->where(function ($inner) {
                    $inner->whereNull('request_id')
                        ->orWhere('request_id', 'not like', 'REP-POS-%')
                        ->orWhere(function ($manualPos) {
                            $manualPos->where('request_id', 'like', 'REP-POS-%')
                                ->where(function ($repPosVisibility) {
                                    $repPosVisibility->whereNotNull('assigned_repairer_id')
                                        ->orWhere('manual_pos_queue_enabled', false);
                                });
                        });
                });
            };

            // Check if authenticated as shop owner first
            $shopOwner = Auth::guard('shop_owner')->user();
            
            if ($shopOwner) {
                // Shop owner sees all repairs for their shop
                $repairs = RepairRequest::with(['user', 'services', 'shopOwner', 'repairer'])
                    ->withSum(['posTransactions as pos_paid_amount' => function ($query) {
                        $query->whereIn('status', ['paid', 'partially_refunded', 'refunded']);
                    }], 'paid_amount')
                    ->where('shop_owner_id', $shopOwner->id)
                    ->where($jobOrderVisibleRepairs)
                    ->whereIn('status', ['new_request', 'assigned_to_repairer', 'repairer_accepted', 'waiting_customer_confirmation', 'owner_approval_pending', 'owner_approved', 'confirmed', 'pending', 'in_progress', 'awaiting_parts', 'completed', 'ready_for_pickup', 'shipped', 'picked_up', 'repairer_rejected', 'manager_reviewing', 'manager_rejected', 'owner_rejected', 'rejected', 'cancelled', 'received', 'under-review'])
                    ->orderBy('created_at', 'desc')
                    ->get();

                $repairs->transform(function (RepairRequest $repair): RepairRequest {
                    $repair->setAttribute('total_paid_amount', $this->resolveJobOrderTotalPaidAmount($repair));
                    $this->normalizeRepairTaxModeForPayload($repair);

                    return $repair;
                });
                
                return response()->json([
                    'success' => true,
                    'data' => $repairs
                ]);
            }
            
            // Otherwise check for regular user (repairer)
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }
            
            // Get repairs assigned to this repairer
            $repairs = RepairRequest::with(['user', 'services', 'shopOwner'])
                ->withSum(['posTransactions as pos_paid_amount' => function ($query) {
                    $query->whereIn('status', ['paid', 'partially_refunded', 'refunded']);
                }], 'paid_amount')
                ->forRepairer($user->id)
                ->where($jobOrderVisibleRepairs)
                ->whereIn('status', ['assigned_to_repairer', 'repairer_accepted', 'waiting_customer_confirmation', 'owner_approval_pending', 'owner_approved', 'confirmed', 'pending', 'in_progress', 'awaiting_parts', 'completed', 'ready_for_pickup', 'shipped', 'picked_up', 'repairer_rejected', 'manager_reviewing', 'manager_rejected', 'owner_rejected', 'rejected', 'cancelled', 'received'])
                ->orderBy('created_at', 'desc')
                ->get();

            $repairs->transform(function (RepairRequest $repair): RepairRequest {
                $repair->setAttribute('total_paid_amount', $this->resolveJobOrderTotalPaidAmount($repair));
                $this->normalizeRepairTaxModeForPayload($repair);

                return $repair;
            });
            
            return response()->json([
                'success' => true,
                'data' => $repairs
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch assigned repairs: ' . $e->getMessage()
            ], 500);
        }
    }

    private function normalizeRepairTaxModeForPayload(RepairRequest $repair): void
    {
        $pricingBreakdown = is_array($repair->pricing_breakdown)
            ? $repair->pricing_breakdown
            : [];

        $pricingTaxMode = strtolower((string) ($pricingBreakdown['tax_mode'] ?? ''));
        $pricingMode = strtolower((string) ($pricingBreakdown['mode'] ?? ''));

        if (!in_array($pricingTaxMode, ['vat_inclusive', 'legacy_add_on', 'legacy_additive'], true) && $pricingMode === 'manual_pos') {
            $pricingTaxMode = 'vat_inclusive';
            $pricingBreakdown['tax_mode'] = 'vat_inclusive';
            $repair->setAttribute('pricing_breakdown', $pricingBreakdown);
        }

        if (in_array($pricingTaxMode, ['vat_inclusive', 'legacy_add_on', 'legacy_additive'], true)) {
            $repair->setAttribute('tax_mode', $pricingTaxMode);
        }
    }

    private function resolveJobOrderTotalPaidAmount(RepairRequest $repair): float
    {
        $grandTotal = round((float) ($repair->final_total ?? $repair->total ?? 0), 2);
        $storedPaidAmount = round((float) ($repair->total_paid_amount ?? 0), 2);
        $posLedgerPaidAmount = round((float) ($repair->pos_paid_amount ?? 0), 2);
        $resolved = max(0.0, $storedPaidAmount, $posLedgerPaidAmount);

        $paymentStatus = strtolower(trim((string) ($repair->payment_status ?? 'pending')));
        $policy = $this->paymentSettlementService->normalizeRepairPaymentPolicy((string) ($repair->payment_policy ?? 'deposit_50'));

        if ($paymentStatus === 'completed') {
            return round(max($resolved, $grandTotal), 2);
        }

        if ($paymentStatus === 'paid' || $paymentStatus === 'partially_paid') {
            $phaseAmount = $policy === 'full_upfront'
                ? $grandTotal
                : round($grandTotal * 0.5, 2);

            return round(max($resolved, $phaseAmount), 2);
        }

        return round($resolved, 2);
    }

    public function validateMaterialStart($id, RepairMaterialPlanningService $planner)
    {
        $repair = $this->resolveRepairForMaterialValidation((int) $id);
        $this->ensureRepairMaterialPlanItems($repair);
        $repair->load('materialPlanItems');
        $result = $planner->validateStartReadiness($repair);

        if ($result['readiness_state'] === 'blocked') {
            return response()->json(['success' => false, 'data' => $result], 422);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function validateMaterialCompletion($id, RepairMaterialPlanningService $planner)
    {
        $repair = $this->resolveRepairForMaterialValidation((int) $id);
        $this->ensureRepairMaterialPlanItems($repair);
        $repair->load('materialPlanItems');
        $result = $planner->validateCompletionReadiness($repair);

        if ($result['readiness_state'] !== 'ready') {
            return response()->json(['success' => false, 'data' => $result], 422);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    private function resolveRepairForMaterialValidation(int $id): RepairRequest
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        $repairQuery = RepairRequest::query()
            ->where('id', $id)
            ->where('shop_owner_id', $user->shop_owner_id);

        $isManager = method_exists($user, 'hasRole') ? $user->hasRole('Manager') : false;
        if (!$isManager) {
            $repairQuery->where('assigned_repairer_id', $user->id);
        }

        return $repairQuery->firstOrFail();
    }
    
    /**
     * Repairer accepts repair request and creates conversation (Phase 3)
     */
    public function acceptRepair(Request $request, $requestId)
    {
        try {
            DB::beginTransaction();
            
            // Check if authenticated as shop owner first
            $shopOwner = Auth::guard('shop_owner')->user();
            $user = Auth::guard('user')->user();
            
            if (!$shopOwner && !$user) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }
            
            $repairRequest = RepairRequest::with('user')->findOrFail($requestId);
            
            // Handle shop owner acceptance (for individual shop owners doing repairs themselves)
            if ($shopOwner) {
                // Verify this repair belongs to the shop owner
                if ($repairRequest->shop_owner_id != $shopOwner->id) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'This repair does not belong to your shop'
                    ], 403);
                }
                
                // Verify status is new_request or assigned_to_repairer
                if (!in_array($repairRequest->status, ['new_request', 'assigned_to_repairer'])) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Repair request cannot be accepted in current status'
                    ], 400);
                }

                // Enforce repair workload limit
                $workloadLimit = (int) ($shopOwner->repair_workload_limit ?? 20);
                $activeStatuses = ['assigned_to_repairer', 'repairer_accepted', 'pending', 'received',
                    'in-progress', 'in_progress', 'awaiting_parts', 'waiting_customer_confirmation',
                    'completed', 'ready-for-pickup', 'ready_for_pickup'];
                $activeCount = RepairRequest::where('shop_owner_id', $shopOwner->id)
                    ->whereIn('status', $activeStatuses)
                    ->count();
                if ($activeCount >= $workloadLimit) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Repair workload limit reached ({$workloadLimit} active repairs). Please complete existing repairs before accepting new ones.",
                    ], 422);
                }
                $conversation = null;
                if ((int) ($repairRequest->user_id ?? 0) > 0) {
                    $conversation = Conversation::firstOrCreate(
                        [
                            'shop_owner_id' => $repairRequest->shop_owner_id,
                            'customer_id' => $repairRequest->user_id,
                        ],
                        [
                            'assigned_to_id' => $shopOwner->id,
                            'assigned_to_type' => 'shop_owner',
                            'status' => 'open',
                            'priority' => 'medium',
                            'last_message_at' => now(),
                        ]
                    );

                    // Normalize assignment when reusing an existing conversation created by other channels.
                    $conversation->update([
                        'assigned_to_id' => $shopOwner->id,
                        'assigned_to_type' => 'shop_owner',
                        'last_message_at' => now(),
                    ]);
                }
                
                // Determine next status based on delivery method
                // Walk-in: Customer needs to confirm/bring item → 'pending'
                // Pickup: Awaiting pickup confirmation → 'repairer_accepted'
                $nextStatus = $repairRequest->delivery_method === 'walk_in' 
                    ? 'pending' 
                    : 'repairer_accepted';
                
                // Update repair request
                $repairRequest->update([
                    'status' => $nextStatus,
                    'conversation_id' => $conversation?->id,
                ]);
                
                // Send automatic system message about the repair
                $repairType = $repairRequest->repair_type ?? 'Repair';
                $deliveryMethod = $repairRequest->delivery_method === 'walk_in' ? 'Walk-in' : 'Pickup';
                $systemMessage = "🔧 **New Repair Order Accepted**\n\n";
                $systemMessage .= "**Type:** {$repairType}\n";
                $systemMessage .= "**Delivery:** {$deliveryMethod}\n";
                $systemMessage .= "**Status:** " . ($nextStatus === 'pending' ? 'Waiting for item drop-off' : 'Accepted') . "\n\n";
                $systemMessage .= $repairRequest->delivery_method === 'walk_in' 
                    ? "Please bring your item to our shop at your convenience." 
                    : "We'll pick up your item as scheduled.";
                
                if ($conversation) {
                    $messageRecord = ConversationMessage::create([
                        'conversation_id' => $conversation->id,
                        'sender_type' => 'system',
                        'sender_id' => $shopOwner->id,
                        'content' => $systemMessage,
                    ]);

                    // Update conversation last message time to trigger refresh
                    $conversation->update(['last_message_at' => $messageRecord->created_at]);
                }
                
                DB::commit();

                // Notify customer that their repair was accepted
                if ($repairRequest->user_id) {
                    try {
                        $this->notificationService->notifyRepairAccepted($repairRequest->user_id, [
                            'order_number'  => $repairRequest->request_id,
                            'repair_id'     => $repairRequest->id,
                            'customer_name' => $repairRequest->customer_name,
                        ]);
                    } catch (\Exception $e) {
                        \Log::warning('Could not notify customer of repair acceptance: ' . $e->getMessage());
                    }
                }

                $message = $repairRequest->delivery_method === 'walk_in' 
                    ? 'Repair accepted. Waiting for customer to bring the item in.'
                    : 'Repair accepted. Chat conversation updated with customer.';
                
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'conversation_id' => $conversation?->id,
                    'repair' => $repairRequest->fresh(['user', 'services', 'conversation'])
                ]);
            }
            
            // Handle staff/repairer acceptance
            // Verify this repair is assigned to current user
            if ($repairRequest->assigned_repairer_id != $user->id) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'This repair is not assigned to you'
                ], 403);
            }
            
            // Verify status is correct
            if ($repairRequest->status !== 'assigned_to_repairer') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Repair request cannot be accepted in current status'
                ], 400);
            }

            // Enforce repair workload limit (load shop owner for the limit value)
            $repairShopOwner = ShopOwner::find($repairRequest->shop_owner_id);
            if ($repairShopOwner) {
                $workloadLimit = (int) ($repairShopOwner->repair_workload_limit ?? 20);
                $activeStatuses = ['assigned_to_repairer', 'repairer_accepted', 'pending', 'received',
                    'in-progress', 'in_progress', 'awaiting_parts', 'waiting_customer_confirmation',
                    'completed', 'ready-for-pickup', 'ready_for_pickup'];
                $activeCount = RepairRequest::where('shop_owner_id', $repairShopOwner->id)
                    ->where('assigned_repairer_id', $user->id)
                    ->where('id', '!=', $repairRequest->id)
                    ->whereIn('status', $activeStatuses)
                    ->count();
                if ($activeCount >= $workloadLimit) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Repair workload limit reached ({$workloadLimit} active repairs per repairer). Please complete existing repairs before accepting new ones.",
                    ], 422);
                }
            }
            $conversation = null;
            if ((int) ($repairRequest->user_id ?? 0) > 0) {
                $conversation = Conversation::firstOrCreate(
                    [
                        'shop_owner_id' => $repairRequest->shop_owner_id,
                        'customer_id' => $repairRequest->user_id,
                    ],
                    [
                        'assigned_to_id' => $user->id,
                        'assigned_to_type' => 'repairer',
                        'status' => 'open',
                        'priority' => 'medium',
                        'last_message_at' => now(),
                    ]
                );

                // Update conversation assignment to current repairer
                $conversation->update([
                    'assigned_to_id' => $user->id,
                    'assigned_to_type' => 'repairer',
                    'last_message_at' => now(),
                ]);
            }
            
            // Determine next status based on delivery method
            // Walk-in: Customer needs to confirm/bring item → 'pending'
            // Pickup: Awaiting pickup confirmation → 'repairer_accepted'
            $nextStatus = $repairRequest->delivery_method === 'walk_in' 
                ? 'pending' 
                : 'repairer_accepted';
            
            // Update repair request
            $repairRequest->update([
                'status' => $nextStatus,
                'conversation_id' => $conversation?->id,
            ]);
            
            // Send automatic system message about the repair
            $repairType = $repairRequest->repair_type ?? 'Repair';
            $deliveryMethod = $repairRequest->delivery_method === 'walk_in' ? 'Walk-in' : 'Pickup';
            $repairerName = $user->name ?? 'Repairer';
            $systemMessage = "🔧 **Repair Order Accepted by {$repairerName}**\n\n";
            $systemMessage .= "**Type:** {$repairType}\n";
            $systemMessage .= "**Delivery:** {$deliveryMethod}\n";
            $systemMessage .= "**Status:** " . ($nextStatus === 'pending' ? 'Waiting for item drop-off' : 'Accepted') . "\n\n";
            $systemMessage .= $repairRequest->delivery_method === 'walk_in' 
                ? "Please bring your item to our shop at your convenience." 
                : "We'll pick up your item as scheduled.";
            
            if ($conversation) {
                $messageRecord = ConversationMessage::create([
                    'conversation_id' => $conversation->id,
                    'sender_type' => 'system',
                    'sender_id' => $user->id,
                    'content' => $systemMessage,
                ]);

                // Update conversation last message time to trigger refresh
                $conversation->update(['last_message_at' => $messageRecord->created_at]);
            }
            
            DB::commit();

            // Notify customer that their repair was accepted
            if ($repairRequest->user_id) {
                try {
                    $this->notificationService->notifyRepairAccepted($repairRequest->user_id, [
                        'order_number'  => $repairRequest->request_id,
                        'repair_id'     => $repairRequest->id,
                        'customer_name' => $repairRequest->customer_name,
                    ]);
                } catch (\Exception $e) {
                    \Log::warning('Could not notify customer of repair acceptance: ' . $e->getMessage());
                }
            }

            $message = $repairRequest->delivery_method === 'walk_in' 
                ? 'Repair accepted. Waiting for customer to bring the item in.'
                : 'Repair accepted. Chat conversation updated with customer.';
            
            return response()->json([
                'success' => true,
                'message' => $message,
                'conversation_id' => $conversation?->id,
                'repair' => $repairRequest->fresh(['user', 'services', 'conversation'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to accept repair: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Repairer rejects repair request (Phase 3)
     */
    /**
     * Reject repair request
     * - Shop owners (individual): Can reject repairs for their shop directly (final rejection)
     * - Repairers (company with staff): Rejection escalates to manager for review
     */
    public function rejectRepair(Request $request, $requestId)
    {
        $request->validate([
            'reason' => 'nullable|string|min:10|max:500',
            'reason_text' => 'nullable|string|min:10|max:500',
            'reason_category' => 'nullable|in:skills_gap,workload,parts_unavailable,safety_risk,quality_concern,other',
        ]);

        $reasonText = trim((string) ($request->input('reason_text') ?? $request->input('reason') ?? ''));
        $reasonCategory = (string) ($request->input('reason_category') ?? 'other');

        if ($reasonText === '') {
            return response()->json([
                'success' => false,
                'message' => 'A rejection reason is required.'
            ], 422);
        }
        
        try {
            DB::beginTransaction();
            
            // Check if authenticated as shop owner first
            $shopOwner = Auth::guard('shop_owner')->user();
            
            if ($shopOwner) {
                // Individual shop owner rejecting repair directly (final rejection)
                $repairRequest = RepairRequest::findOrFail($requestId);
                
                // Verify this repair belongs to the shop owner
                if ($repairRequest->shop_owner_id != $shopOwner->id) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'This repair does not belong to your shop'
                    ], 403);
                }
                
                // Verify status allows rejection
                if (!in_array($repairRequest->status, ['new_request', 'assigned_to_repairer', 'repairer_accepted', 'pending'])) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Repair request cannot be rejected in current status'
                    ], 400);
                }
                
                // Shop owner rejection is FINAL - uses owner_rejected status (no manager approval needed)
                $repairRequest->update([
                    'status' => 'owner_rejected',
                    'owner_decision' => 'rejected',
                    'owner_approval_notes' => $reasonText,
                    'owner_reviewed_at' => now(),
                    'owner_reviewed_by' => $shopOwner->id,
                ]);
                
                DB::commit();

                // Notify customer of rejection
                if ($repairRequest->user_id) {
                    try {
                        $this->notificationService->notifyRepairRejected($repairRequest->user_id, [
                            'order_number' => $repairRequest->request_id,
                            'reason'       => $reasonText,
                        ]);
                    } catch (\Exception $e) {
                        \Log::warning('Could not notify customer of repair rejection: ' . $e->getMessage());
                    }
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Repair request rejected. Customer will be notified.',
                    'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
                ]);
            }
            
            // Otherwise check for regular user (repairer) - escalates to manager
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }
            
            $repairRequest = RepairRequest::findOrFail($requestId);
            
            // Verify this repair is assigned to current user
            if ($repairRequest->assigned_repairer_id != $user->id) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'This repair is not assigned to you'
                ], 403);
            }
            
            // Verify status allows rejection
            if (!in_array($repairRequest->status, ['assigned_to_repairer', 'repairer_accepted'])) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Repair request cannot be rejected in current status'
                ], 400);
            }
            
            // Find manager for escalation
            $manager = $this->findShopManager($repairRequest->shop_owner_id);
            $missingSkillNames = $reasonCategory === 'skills_gap'
                ? []
                : [];
            
            $repairRequest->update([
                'status' => 'repairer_rejected',
                'repairer_rejection_reason' => $reasonText,
                'repairer_rejection_reason_category' => $reasonCategory,
                'repairer_rejected_at' => now(),
                'repairer_rejected_by' => $user->id,
                'assigned_manager_id' => $manager ? $manager->id : null,
                // Start a fresh manager-review cycle for this new rejection.
                'manager_decision' => null,
                'manager_review_notes' => null,
                'manager_reviewed_at' => null,
                'manager_reviewed_by' => null,
                'owner_decision' => null,
                'owner_review_notes' => null,
                'owner_reviewed_at' => null,
                'owner_reviewed_by' => null,
            ]);
            
            DB::commit();

            // Notify Manager role users to review the rejection
            try {
                $this->notificationService->notifyRepairRejectedToManager($repairRequest->shop_owner_id, [
                    'order_number' => $repairRequest->request_id,
                    'repair_id'    => $repairRequest->id,
                    'reason'       => $reasonText,
                    'reason_category' => $reasonCategory,
                    'missing_skills' => $missingSkillNames,
                ]);
            } catch (\Exception $e) {
                \Log::warning('Could not notify manager of repair rejection: ' . $e->getMessage());
            }

            $suggestedRepairer = $reasonCategory === 'skills_gap'
                ? $this->buildSuggestedRepairerPayload($repairRequest, (int) $user->id)
                : null;

            return response()->json([
                'success' => true,
                'message' => 'Repair rejected. Manager has been notified for review.',
                'repair' => $repairRequest->fresh(['user', 'services', 'manager']),
                'rejection_reason' => [
                    'category' => $reasonCategory,
                    'text' => $reasonText,
                    'missing_skills' => $missingSkillNames,
                ],
                'suggested_repairer' => $suggestedRepairer,
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject repair: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get repairs pending manager review (Phase 5)
     */
    public function getPendingManagerReviews(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }
            
            if (!$this->userHasManagerReviewAccess($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Manager role required.'
                ], 403);
            }
            
            // Manager inbox for rejection workflow:
            // - Initial review: repairer_rejected
            // - Final review after owner approval: manager_reviewing
            // Include resolved entries for reference/history in the same table.
            $repairs = RepairRequest::with([
                'user', 
                'services', 
                'shopOwner', 
                'repairer',
                'repairerRejectedBy',
                'managerReviewedBy'
            ])
                ->where('shop_owner_id', $user->shop_owner_id)
                ->whereNotNull('repairer_rejected_at')
                ->whereIn('status', ['repairer_rejected', 'manager_reviewing', 'rejected', 'assigned_to_repairer'])
                ->orderBy('repairer_rejected_at', 'desc')
                ->get();

            $repairs->each(function (RepairRequest $repair) {
                $excludeUserId = (int) ($repair->repairer_rejected_by ?? 0);
                $repair->setAttribute(
                    'suggested_repairer',
                    $this->buildSuggestedRepairerPayload($repair, $excludeUserId > 0 ? $excludeUserId : null)
                );
            });
            
            return response()->json([
                'success' => true,
                'data' => $repairs
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch manager reviews: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Manager first approval: forwards repairer rejection to shop owner.
     */
    public function approveRejection(Request $request, $requestId)
    {
        $request->validate([
            'notes' => 'nullable|string|max:500'
        ]);
        
        try {
            DB::beginTransaction();
            
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }
            
            if (!$this->userHasManagerReviewAccess($user)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Manager role required.'
                ], 403);
            }
            
            $repairRequest = RepairRequest::findOrFail($requestId);
            
            // Verify this is from same shop
            if ($repairRequest->shop_owner_id != $user->shop_owner_id) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }
            
            // Verify status is in manager's first-approval stage
            if ($repairRequest->status !== 'repairer_rejected') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Repair is not in manager initial approval stage'
                ], 400);
            }
            
                $repairRequest->update([
                    'status' => 'owner_approval_pending',
                    'manager_decision' => 'approve_rejection',
                'manager_review_notes' => $request->notes,
                'manager_reviewed_at' => now(),
                'manager_reviewed_by' => $user->id,
            ]);
            
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Initial approval completed. Rejection forwarded to shop owner for review.',
                'repair' => $repairRequest->fresh(['user', 'services', 'managerReviewedBy'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve rejection: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Manager final approval: closes the rejection workflow as final rejected.
     */
    public function finalizeRejection(Request $request, $requestId)
    {
        $request->validate([
            'notes' => 'nullable|string|max:500'
        ]);

        try {
            DB::beginTransaction();

            $user = Auth::guard('user')->user();

            if (!$user) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            if (!$this->userHasManagerReviewAccess($user)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Manager role required.'
                ], 403);
            }

            $repairRequest = RepairRequest::findOrFail($requestId);

            if ($repairRequest->shop_owner_id != $user->shop_owner_id) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            if ($repairRequest->status !== 'manager_reviewing') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Repair is not pending manager final approval'
                ], 400);
            }

            $finalNotes = trim((string) ($request->notes ?? ''));
            $combinedNotes = trim(implode("\n\n", array_filter([
                $repairRequest->manager_review_notes,
                $finalNotes !== '' ? 'Final approval notes: ' . $finalNotes : null,
            ])));

            $repairRequest->update([
                'status' => 'rejected',
                'manager_decision' => 'approve_rejection',
                'manager_review_notes' => $combinedNotes !== '' ? $combinedNotes : $repairRequest->manager_review_notes,
                'manager_reviewed_at' => now(),
                'manager_reviewed_by' => $user->id,
            ]);

            DB::commit();

            if ($repairRequest->user_id) {
                try {
                    $notificationReason = trim((string) ($repairRequest->repairer_rejection_reason
                        ?? $repairRequest->owner_review_notes
                        ?? $finalNotes));

                    $this->notificationService->notifyRepairRejected($repairRequest->user_id, [
                        'order_number' => $repairRequest->request_id,
                        'reason' => $notificationReason !== ''
                            ? $notificationReason
                            : 'The repair could not be processed at this time.',
                    ]);
                } catch (\Exception $e) {
                    \Log::warning('Could not notify customer of final repair rejection: ' . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Final approval completed. Repair request is now rejected.',
                'repair' => $repairRequest->fresh(['user', 'services', 'managerReviewedBy'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to finalize rejection: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Manager overrides repairer's rejection and reassigns (Phase 5)
     */
    public function overrideRejection(Request $request, $requestId)
    {
        $request->validate([
            'notes' => 'required|string|min:10|max:500',
            'repairer_id' => 'required|integer|exists:users,id'
        ]);
        
        try {
            DB::beginTransaction();
            
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }
            
            if (!$this->userHasManagerReviewAccess($user)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Manager role required.'
                ], 403);
            }
            
            $repairRequest = RepairRequest::findOrFail($requestId);
            
            // Verify this is from same shop
            if ($repairRequest->shop_owner_id != $user->shop_owner_id) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }
            
            // Verify status
            if ($repairRequest->status !== 'repairer_rejected') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Override is only allowed at manager initial review stage'
                ], 400);
            }
            
            // Manager must explicitly choose a repairer from the available list.
            $rejectedById = (int) ($repairRequest->repairer_rejected_by_id ?? $repairRequest->repairer_rejected_by ?? 0);
            $workloadLimit = $this->getRepairWorkloadLimitForShop((int) $repairRequest->shop_owner_id);

            $selectedRepairerId = (int) $request->repairer_id;
            $newRepairer = User::findOrFail($selectedRepairerId);

            // Verify the selected repairer is in the same shop and has Repairer role
            if ($newRepairer->shop_owner_id != $repairRequest->shop_owner_id || !$newRepairer->hasRole('Repairer')) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid repairer selection'
                ], 400);
            }

            // Verify it's not the same repairer who rejected it
            if ($newRepairer->id === $rejectedById) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot reassign to the repairer who rejected it'
                ], 400);
            }

            $selectedRepairerActiveCount = RepairRequest::query()
                ->where('shop_owner_id', $repairRequest->shop_owner_id)
                ->where('assigned_repairer_id', $newRepairer->id)
                ->whereIn('status', $this->getActiveRepairStatuses())
                ->count();

            if ($selectedRepairerActiveCount >= $workloadLimit) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => "Selected repairer is at workload limit ({$selectedRepairerActiveCount}/{$workloadLimit} active repairs). Choose another repairer."
                ], 422);
            }

            $selection = [
                'repairer' => $newRepairer,
                'strategy' => 'manager_selection',
                'matched_skill_count' => 0,
            ];
            
            $this->logAssignmentDecision(
                $repairRequest,
                $selection,
                'manager_override_reassignment',
                $rejectedById > 0 ? $rejectedById : null
            );
            
                $repairRequest->update([
                    'status' => 'manager_rejected',
                    'manager_decision' => 'override_accept',
                'manager_review_notes' => $request->notes,
                'manager_reviewed_at' => now(),
                'manager_reviewed_by' => $user->id,
                'assigned_repairer_id' => $newRepairer ? $newRepairer->id : null,
            ]);
            
            // If new repairer found, change status to assigned
                if ($newRepairer) {
                    $repairRequest->update(['status' => 'assigned_to_repairer']);
                    
                    // Send notification to new repairer
                    $this->notificationService->notifyRepairerAssignment(
                        $newRepairer->id,
                        [
                            'order_number' => $repairRequest->request_id,
                            'repair_id' => $repairRequest->id,
                            'customer_name' => $repairRequest->customer_name,
                            'service_type' => $repairRequest->delivery_method,
                        ],
                        $repairRequest->shop_owner_id
                    );
                }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Rejection overridden. Repair reassigned to the selected repairer.',
                'assignment_strategy' => $selection['strategy'],
                'suggested_repairer' => $this->buildSuggestedRepairerPayload(
                    $repairRequest,
                    $rejectedById > 0 ? $rejectedById : null
                ),
                'repair' => $repairRequest->fresh(['user', 'services', 'repairer', 'managerReviewedBy'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to override rejection: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get rejection requests waiting for shop-owner approval (rejection workflow only).
     */
    public function getOwnerRejectionPendingApprovals(Request $request)
    {
        try {
            $shopOwner = Auth::guard('shop_owner')->user();

            if (!$shopOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $repairs = RepairRequest::with([
                'user:id,first_name,last_name,email,phone',
                'services:id,name,price',
                'repairer:id,first_name,last_name',
                'repairerRejectedBy',
                'managerReviewedBy',
                'ownerReviewedBy',
            ])
                ->where('shop_owner_id', $shopOwner->id)
                ->whereNotNull('repairer_rejected_at')
                ->whereIn('status', ['owner_approval_pending', 'manager_reviewing', 'assigned_to_repairer', 'rejected'])
                ->orderByDesc('repairer_rejected_at')
                ->get();

            return response()->json([
                'success' => true,
                'repairs' => $repairs,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch owner rejection approvals: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Shop-owner approval step for rejection workflow.
     */
    public function approveOwnerRejection(Request $request, $id)
    {
        $request->validate([
            'notes' => 'nullable|string|max:500'
        ]);

        try {
            $shopOwner = Auth::guard('shop_owner')->user();

            if (!$shopOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            DB::beginTransaction();

            $repairRequest = RepairRequest::where('id', $id)
                ->where('shop_owner_id', $shopOwner->id)
                ->firstOrFail();

            if ($repairRequest->status !== 'owner_approval_pending') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Repair is not pending owner rejection approval'
                ], 400);
            }

            $repairRequest->update([
                'status' => 'manager_reviewing',
                'owner_decision' => 'approved',
                'owner_review_notes' => $request->notes,
                'owner_reviewed_at' => now(),
                'owner_reviewed_by' => $shopOwner->id,
                'owner_reviewed_by_id' => $shopOwner->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Owner approval completed. Request is now pending manager final approval.',
                'repair' => $repairRequest->fresh(['user', 'services', 'repairer', 'ownerReviewedBy'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve rejection: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Shop-owner rejects the rejection request and returns repair to assigned flow.
     */
    public function rejectOwnerRejection(Request $request, $id)
    {
        $request->validate([
            'notes' => 'required|string|min:10|max:500'
        ]);

        try {
            $shopOwner = Auth::guard('shop_owner')->user();

            if (!$shopOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            DB::beginTransaction();

            $repairRequest = RepairRequest::where('id', $id)
                ->where('shop_owner_id', $shopOwner->id)
                ->firstOrFail();

            if ($repairRequest->status !== 'owner_approval_pending') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Repair is not pending owner rejection approval'
                ], 400);
            }

            $repairRequest->update([
                'status' => 'assigned_to_repairer',
                'owner_decision' => 'rejected',
                'owner_review_notes' => $request->notes,
                'owner_reviewed_at' => now(),
                'owner_reviewed_by' => $shopOwner->id,
                'owner_reviewed_by_id' => $shopOwner->id,
                'manager_decision' => null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Owner rejected the rejection request. Repair returned to assigned workflow.',
                'repair' => $repairRequest->fresh(['user', 'services', 'repairer', 'ownerReviewedBy'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject rejection request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available repairers for a repair (workload-based only)
     * Used by manager to select repairers for override
     */
    public function getAvailableRepairersForRepair(Request $request, $repairRequestId)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$this->userHasManagerReviewAccess($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $repairRequest = RepairRequest::findOrFail($repairRequestId);
            
            if ($repairRequest->shop_owner_id != $user->shop_owner_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $workloadLimit = $this->getRepairWorkloadLimitForShop((int) $repairRequest->shop_owner_id);

            // Exclude the repairer who rejected it
            $excludeUserId = (int) ($repairRequest->repairer_rejected_by_id ?? $repairRequest->repairer_rejected_by ?? 0);

            // Get all active repairers in the shop
            $baseQuery = User::query()
                ->where('shop_owner_id', $repairRequest->shop_owner_id)
                ->whereHas('roles', function ($query) {
                    $query->where('name', 'Repairer');
                })
                ->where('status', 'active')
                ->when($excludeUserId, function ($query) use ($excludeUserId) {
                    $query->where('id', '!=', $excludeUserId);
                })
                ->withCount([
                    'assignedRepairs as active_repairs_count' => function ($query) {
                        $query->whereIn('status', $this->getActiveRepairStatuses());
                    }
                ])
                ->having('active_repairs_count', '<', $workloadLimit);

            $repairers = $baseQuery->get()->map(function ($repairer) {
                return [
                    'id' => $repairer->id,
                    'name' => $repairer->name,
                    'email' => $repairer->email,
                    'phone' => $repairer->phone,
                    'active_repairs' => $repairer->active_repairs_count ?? 0,
                ];
            });

            // Sort by workload then by ID
            $repairers = $repairers->sortBy(function ($r) {
                return [
                    $r['active_repairs'],                           // lower workload first
                    $r['id'],                                       // then by ID
                ];
            })->values();

            // Check if there are any available repairers
            if ($repairers->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot override rejection: No other repairers are available in this shop to reassign to.',
                    'can_override' => false,
                    'repair_id' => $repairRequest->id,
                ], 400);
            }

            return response()->json([
                'success' => true,
                'repair_id' => $repairRequest->id,
                'repair_request_number' => $repairRequest->request_id,
                'available_repairers' => $repairers,
                'can_override' => true,
                'workload_limit_per_repairer' => $workloadLimit,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch available repairers: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get high-value repairs pending shop owner approval
     * For shop owners only
     */
    public function getHighValuePendingApprovals()
    {
        try {
            $shopOwner = Auth::guard('shop_owner')->user();
            
            $repairs = RepairRequest::where('shop_owner_id', $shopOwner->id)
                ->where('is_high_value', true)
                ->where('requires_owner_approval', true)
                ->where('status', 'owner_approval_pending')
                ->with([
                    'user:id,first_name,last_name,email,phone',
                    'services:id,name,price',
                    'repairer:id,first_name,last_name',
                    'conversation'
                ])
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'repairs' => $repairs
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch high-value repairs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve high-value repair request
     * Allows repairer to start work
     */
    public function approveHighValueRepair(Request $request, $id)
    {
        try {
            $shopOwner = Auth::guard('shop_owner')->user();
            
            DB::beginTransaction();
            
            $repairRequest = RepairRequest::where('id', $id)
                ->where('shop_owner_id', $shopOwner->id)
                ->firstOrFail();
            
            if ($repairRequest->status !== 'owner_approval_pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Repair is not pending owner approval'
                ], 400);
            }
            
            $repairRequest->update([
                'status' => 'owner_approved',
                'owner_decision' => 'approve',
                'owner_approval_notes' => $request->notes,
                'owner_reviewed_at' => now(),
                'owner_reviewed_by_id' => $shopOwner->id,
            ]);
            
            DB::commit();
            
            // TODO: Send notification to repairer that they can start work
            // TODO: Send notification to customer that repair is approved
            
            return response()->json([
                'success' => true,
                'message' => 'High-value repair approved. Repairer can now start work.',
                'repair' => $repairRequest->fresh(['user', 'services', 'repairer', 'ownerReviewedBy'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve repair: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject high-value repair request
     * Cancels the repair and notifies customer
     */
    public function rejectHighValueRepair(Request $request, $id)
    {
        $request->validate([
            'notes' => 'required|string|min:10'
        ]);
        
        try {
            $shopOwner = Auth::guard('shop_owner')->user();
            
            DB::beginTransaction();
            
            $repairRequest = RepairRequest::where('id', $id)
                ->where('shop_owner_id', $shopOwner->id)
                ->firstOrFail();
            
            if ($repairRequest->status !== 'owner_approval_pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Repair is not pending owner approval'
                ], 400);
            }
            
            $repairRequest->update([
                'status' => 'owner_rejected',
                'owner_decision' => 'reject',
                'owner_approval_notes' => $request->notes,
                'owner_reviewed_at' => now(),
                'owner_reviewed_by_id' => $shopOwner->id,
            ]);
            
            DB::commit();
            
            // TODO: Send notification to customer explaining rejection
            // TODO: Potentially trigger refund if payment was made
            
            return response()->json([
                'success' => true,
                'message' => 'High-value repair rejected. Customer will be notified.',
                'repair' => $repairRequest->fresh(['user', 'services', 'ownerReviewedBy'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject repair: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start work on repair (Phase 8 - Work Progress)
     * Changes status from owner_approved/waiting_customer_confirmation to in_progress
     */
    public function startWork($id)
    {
        try {
            // Check if authenticated as shop owner or user (staff/repairer)
            $shopOwner = Auth::guard('shop_owner')->user();
            $user = Auth::guard('user')->user();
            
            if (!$shopOwner && !$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            DB::beginTransaction();
            
            // Build query based on authentication type
            $query = RepairRequest::where('id', $id);
            
            if ($shopOwner) {
                // Shop owner can start work on their own repairs
                $query->where('shop_owner_id', $shopOwner->id);
            } else {
                // Staff/repairer must be assigned
                $query->where('assigned_repairer_id', $user->id);
            }
            
            $repairRequest = $query->whereIn('status', ['owner_approved', 'waiting_customer_confirmation', 'confirmed', 'received'])
                ->firstOrFail();
            
            // Validate payment is completed (or deposit paid) before starting work
            if ($repairRequest->payment_enabled && !$this->isPaymentSatisfiedForRepairProgress($repairRequest)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Payment must be completed before starting work on this repair.'
                ], 400);
            }
            
            $repairRequest->update([
                'status' => 'in_progress',
                'started_at' => now(),
            ]);
            
            DB::commit();
            
            // TODO: Send notification to customer that work has started
            
            return response()->json([
                'success' => true,
                'message' => 'Work started. Status updated to In Progress.',
                'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to start work: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark repair as awaiting parts/materials
     */
    public function markAwaitingParts(Request $request, $id)
    {
        $request->validate([
            'notes' => 'required|string|min:10'
        ]);

        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $repairRequest = RepairRequest::where('id', $id)
                ->where('assigned_repairer_id', $user->id)
                ->where('status', 'in_progress')
                ->firstOrFail();
            
            $repairRequest->update([
                'status' => 'awaiting_parts',
                'awaiting_parts_notes' => $request->notes,
                'awaiting_parts_since' => now(),
            ]);
            
            DB::commit();
            
            // TODO: Send notification to customer about parts delay
            
            return response()->json([
                'success' => true,
                'message' => 'Status updated to Awaiting Parts. Customer will be notified.',
                'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resume work after parts arrive
     */
    public function resumeWork($id)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            DB::beginTransaction();
            
            $repairRequest = RepairRequest::where('id', $id)
                ->where('assigned_repairer_id', $user->id)
                ->where('status', 'awaiting_parts')
                ->firstOrFail();
            
            $repairRequest->update([
                'status' => 'in_progress',
            ]);
            
            DB::commit();
            
            // TODO: Send notification to customer that work resumed
            
            return response()->json([
                'success' => true,
                'message' => 'Work resumed. Status updated to In Progress.',
                'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to resume work: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark repair as completed (work finished, QC done)
     */
    public function markCompleted(Request $request, $id)
    {
        $request->validate([
            'completion_notes' => 'nullable|string|max:500',
            'no_materials_used_confirmed' => 'nullable|boolean',
        ]);

        $noMaterialsUsedConfirmed = $request->boolean('no_materials_used_confirmed');

        try {
            // Check if authenticated as shop owner first
            $shopOwner = Auth::guard('shop_owner')->user();
            
            if ($shopOwner) {
                $repairRequest = RepairRequest::where('id', $id)
                    ->where('shop_owner_id', $shopOwner->id)
                    ->where('status', 'in_progress')
                    ->firstOrFail();

                $materialGateResponse = $this->validateMaterialLoggingGateForTransition(
                    $repairRequest,
                    $noMaterialsUsedConfirmed,
                    'completed'
                );
                if ($materialGateResponse) {
                    return $materialGateResponse;
                }

                $completionGateResponse = $this->validateMaterialCompletionGateForTransition($repairRequest);
                if ($completionGateResponse) {
                    return $completionGateResponse;
                }

                // Shop owner can mark any repair for their shop as completed
                DB::beginTransaction();
                
                $repairRequest->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'completion_notes' => $request->completion_notes,
                ]);
                
                DB::commit();
                
                // TODO: Send notification to customer that repair is completed
                
                return response()->json([
                    'success' => true,
                    'message' => 'Repair marked as completed. Customer will be notified.',
                    'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
                ]);
            }
            
            // Otherwise check for regular user (repairer)
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }
            
            $repairRequest = RepairRequest::where('id', $id)
                ->where('assigned_repairer_id', $user->id)
                ->where('status', 'in_progress')
                ->firstOrFail();

            $materialGateResponse = $this->validateMaterialLoggingGateForTransition(
                $repairRequest,
                $noMaterialsUsedConfirmed,
                'completed'
            );
            if ($materialGateResponse) {
                return $materialGateResponse;
            }

            DB::beginTransaction();
            
            $repairRequest->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completion_notes' => $request->completion_notes,
            ]);
            
            DB::commit();
            
            // TODO: Send notification to customer that repair is completed
            
            return response()->json([
                'success' => true,
                'message' => 'Repair marked as completed. Customer will be notified.',
                'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark as completed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark repair as ready for pickup/delivery
     */
    public function markReadyForPickup(Request $request, $id)
    {
        $request->validate([
            'pickup_instructions' => 'nullable|string|max:500',
            'no_materials_used_confirmed' => 'nullable|boolean',
        ]);

        $noMaterialsUsedConfirmed = $request->boolean('no_materials_used_confirmed');

        try {
            // Check if authenticated as shop owner first
            $shopOwner = Auth::guard('shop_owner')->user();
            
            if ($shopOwner) {
                $repairRequest = RepairRequest::where('id', $id)
                    ->where('shop_owner_id', $shopOwner->id)
                    ->whereIn('status', ['completed', 'in_progress'])
                    ->firstOrFail();

                if ((string) $repairRequest->status === 'in_progress') {
                    $materialGateResponse = $this->validateMaterialLoggingGateForTransition(
                        $repairRequest,
                        $noMaterialsUsedConfirmed,
                        'ready for pickup'
                    );
                    if ($materialGateResponse) {
                        return $materialGateResponse;
                    }

                    $completionGateResponse = $this->validateMaterialCompletionGateForTransition($repairRequest);
                    if ($completionGateResponse) {
                        return $completionGateResponse;
                    }
                }

                // Shop owner can mark any repair for their shop as ready
                DB::beginTransaction();
                
                $repairRequest->update([
                    'status' => 'ready_for_pickup',
                    'completed_at' => $repairRequest->completed_at ?? now(),
                    'pickup_instructions' => $request->pickup_instructions,
                ]);
                
                DB::commit();
                
                // TODO: Send notification to customer to pick up their item
                
                return response()->json([
                    'success' => true,
                    'message' => 'Marked as ready for pickup. Customer will be notified.',
                    'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
                ]);
            }
            
            // Otherwise check for regular user (repairer)
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }
            
            $repairRequest = RepairRequest::where('id', $id)
                ->where('assigned_repairer_id', $user->id)
                ->whereIn('status', ['completed', 'in_progress'])
                ->firstOrFail();

            if ((string) $repairRequest->status === 'in_progress') {
                $materialGateResponse = $this->validateMaterialLoggingGateForTransition(
                    $repairRequest,
                    $noMaterialsUsedConfirmed,
                    'ready for pickup'
                );
                if ($materialGateResponse) {
                    return $materialGateResponse;
                }
            }

            DB::beginTransaction();
            
            $repairRequest->update([
                'status' => 'ready_for_pickup',
                'completed_at' => $repairRequest->completed_at ?? now(),
                'pickup_instructions' => $request->pickup_instructions,
            ]);
            
            DB::commit();
            
            // TODO: Send notification to customer to pick up their item
            
            return response()->json([
                'success' => true,
                'message' => 'Marked as ready for pickup. Customer will be notified.',
                'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark as ready: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark shoes as received at shop (after pickup from customer's address)
     */
    public function markAsReceived(Request $request, $id)
    {
        try {
            // Check if authenticated as shop owner first
            $shopOwner = Auth::guard('shop_owner')->user();
            
            if ($shopOwner) {
                // Shop owner can mark any repair for their shop as received
                DB::beginTransaction();
                
                $debugRepair = RepairRequest::find($id);
                
                if (!$debugRepair) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Repair not found'
                    ], 404);
                }
                
                // Verify repair belongs to this shop owner
                if ($debugRepair->shop_owner_id != $shopOwner->id) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'This repair does not belong to your shop'
                    ], 403);
                }
                
                // Check if status is valid (allowing most statuses for flexibility/error correction)
                $invalidStatuses = ['cancelled', 'rejected', 'picked_up'];
                if (in_array($debugRepair->status, $invalidStatuses)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Repair cannot be marked as received in status: ' . $debugRepair->status
                    ], 400);
                }

                if (!$this->isPaymentSatisfiedForRepairProgress($debugRepair)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Payment must be paid before marking shoes as received.'
                    ], 422);
                }
                
                // Update status
                $debugRepair->update([
                    'status' => 'received',
                    'received_at' => now(),
                ]);
                
                DB::commit();
                
                return response()->json([
                    'success' => true,
                    'message' => 'Shoes marked as received. You can now begin the repair.',
                    'repair' => $debugRepair->fresh(['user', 'services', 'shopOwner'])
                ]);
            }
            
            // Otherwise check for regular user (repairer)
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            DB::beginTransaction();
            
            // Check what repair exists
            $debugRepair = RepairRequest::find($id);
            
            if (!$debugRepair) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Repair not found'
                ], 404);
            }
            
            // Check if assigned to current user
            if ($debugRepair->assigned_repairer_id != $user->id) {
                DB::rollBack();
                \Log::warning('Mark as received - Wrong repairer', [
                    'repair_id' => $id,
                    'current_user' => $user->id,
                    'assigned_to' => $debugRepair->assigned_repairer_id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'This repair is not assigned to you'
                ], 403);
            }
            
            // Check if status is valid
            $validStatuses = ['assigned_to_repairer', 'repairer_accepted', 'waiting_customer_confirmation', 'confirmed', 'owner_approval_pending', 'owner_approved', 'pending'];
            if (!in_array($debugRepair->status, $validStatuses)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Repair cannot be marked as received in status: ' . $debugRepair->status
                ], 400);
            }

            if (!$this->isPaymentSatisfiedForRepairProgress($debugRepair)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Payment must be paid before marking shoes as received.'
                ], 422);
            }
            
            // Update status
            $debugRepair->update([
                'status' => 'received',
                'received_at' => now(),
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Shoes marked as received. You can now begin the repair.',
                'repair' => $debugRepair->fresh(['user', 'services', 'shopOwner'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Mark as received failed - Full Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'repair_id' => $id
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark as received: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activate pickup confirmation for customer (Shop Owner or Repairer)
     */
    public function activatePickup(Request $request, $id)
    {
        try {
            $route = $request->route();
            $routeMiddleware = $route ? $route->gatherMiddleware() : [];
            $routeName = $route ? $route->getName() : null;

            $expectsShopOwnerGuard = in_array('auth:shop_owner', $routeMiddleware, true)
                || (is_string($routeName) && str_starts_with($routeName, 'shop_owner.'));
            $expectsUserGuard = in_array('auth:user', $routeMiddleware, true);

            // Prevent cross-session guard collisions (e.g., user logged in as both repairer and shop owner).
            $shopOwner = ($expectsShopOwnerGuard && !$expectsUserGuard)
                ? Auth::guard('shop_owner')->user()
                : null;
            
            if ($shopOwner) {
                // Shop owner can activate pickup for any repair for their shop
                DB::beginTransaction();
                
                $repairRequest = RepairRequest::find($id);
                
                if (!$repairRequest) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Repair not found'
                    ], 404);
                }
                
                // Verify repair belongs to this shop owner
                if ($repairRequest->shop_owner_id != $shopOwner->id) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'This repair does not belong to your shop'
                    ], 403);
                }
                
                $effectiveReturnMethod = $repairRequest->return_delivery_method
                    ?? (($repairRequest->delivery_method ?? null) === 'walk_in' ? 'walk_in' : 'customer_pickup');
                $isWalkInReturn = $effectiveReturnMethod === 'walk_in';
                $allowedStatuses = $isWalkInReturn
                    ? ['ready_for_pickup', 'ready-for-pickup']
                    : ['shipped'];

                // Walk-in: ready_for_pickup only. Delivery returns: shipped only.
                if (!in_array((string) $repairRequest->status, $allowedStatuses, true)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => $isWalkInReturn
                            ? 'Receive can only be activated when repair is ready for pickup.'
                            : 'Receive can only be activated after the repair is shipped.'
                    ], 400);
                }
                
                // Check if pickup is already enabled
                if ($repairRequest->pickup_enabled) {
                    DB::rollBack();
                    return response()->json([
                        'success' => true,
                        'message' => 'Pickup confirmation is already activated',
                        'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
                    ]);
                }

                if (!$this->isRepairFullyPaidForRelease($repairRequest)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => $this->getReleasePaymentRequiredMessage($repairRequest),
                    ], 422);
                }

                if ($isWalkInReturn) {
                    $repairRequest->update([
                        'status' => 'picked_up',
                        'picked_up_at' => now(),
                    ]);

                    try {
                        $this->autoGenerateInvoiceForPickedUpRepair($repairRequest);
                    } catch (\Throwable $invoiceError) {
                        \Log::warning('Failed to auto-generate invoice for in-shop picked-up repair (shop owner flow)', [
                            'repair_id' => $repairRequest->id,
                            'request_id' => $repairRequest->request_id,
                            'error' => $invoiceError->getMessage(),
                        ]);
                    }

                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'message' => 'Repair marked as received in-shop and completed.',
                        'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
                    ]);
                }
                
                // Enable pickup confirmation
                $repairRequest->update([
                    'pickup_enabled' => true,
                    'pickup_enabled_at' => now(),
                    'pickup_enabled_by' => $shopOwner->id,
                ]);
                
                DB::commit();
                
                return response()->json([
                    'success' => true,
                    'message' => 'Pickup confirmation activated. Customer can now confirm they received their item.',
                    'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
                ]);
            }
            
            // Otherwise check for regular user (repairer)
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            DB::beginTransaction();
            
            $repairRequest = RepairRequest::find($id);
            
            if (!$repairRequest) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Repair not found'
                ], 404);
            }
            
            // Verify repair is assigned to this repairer
            if ($repairRequest->assigned_repairer_id != $user->id) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'This repair is not assigned to you'
                ], 403);
            }
            
            $effectiveReturnMethod = $repairRequest->return_delivery_method
                ?? (($repairRequest->delivery_method ?? null) === 'walk_in' ? 'walk_in' : 'customer_pickup');
            $isWalkInReturn = $effectiveReturnMethod === 'walk_in';
            $allowedStatuses = $isWalkInReturn
                ? ['ready_for_pickup', 'ready-for-pickup']
                : ['shipped'];

            // Walk-in: ready_for_pickup only. Delivery returns: shipped only.
            if (!in_array((string) $repairRequest->status, $allowedStatuses, true)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => $isWalkInReturn
                        ? 'Receive can only be activated when repair is ready for pickup.'
                        : 'Receive can only be activated after the repair is shipped.'
                ], 400);
            }
            
            // Check if pickup is already enabled
            if ($repairRequest->pickup_enabled) {
                DB::rollBack();
                return response()->json([
                    'success' => true,
                    'message' => 'Pickup confirmation is already activated',
                    'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
                ]);
            }

            if (!$this->isRepairFullyPaidForRelease($repairRequest)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => $this->getReleasePaymentRequiredMessage($repairRequest),
                ], 422);
            }

            if ($isWalkInReturn) {
                $repairRequest->update([
                    'status' => 'picked_up',
                    'picked_up_at' => now(),
                ]);

                try {
                    $this->autoGenerateInvoiceForPickedUpRepair($repairRequest);
                } catch (\Throwable $invoiceError) {
                    \Log::warning('Failed to auto-generate invoice for in-shop picked-up repair', [
                        'repair_id' => $repairRequest->id,
                        'request_id' => $repairRequest->request_id,
                        'error' => $invoiceError->getMessage(),
                    ]);
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Repair marked as received in-shop and completed.',
                    'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
                ]);
            }
            
            // Enable pickup confirmation
            $repairRequest->update([
                'pickup_enabled' => true,
                'pickup_enabled_at' => now(),
                'pickup_enabled_by' => $user->id,
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Pickup confirmation activated. Customer can now confirm they received their item.',
                'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Activate pickup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'repair_id' => $id
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate pickup: ' . $e->getMessage()
            ], 500);
        }
    }

    private function isRepairFullyPaidForRelease(RepairRequest $repairRequest): bool
    {
        $paymentStatus = strtolower((string) ($repairRequest->payment_status ?? ''));
        $paymentPolicy = $repairRequest->payment_policy ?? 'deposit_50';

        return $paymentStatus === 'completed'
            || ($paymentPolicy !== 'deposit_50' && $paymentStatus === 'paid');
    }

    private function getReleasePaymentRequiredMessage(RepairRequest $repairRequest): string
    {
        return match ($repairRequest->payment_policy ?? 'deposit_50') {
            'deposit_50' => 'Customer must pay the remaining 50% balance before receive confirmation can be activated.',
            'full_upfront' => 'Customer payment must be completed before receive confirmation can be activated.',
            default => 'Customer payment must be completed before receive confirmation can be activated.',
        };
    }

    private function generateRepairInvoiceReference(): string
    {
        do {
            $reference = 'RINV-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid('', true), -4));
        } while (Invoice::where('reference', $reference)->exists());

        return $reference;
    }

    private function autoGenerateInvoiceForPickedUpRepair(RepairRequest $repair): ?Invoice
    {
        if (!$repair->shop_owner_id) {
            return null;
        }

        $repair->loadMissing(['services:id,name']);

        $existingInvoice = Invoice::where('shop_id', $repair->shop_owner_id)
            ->where('job_reference', (string) $repair->request_id)
            ->first();

        if ($existingInvoice) {
            return $existingInvoice;
        }

        $pricingSnapshot = $this->calculateRepairPricingSnapshot($repair);
        $finalTotal = (float) ($pricingSnapshot['final_total'] ?? 0);

        if ($finalTotal <= 0) {
            return null;
        }

        $paymentStatus = strtolower((string) ($repair->payment_status ?? 'pending'));
        $isSettled = in_array($paymentStatus, ['paid', 'completed'], true);

        $invoice = Invoice::create([
            'shop_id' => $repair->shop_owner_id,
            'reference' => $this->generateRepairInvoiceReference(),
            'customer_id' => $repair->user_id,
            'customer_name' => $repair->customer_name,
            'customer_email' => $repair->email,
            'date' => now(),
            'due_date' => $isSettled ? null : now()->addDays(7),
            'total' => $finalTotal,
            'tax_amount' => 0,
            'status' => $isSettled ? 'paid' : 'sent',
            'payment_date' => $isSettled ? ($repair->payment_completed_at ?? now()) : null,
            'payment_method' => 'repair_service',
            'job_reference' => (string) $repair->request_id,
            'notes' => 'Auto-generated from Repair Request #' . $repair->request_id,
            'meta' => [
                'source' => 'repair_request',
                'repair_request_id' => $repair->id,
                'repair_request_number' => $repair->request_id,
                'payment_status' => $repair->payment_status,
                'generated_on_status' => 'picked_up',
            ],
        ]);

        $serviceSummary = $repair->services->pluck('name')->filter()->values()->implode(', ');
        $description = 'Repair Service #' . $repair->request_id;
        if ($serviceSummary !== '') {
            $description .= ' - ' . $serviceSummary;
        }

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => $description,
            'quantity' => 1,
            'unit_price' => $finalTotal,
            'tax_rate' => 0,
            'amount' => $finalTotal,
            'account_id' => null,
        ]);

        activity()
            ->performedOn($repair)
            ->withProperties([
                'invoice_id' => $invoice->id,
                'invoice_reference' => $invoice->reference,
                'repair_request_id' => $repair->id,
                'repair_request_number' => $repair->request_id,
                'total' => $finalTotal,
            ])
            ->log('Auto-generated invoice for picked-up repair request');

        return $invoice;
    }

    private function calculateRepairPricingSnapshot(RepairRequest $repair): array
    {
        $repair->loadMissing(['materialUsages.inventoryItem:id,price']);

        $materialsTotal = round((float) $repair->materialUsages->sum(function ($usage) {
            $unitPrice = (float) ($usage->inventoryItem->price ?? 0);
            return ((int) $usage->quantity_used) * $unitPrice;
        }), 2);

        $packagePrice = round((float) ($repair->package_price ?? 0), 2);
        $addOnsTotal = round((float) ($repair->add_ons_total ?? 0), 2);
        $baseTotal = !is_null($repair->repair_package_id)
            ? round($packagePrice + $addOnsTotal, 2)
            : round((float) ($repair->total ?? 0), 2);
        // Material usage is operational tracking and should not auto-inflate
        // the customer-facing billable amount for the repair.
        $finalTotal = $baseTotal;

        $pricingBreakdown = is_array($repair->pricing_breakdown)
            ? $repair->pricing_breakdown
            : [];

        $pricingBreakdown['base_total'] = $baseTotal;
        $pricingBreakdown['materials_total'] = $materialsTotal;
        $pricingBreakdown['final_total'] = $finalTotal;

        return [
            'base_total' => $baseTotal,
            'materials_total' => $materialsTotal,
            'final_total' => $finalTotal,
            'pricing_breakdown' => $pricingBreakdown,
        ];
    }

    /**
     * Customer confirms repair (Phase 4)
     * Changes status from repairer_accepted to waiting_customer_confirmation
     * Note: For high-value repairs, owner_approval_pending is set AFTER payment (not here)
     * This allows payment to be enabled first before owner approval
     */
    public function confirmRepair(Request $request, $id)
    {
        try {
            $user = Auth::guard('web')->user() ?? Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            DB::beginTransaction();
            
            $repairRequest = RepairRequest::where('id', $id)
                ->where('user_id', $user->id)
                ->where('status', 'repairer_accepted')
                ->firstOrFail();
            
            // Always transition to waiting_customer_confirmation on confirmation
            // For high-value repairs, transition to owner_approval_pending AFTER payment is completed
            // This ensures payment can be enabled and processed before owner approval
            $newStatus = 'waiting_customer_confirmation';
            
            $repairRequest->update([
                'status' => $newStatus,
                'customer_confirmed_at' => now(),
            ]);
            
            DB::commit();
            
            // TODO: Send notification to repairer or shop owner
            
            return response()->json([
                'success' => true,
                'message' => 'Repair confirmed successfully. Proceed to payment.',
                'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm repair: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get rejection history with full timeline (Phase 5)
     * Accessible by shop owner and managers
     * 
     * @param Request $request - Accepts optional 'status' query param: 'Pending', 'Approved', 'Rejected', or 'All'
     */
    public function getRejectionHistory(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }
            
            // Get filter from request (optional)
            $statusFilter = $request->query('status', 'All');
            
            // Build base query
            $query = RepairRequest::with([
                'user', 
                'services', 
                'shopOwner', 
                'repairer',
                'repairerRejectedBy',
                'managerReviewedBy'
            ])
                ->where('shop_owner_id', $user->shop_owner_id)
                ->whereIn('status', [
                    'repairer_rejected', 
                    'manager_approved', 
                    'manager_rejected'
                ]);
            
            // Apply status filter if not 'All'
            if ($statusFilter !== 'All') {
                if ($statusFilter === 'Pending') {
                    $query->where('status', 'repairer_rejected');
                } elseif ($statusFilter === 'Approved') {
                    $query->where('status', 'manager_approved');
                } elseif ($statusFilter === 'Rejected') {
                    $query->where('status', 'manager_rejected');
                }
            }
            
            $rejections = $query
                ->orderBy('repairer_rejected_at', 'desc')
                ->get()
                ->map(function ($repair) {
                    // Build timeline history
                    $history = [];
                    
                    // Event 1: Rejection submitted
                    if ($repair->repairer_rejected_at) {
                        $history[] = [
                            'id' => 1,
                            'event' => 'submitted',
                            'description' => 'Rejection request submitted',
                            'changedBy' => $repair->repairerRejectedBy ? 
                                $repair->repairerRejectedBy->first_name . ' ' . $repair->repairerRejectedBy->last_name : 
                                'Unknown',
                            'changedAt' => $repair->repairer_rejected_at->format('Y-m-d h:i A'),
                            'status' => 'Submitted',
                            'notes' => $repair->repairer_rejection_reason
                        ];
                    }
                    
                    // Event 2: Manager review (if reviewed)
                    if ($repair->manager_reviewed_at && $repair->status !== 'repairer_rejected') {
                        $reviewStatus = 'Under Review';
                        $reviewDescription = 'Request received and under review';
                        
                        $history[] = [
                            'id' => 2,
                            'event' => 'reviewed',
                            'description' => $reviewDescription,
                            'changedBy' => $repair->managerReviewedBy ? 
                                $repair->managerReviewedBy->first_name . ' ' . $repair->managerReviewedBy->last_name : 
                                'Manager',
                            'changedAt' => $repair->manager_reviewed_at->format('Y-m-d h:i A'),
                            'status' => $reviewStatus,
                            'notes' => 'Manager reviewing rejection'
                        ];
                    }
                    
                    // Event 3: Final decision
                    if ($repair->status === 'manager_approved') {
                        $history[] = [
                            'id' => 3,
                            'event' => 'approved',
                            'description' => 'Rejection approved by Manager',
                            'changedBy' => $repair->managerReviewedBy ? 
                                $repair->managerReviewedBy->first_name . ' ' . $repair->managerReviewedBy->last_name : 
                                'Manager',
                            'changedAt' => $repair->manager_reviewed_at->format('Y-m-d h:i A'),
                            'status' => 'Approved',
                            'notes' => $repair->manager_review_notes ?? 'Rejection confirmed'
                        ];
                    } elseif ($repair->status === 'manager_rejected') {
                        $history[] = [
                            'id' => 3,
                            'event' => 'rejected',
                            'description' => 'Rejection request rejected (override)',
                            'changedBy' => $repair->managerReviewedBy ? 
                                $repair->managerReviewedBy->first_name . ' ' . $repair->managerReviewedBy->last_name : 
                                'Manager',
                            'changedAt' => $repair->manager_reviewed_at->format('Y-m-d h:i A'),
                            'status' => 'Rejected',
                            'notes' => $repair->manager_review_notes ?? 'Rejection overridden, repair reassigned'
                        ];
                    }
                    
                    return [
                        'id' => $repair->id,
                        'requestNumber' => $repair->order_number,
                        'serviceName' => $repair->services->pluck('name')->join(', '),
                        'category' => $repair->repair_type ?? 'Repair Service',
                        'customerName' => $repair->user->first_name . ' ' . $repair->user->last_name,
                        'orderedBy' => $repair->repairerRejectedBy ? 
                            'Repairer - ' . $repair->repairerRejectedBy->first_name . ' ' . $repair->repairerRejectedBy->last_name : 
                            'Repairer - Unknown',
                        'requestedOn' => $repair->repairer_rejected_at->format('Y-m-d'),
                        'reason' => $repair->repairer_rejection_reason ?? 'No reason provided',
                        'rejectionReason' => $repair->repairer_rejection_reason ?? 'No reason provided',
                        'status' => $repair->status === 'repairer_rejected' ? 'Pending' : 
                                   ($repair->status === 'manager_approved' ? 'Approved' : 'Rejected'),
                        'approvedBy' => $repair->status === 'manager_approved' && $repair->managerReviewedBy ? 
                            $repair->managerReviewedBy->first_name . ' ' . $repair->managerReviewedBy->last_name : 
                            null,
                        'approvedAt' => $repair->status === 'manager_approved' ? 
                            $repair->manager_reviewed_at->format('Y-m-d') : 
                            null,
                        'rejectedBy' => $repair->status === 'manager_rejected' && $repair->managerReviewedBy ? 
                            $repair->managerReviewedBy->first_name . ' ' . $repair->managerReviewedBy->last_name : 
                            null,
                        'rejectedAt' => $repair->status === 'manager_rejected' ? 
                            $repair->manager_reviewed_at->format('Y-m-d') : 
                            null,
                        'decisionReason' => $repair->manager_review_notes,
                        'media' => $repair->repair_images ?? [],
                        'history' => $history
                    ];
                });
            
            return response()->json([
                'success' => true,
                'rejections' => $rejections,
                'total' => $rejections->count()
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch rejection history: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Activate payment for a specific repair request (Phase 9 - Unified Chat Solution)
     */
    public function activatePaymentForRepair(Request $request, $id)
    {
        try {
            // Check if authenticated as shop owner first
            $shopOwner = Auth::guard('shop_owner')->user();
            $user = Auth::guard('user')->user();
            
            if (!$shopOwner && !$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }
            
            // Get the repair request
            if ($shopOwner) {
                $repairRequest = RepairRequest::where('id', $id)
                    ->where('shop_owner_id', $shopOwner->id)
                    ->firstOrFail();
            } else {
                // For repairer, verify they're assigned to this repair
                $repairRequest = RepairRequest::where('id', $id)
                    ->where(function($query) use ($user) {
                        $query->where('assigned_repairer_id', $user->id)
                              ->orWhere('shop_owner_id', $user->shop_owner_id);
                    })
                    ->firstOrFail();
            }
            
            // Check if payment is already enabled
            if ($repairRequest->payment_enabled) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment is already enabled for this repair',
                    'repair' => $repairRequest
                ]);
            }
            
            // Verify repair is in a state where payment can be activated.
            // Standard: before/while work starts.
            // Special case: deposit_50 remaining balance at ready_for_pickup for non-walk-in returns.
            $validStatuses = ['repairer_accepted', 'received', 'pending', 'in_progress'];
            $requestId = strtoupper((string) ($repairRequest->request_id ?? ''));
            $pricingMode = strtolower((string) data_get($repairRequest->pricing_breakdown, 'mode', ''));
            $isManualPosRepair = str_starts_with($requestId, 'REP-POS-') || $pricingMode === 'manual_pos';
            $effectiveIntakeMethod = $repairRequest->intake_delivery_method
                ?? (($repairRequest->delivery_method ?? null) === 'walk_in' ? 'walk_in' : 'customer_delivery');
            $isWalkInIntake = $effectiveIntakeMethod === 'walk_in';
            $effectiveReturnMethod = $repairRequest->return_delivery_method
                ?? (($repairRequest->delivery_method ?? null) === 'walk_in' ? 'walk_in' : 'customer_pickup');
            $isWalkInReturn = $effectiveReturnMethod === 'walk_in';
            $paymentPolicy = (string) ($repairRequest->payment_policy ?? 'deposit_50');
            $paymentStatus = strtolower((string) ($repairRequest->payment_status ?? 'pending'));

            if ($isManualPosRepair && $isWalkInIntake) {
                return response()->json([
                    'success' => false,
                    'code' => 'POS_WALKIN_ACTIVATION_NOT_REQUIRED',
                    'message' => 'Manual POS walk-in repairs do not require payment activation. Use Proceed to POS when payment is due.',
                ], 409);
            }

            $isRemainingBalanceActivation =
                !$isWalkInReturn
                && in_array((string) $repairRequest->status, ['ready_for_pickup', 'ready-for-pickup'], true)
                && $paymentPolicy === 'deposit_50'
                && in_array($paymentStatus, ['paid', 'partially_paid'], true);

            if (!in_array((string) $repairRequest->status, $validStatuses, true) && !$isRemainingBalanceActivation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment can only be activated for accepted/received/pending/in-progress repairs, or for deposit 50/50 remaining balance at ready for pickup (courier return).'
                ], 400);
            }
            
            // Enable payment
            $repairRequest->update([
                'payment_enabled' => true,
                'payment_enabled_at' => now(),
                'payment_enabled_by' => $shopOwner ? $shopOwner->id : $user->id,
            ]);
            
            \Log::info('Payment activated for repair request', [
                'user_id' => $shopOwner ? $shopOwner->id : $user->id,
                'user_type' => $shopOwner ? 'shop_owner' : 'repairer',
                'repair_request_id' => $repairRequest->id,
            ]);
            
            // Send notification to customer
            // TODO: Implement notification logic
            
            return response()->json([
                'success' => true,
                'message' => 'Payment has been activated successfully. Customer can now pay for this repair.',
                'repair' => $repairRequest->fresh(['user', 'services', 'shopOwner'])
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark repair payment as paid in-shop (cash/manual).
     */
    public function markPaidInShop(Request $request, $id)
    {
        return response()->json([
            'success' => false,
            'code' => 'POS_REQUIRED',
            'message' => 'Direct manual payment is disabled. Use Proceed to POS for all repair payments.',
        ], 409);
    }

    /**
     * Mark a repair as shipped and record carrier/tracking details.
     */
    public function shipRepair(Request $request, $id)
    {
        try {
            $shopOwner = Auth::guard('shop_owner')->user();
            $user      = Auth::guard('user')->user();

            if (!$shopOwner && !$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            $validated = $request->validate([
                'tracking_number' => ['required', 'string', 'max:100'],
                'carrier_company' => ['required', 'string', 'max:100'],
                'carrier_name'    => ['required', 'string', 'max:100'],
                'carrier_phone'   => ['required', 'string', 'max:30'],
                'tracking_link'   => ['nullable', 'string', 'max:500'],
                'estimated_delivery_date' => ['required', 'date'],
            ]);

            $query = RepairRequest::where('id', $id);
            if ($shopOwner) {
                $query->where('shop_owner_id', $shopOwner->id);
            } else {
                $query->where('assigned_repairer_id', $user->id);
            }

            $repairRequest = $query->whereIn('status', ['ready_for_pickup', 'ready-for-pickup'])
                ->firstOrFail();

            $effectiveReturnMethod = $repairRequest->return_delivery_method
                ?? (($repairRequest->delivery_method ?? null) === 'walk_in' ? 'walk_in' : 'customer_pickup');

            if ($effectiveReturnMethod === 'walk_in') {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer pick-up orders cannot be marked as shipped. Use the receive/pickup confirmation flow instead.',
                ], 422);
            }

            $returnAddress = is_array($repairRequest->return_address) ? $repairRequest->return_address : null;
            $hasReturnAddress = $returnAddress
                && !empty($returnAddress['address_line'])
                && !empty($returnAddress['barangay'])
                && !empty($returnAddress['city'])
                && !empty($returnAddress['region'])
                && !empty($returnAddress['postal_code']);

            if (!$hasReturnAddress) {
                $defaultAddress = $repairRequest->user?->defaultAddress()->first();

                if ($defaultAddress) {
                    $repairRequest->update([
                        'return_address' => [
                            'address_line' => $defaultAddress->address_line,
                            'barangay' => $defaultAddress->barangay,
                            'city' => $defaultAddress->city,
                            'region' => $defaultAddress->region,
                            'postal_code' => $defaultAddress->postal_code,
                        ],
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot ship because customer return address is missing. Ask the customer to set a delivery address first.',
                    ], 422);
                }
            }

            if (!$this->isRepairFullyPaidForRelease($repairRequest)) {
                return response()->json([
                    'success' => false,
                    'message' => $this->getReleasePaymentRequiredMessage($repairRequest),
                ], 422);
            }

            $repairRequest->update(array_merge($validated, [
                'status'    => 'shipped',
                'shipped_at' => now(),
                'pickup_enabled' => false,
                'pickup_enabled_at' => null,
                'pickup_enabled_by' => null,
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Repair marked as shipped.',
                'repair'  => $repairRequest->fresh(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to ship: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Change the delivery method of a repair request (before shoes are received).
     */
    public function changeDeliveryMethod(Request $request, $id)
    {
        try {
            $user      = Auth::guard('user')->user();
            $shopOwner = Auth::guard('shop_owner')->user();

            if (!$user && !$shopOwner) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            $validated = $request->validate([
                'delivery_method' => ['required', 'in:walk_in,pickup'],
            ]);

            $query = RepairRequest::where('id', $id);

            if ($shopOwner) {
                $query->where('shop_owner_id', $shopOwner->id);
            } else {
                $query->where('assigned_repairer_id', $user->id);
            }

            // Only allow changes before the shoes are physically received
            $repairRequest = $query->whereNotIn('status', [
                'in_progress', 'awaiting_parts', 'completed', 'ready_for_pickup',
                'ready-for-pickup', 'picked_up', 'cancelled', 'rejected',
            ])->firstOrFail();

            $updatePayload = [
                'delivery_method' => $validated['delivery_method'],
                'intake_delivery_method' => $validated['delivery_method'] === 'walk_in' ? 'walk_in' : 'customer_delivery',
                'intake_address' => $validated['delivery_method'] === 'walk_in'
                    ? null
                    : ($repairRequest->intake_address ?? $repairRequest->pickup_address),
                'pickup_address' => $validated['delivery_method'] === 'walk_in' ? null : $repairRequest->pickup_address,
            ];

            // Auto-enable online payment for walk-in intake
            if ($validated['delivery_method'] === 'walk_in' && !$repairRequest->payment_enabled) {
                $updatePayload['payment_enabled'] = true;
                $updatePayload['payment_enabled_at'] = now();
                $updatePayload['payment_enabled_by'] = $shopOwner?->id ?? $user?->id;
            }

            $repairRequest->update($updatePayload);

            return response()->json([
                'success'         => true,
                'delivery_method' => $repairRequest->delivery_method,
                'intake_delivery_method' => $repairRequest->intake_delivery_method,
                'payment_enabled' => (bool) $repairRequest->payment_enabled,
                'message'         => 'Delivery method updated successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update delivery method: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get repair materials stock overview for repairer pages.
     */
    public function repairStocksOverview(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            $shopOwner = Auth::guard('shop_owner')->user();
            $shopOwnerId = $user?->shop_owner_id ?? $shopOwner?->id;

            if (!$shopOwnerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $categoryFilter = $request->filled('category') ? (string) $request->category : 'repair_materials';

            if ($categoryFilter !== 'repair_materials') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only repair materials are available in this view.',
                ], 422);
            }

            $query = InventoryItem::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true);

            if ($request->filled('search')) {
                $search = trim((string) $request->search);
                $query->where(function ($innerQuery) use ($search) {
                    $innerQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                });
            }

            $query->where('category', $categoryFilter);

            if ($request->filled('status')) {
                $status = (string) $request->status;
                if ($status === 'In Stock') {
                    $query->whereRaw('available_quantity > reorder_level');
                } elseif ($status === 'Low Stock') {
                    $query->whereRaw('available_quantity > 0 AND available_quantity <= reorder_level');
                } elseif ($status === 'Out of Stock') {
                    $query->where('available_quantity', '<=', 0);
                }
            }

            $items = $query
                ->with(['images' => function ($imageQuery) {
                    $imageQuery->select('id', 'inventory_item_id', 'image_path', 'is_thumbnail');
                }])
                ->orderBy('name')
                ->get();

            $baseQuery = InventoryItem::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true);

            $baseQuery->where('category', $categoryFilter);

            $metrics = [
                'total_items' => (clone $baseQuery)->sum('available_quantity'),
                'low_stock_count' => (clone $baseQuery)->whereRaw('available_quantity > 0 AND available_quantity <= reorder_level')->count(),
                'out_of_stock_count' => (clone $baseQuery)->where('available_quantity', '<=', 0)->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $items,
                'metrics' => $metrics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch repair stocks overview: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List material requests created by authenticated repairer.
     */
    public function myMaterialRequests(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $query = StockRequestApproval::query()
                ->with(['inventoryItem', 'repairRequest'])
                ->where('shop_owner_id', $user->shop_owner_id)
                ->where('requested_by', $user->id)
                ->where('request_source', 'repair')
                ->orderBy('requested_date', 'desc');

            if ($request->filled('repair_request_id')) {
                $query->where('repair_request_id', (int) $request->repair_request_id);
            }

            if ($request->filled('status')) {
                $query->where('status', (string) $request->status);
            }

            $requests = $query->get();

            return response()->json([
                'success' => true,
                'data' => $requests,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch material requests: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create material request for procurement review.
     */
    public function createMaterialRequest(Request $request)
    {
        $validated = $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'quantity_needed' => 'required|integer|min:1',
            'priority' => 'required|in:high,medium,low',
            'requested_size' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:1000',
            'repair_request_id' => 'nullable|exists:repair_requests,id',
        ]);

        try {
            $user = Auth::guard('user')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $inventoryItem = InventoryItem::query()
                ->where('id', $validated['inventory_item_id'])
                ->where('shop_owner_id', $user->shop_owner_id)
                ->first();

            if (!$inventoryItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected inventory item is invalid for your shop.',
                ], 422);
            }

            if ((string) $inventoryItem->category !== 'repair_materials') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only repair materials can be requested from this endpoint.',
                ], 422);
            }

            $repairRequestId = $validated['repair_request_id'] ?? null;
            if ($repairRequestId) {
                $repairRequest = RepairRequest::query()
                    ->where('id', $repairRequestId)
                    ->where('shop_owner_id', $user->shop_owner_id)
                    ->first();

                if (!$repairRequest) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected repair request is invalid for your shop.',
                    ], 422);
                }

                $isAssignedRepair = (int) $repairRequest->assigned_repairer_id === (int) $user->id;
                $isManager = method_exists($user, 'hasRole') ? $user->hasRole('Manager') : false;

                if (!$isAssignedRepair && !$isManager) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You can only create repair-linked requests for your assigned repairs.',
                    ], 403);
                }

                if (in_array((string) $repairRequest->status, ['completed', 'ready_for_pickup', 'picked_up', 'shipped', 'cancelled', 'rejected'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot create a repair-linked material request for a closed repair.',
                    ], 422);
                }
            }

            $stockRequest = StockRequestApproval::create([
                'request_number' => $this->generateStockRequestNumber(),
                'shop_owner_id' => $user->shop_owner_id,
                'inventory_item_id' => $inventoryItem->id,
                'repair_request_id' => $repairRequestId,
                'product_name' => $inventoryItem->name,
                'sku_code' => $inventoryItem->sku ?? '',
                'quantity_needed' => $validated['quantity_needed'],
                'requested_size' => $validated['requested_size'] ?? null,
                'priority' => $validated['priority'],
                'request_source' => 'repair',
                'status' => 'pending',
                'requested_by' => $user->id,
                'requested_date' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Material request submitted successfully.',
                'data' => $stockRequest->load(['inventoryItem', 'repairRequest', 'requester']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create material request: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create multiple material requests in bulk (cart-style submission)
     */
    public function createBulkMaterialRequests(Request $request)
    {
        $validated = $request->validate([
            'materials' => 'required|array|min:1|max:20',
            'materials.*.inventory_item_id' => 'required|integer|exists:inventory_items,id',
            'materials.*.quantity_needed' => 'required|integer|min:1',
            'materials.*.priority' => 'required|in:high,medium,low',
            'materials.*.requested_size' => 'nullable|string|max:20',
            'materials.*.notes' => 'nullable|string|max:500',
            'repair_request_id' => 'nullable|exists:repair_requests,id',
            'batch_notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();
            
            $user = Auth::guard('user')->user();

            if (!$user) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $repairRequestId = $validated['repair_request_id'] ?? null;
            if ($repairRequestId) {
                $repairRequest = RepairRequest::query()
                    ->where('id', $repairRequestId)
                    ->where('shop_owner_id', $user->shop_owner_id)
                    ->first();

                if (!$repairRequest) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected repair request is invalid for your shop.',
                    ], 422);
                }

                $isAssignedRepair = (int) $repairRequest->assigned_repairer_id === (int) $user->id;
                $isManager = method_exists($user, 'hasRole') ? $user->hasRole('Manager') : false;

                if (!$isAssignedRepair && !$isManager) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'You can only create repair-linked requests for your assigned repairs.',
                    ], 403);
                }

                if (in_array((string) $repairRequest->status, ['completed', 'ready_for_pickup', 'picked_up', 'shipped', 'cancelled', 'rejected'])) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot create repair-linked material requests for a closed repair.',
                    ], 422);
                }
            }

            $createdRequests = [];
            $errors = [];

            foreach ($validated['materials'] as $index => $materialData) {
                try {
                    $inventoryItem = InventoryItem::query()
                        ->where('id', $materialData['inventory_item_id'])
                        ->where('shop_owner_id', $user->shop_owner_id)
                        ->first();

                    if (!$inventoryItem) {
                        $errors[] = [
                            'index' => $index,
                            'message' => 'Selected inventory item is invalid for your shop.',
                        ];
                        continue;
                    }

                    if ((string) $inventoryItem->category !== 'repair_materials') {
                        $errors[] = [
                            'index' => $index,
                            'message' => 'Only repair materials can be requested.',
                        ];
                        continue;
                    }

                    $stockRequest = StockRequestApproval::create([
                        'request_number' => $this->generateStockRequestNumber(),
                        'shop_owner_id' => $user->shop_owner_id,
                        'inventory_item_id' => $inventoryItem->id,
                        'repair_request_id' => $repairRequestId,
                        'product_name' => $inventoryItem->name,
                        'sku_code' => $inventoryItem->sku ?? '',
                        'quantity_needed' => $materialData['quantity_needed'],
                        'requested_size' => $materialData['requested_size'] ?? null,
                        'priority' => $materialData['priority'],
                        'request_source' => 'repair',
                        'status' => 'pending',
                        'requested_by' => $user->id,
                        'requested_date' => now(),
                        'notes' => $materialData['notes'] ?? null,
                    ]);

                    $createdRequests[] = $stockRequest->load(['inventoryItem', 'repairRequest', 'requester']);
                } catch (\Exception $e) {
                    $errors[] = [
                        'index' => $index,
                        'message' => 'Failed to create request: ' . $e->getMessage(),
                    ];
                }
            }

            if (empty($createdRequests)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'No material requests were created successfully.',
                    'errors' => $errors,
                ], 422);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($createdRequests) . ' material request(s) submitted successfully.',
                'data' => [
                    'created' => $createdRequests,
                    'failed' => $errors,
                    'total_created' => count($createdRequests),
                    'total_failed' => count($errors),
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create bulk material requests: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get material usage history for a repair request and stock options.
     */
    public function getRepairMaterialUsage($id)
    {
        try {
            $actorContext = $this->resolveRepairMaterialActorContext();
            $shopOwnerId = (int) $actorContext['shop_owner_id'];
            $actorUserId = (int) $actorContext['actor_user_id'];
            $enforceAssignment = (bool) $actorContext['enforce_assignment'];
            $canManage = (bool) $actorContext['can_manage'];

            if ($shopOwnerId <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $repairRequest = RepairRequest::query()
                ->where('id', $id)
                ->where('shop_owner_id', $shopOwnerId)
                ->with([
                    'materialUsages' => function ($usageQuery) {
                        $usageQuery->with(['inventoryItem', 'user'])
                            ->orderBy('used_at', 'desc');
                    }
                ])
                ->firstOrFail();

            $this->ensureRepairMaterialPlanItems($repairRequest);

            if ($enforceAssignment && (int) $repairRequest->assigned_repairer_id !== $actorUserId && !$canManage) {
                return response()->json([
                    'success' => false,
                    'message' => 'This repair is not assigned to you.'
                ], 403);
            }

            $materials = InventoryItem::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true)
                ->where('category', 'repair_materials')
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'category', 'available_quantity', 'unit', 'reorder_level', 'price']);

            $pricingTotals = $this->computeRepairPricingTotals($repairRequest);

            $usageEntries = $repairRequest->materialUsages->map(function ($usage) {
                $unitPrice = round((float) ($usage->inventoryItem->price ?? 0), 2);
                $lineTotal = round(((int) $usage->quantity_used) * $unitPrice, 2);

                return array_merge($usage->toArray(), [
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ]);
            })->values();

            $planItems = RepairMaterialPlanItem::query()
                ->where('repair_request_id', $repairRequest->id)
                ->with(['inventoryItem:id,name,sku,available_quantity,unit'])
                ->orderByDesc('is_critical')
                ->orderBy('inventory_item_id')
                ->get()
                ->map(function (RepairMaterialPlanItem $planItem) {
                    $planned = round((float) $planItem->planned_quantity, 2);
                    $actual = round((float) $planItem->actual_quantity, 2);

                    return [
                        'id' => (int) $planItem->id,
                        'repair_request_id' => (int) $planItem->repair_request_id,
                        'inventory_item_id' => (int) $planItem->inventory_item_id,
                        'planned_quantity' => $planned,
                        'actual_quantity' => $actual,
                        'remaining_quantity' => round(max($planned - $actual, 0), 2),
                        'is_critical' => (bool) $planItem->is_critical,
                        'tolerance_percent' => round((float) $planItem->tolerance_percent, 2),
                        'variance_status' => (string) $planItem->variance_status,
                        'variance_note' => $planItem->variance_note,
                        'inventory_item' => $planItem->inventoryItem,
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'repair_id' => $repairRequest->id,
                    'repair_status' => $repairRequest->status,
                    'usages' => $usageEntries,
                    'plan_items' => $planItems,
                    'materials' => $materials,
                    'summary' => $pricingTotals,
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Repair request not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch repair material usage: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Log material usage for a repair request and deduct stock.
     */
    public function logRepairMaterialUsage(Request $request, $id)
    {
        $validated = $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'quantity_used' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $actorContext = $this->resolveRepairMaterialActorContext();
            $shopOwnerId = (int) $actorContext['shop_owner_id'];
            $actorUserId = (int) $actorContext['actor_user_id'];
            $enforceAssignment = (bool) $actorContext['enforce_assignment'];

            if ($shopOwnerId <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $repairRequest = RepairRequest::query()
                ->where('id', $id)
                ->where('shop_owner_id', $shopOwnerId)
                ->firstOrFail();

            $this->ensureRepairMaterialPlanItems($repairRequest);

            if ($enforceAssignment && (int) $repairRequest->assigned_repairer_id !== $actorUserId) {
                return response()->json([
                    'success' => false,
                    'message' => 'This repair is not assigned to you.'
                ], 403);
            }

            if (!in_array((string) $repairRequest->status, ['in_progress', 'awaiting_parts'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Material usage can only be logged while repair is in progress or awaiting parts.',
                ], 422);
            }

            $inventoryItem = InventoryItem::query()
                ->where('id', $validated['inventory_item_id'])
                ->where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true)
                ->first();

            if (!$inventoryItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected material is invalid for your shop.',
                ], 422);
            }

            if ((string) $inventoryItem->category !== 'repair_materials') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only repair materials can be logged for repair usage.',
                ], 422);
            }

            $quantityUsed = (int) $validated['quantity_used'];
            $usageNotes = trim((string) ($validated['notes'] ?? ''));

            if ($quantityUsed === 0 && $usageNotes === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Add a note when logging zero quantity so inventory can track carry-over usage context.',
                ], 422);
            }

            if ($quantityUsed > 0 && (int) $inventoryItem->available_quantity <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected material is out of stock. Please request replenishment first.',
                    'available_quantity' => 0,
                ], 422);
            }

            if ($quantityUsed > 0 && (int) $inventoryItem->available_quantity < $quantityUsed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient stock for selected material.',
                    'available_quantity' => (int) $inventoryItem->available_quantity,
                ], 422);
            }

            $dedupeFingerprint = hash('sha256', implode('|', [
                (string) $shopOwnerId,
                (string) $repairRequest->id,
                (string) $inventoryItem->id,
                (string) $quantityUsed,
                strtolower($usageNotes),
                (string) $actorUserId,
            ]));

            $dedupeKey = "repair_material_usage_log:{$shopOwnerId}:{$repairRequest->id}:{$dedupeFingerprint}";
            $dedupeTtlSeconds = 6;

            if (!Cache::add($dedupeKey, '1', now()->addSeconds($dedupeTtlSeconds))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate material log detected. Please wait a few seconds before retrying.',
                ], 429);
            }

            $usage = null;
            $remainingQuantity = (int) $inventoryItem->available_quantity;
            $reorderLevel = max(0, (int) ($inventoryItem->reorder_level ?? 0));
            $stockStatus = 'ok';
            $warnings = [];
            $autoReorderMeta = ['triggered' => false];
            $shouldDispatchLowStockAlert = false;
            $autoReorderNotificationPayload = null;
            $pricingTotals = null;

            DB::transaction(function () use (
                &$usage,
                &$remainingQuantity,
                &$reorderLevel,
                &$stockStatus,
                &$warnings,
                &$autoReorderMeta,
                &$shouldDispatchLowStockAlert,
                &$autoReorderNotificationPayload,
                &$pricingTotals,
                $inventoryItem,
                $quantityUsed,
                $usageNotes,
                $repairRequest,
                $shopOwnerId,
                $actorUserId
            ) {
                $movementNote = $usageNotes !== ''
                    ? $usageNotes
                    : "Material used for repair {$repairRequest->request_id}";

                if ($quantityUsed > 0) {
                    $movement = $inventoryItem->decrementStock(
                        $quantityUsed,
                        'repair_usage',
                        $movementNote,
                        $actorUserId > 0 ? $actorUserId : null
                    );

                    $movement->update([
                        'reference_type' => 'repair_request',
                        'reference_id' => $repairRequest->id,
                    ]);
                } else {
                    $quantityBefore = (int) $inventoryItem->available_quantity;
                    $movement = $inventoryItem->stockMovements()->create([
                        'movement_type' => 'repair_usage',
                        'quantity_change' => 0,
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $quantityBefore,
                        'reference_type' => 'repair_request',
                        'reference_id' => $repairRequest->id,
                        'notes' => $movementNote,
                        'performed_by' => $actorUserId > 0 ? $actorUserId : null,
                        'performed_at' => now(),
                    ]);
                }

                $usage = RepairMaterialUsage::create([
                    'repair_request_id' => $repairRequest->id,
                    'inventory_item_id' => $inventoryItem->id,
                    'quantity_used' => $quantityUsed,
                    'notes' => $usageNotes !== '' ? $usageNotes : null,
                    'used_by' => $actorUserId > 0 ? $actorUserId : null,
                    'used_at' => now(),
                    'stock_movement_id' => $movement->id,
                ]);

                $planItem = RepairMaterialPlanItem::query()
                    ->where('repair_request_id', $repairRequest->id)
                    ->where('inventory_item_id', $inventoryItem->id)
                    ->lockForUpdate()
                    ->first();

                if ($planItem) {
                    $planItem->actual_quantity = round((float) $planItem->actual_quantity + (float) $quantityUsed, 2);
                    $planItem->save();
                }

                $remainingQuantity = (int) $inventoryItem->available_quantity;
                $reorderLevel = max(0, (int) ($inventoryItem->reorder_level ?? 0));

                if ($quantityUsed > 0) {
                    if ($remainingQuantity <= 0) {
                        $stockStatus = 'out_of_stock';
                        $warnings[] = "{$inventoryItem->name} is now out of stock.";
                        $shouldDispatchLowStockAlert = true;
                    } elseif ($reorderLevel > 0 && $remainingQuantity <= $reorderLevel) {
                        $stockStatus = 'low_stock';
                        $warnings[] = "{$inventoryItem->name} is low on stock ({$remainingQuantity} left, reorder level {$reorderLevel}).";
                        $shouldDispatchLowStockAlert = true;
                    }
                }

                if ($quantityUsed > 0 && $reorderLevel > 0 && $remainingQuantity <= $reorderLevel) {
                    $existingPendingRepairRequest = StockRequestApproval::query()
                        ->where('shop_owner_id', $shopOwnerId)
                        ->where('inventory_item_id', $inventoryItem->id)
                        ->where('request_source', 'repair')
                        ->whereIn('status', ['pending', 'needs_details'])
                        ->orderByDesc('requested_date')
                        ->first();

                    if ($existingPendingRepairRequest) {
                        $autoReorderMeta = [
                            'triggered' => false,
                            'existing_request_number' => $existingPendingRepairRequest->request_number,
                        ];
                    } else {
                        $autoQuantity = max(1, (int) ($inventoryItem->reorder_quantity ?: $inventoryItem->reorder_level ?: 1));
                        $autoPriority = $remainingQuantity <= 0 ? 'high' : 'medium';

                        $requesterId = $actorUserId > 0
                            ? $actorUserId
                            : $this->resolveRepairMaterialFallbackRequesterId($shopOwnerId);

                        if ($requesterId <= 0) {
                            $autoReorderMeta = [
                                'triggered' => false,
                                'skipped_reason' => 'missing_requester',
                            ];
                            $warnings[] = 'Auto-reorder was skipped because no active staff requester is available.';
                        } else {
                            $autoRequest = StockRequestApproval::create([
                                'request_number' => $this->generateStockRequestNumber(),
                                'shop_owner_id' => $shopOwnerId,
                                'inventory_item_id' => $inventoryItem->id,
                                'repair_request_id' => $repairRequest->id,
                                'product_name' => $inventoryItem->name,
                                'sku_code' => $inventoryItem->sku ?? '',
                                'quantity_needed' => $autoQuantity,
                                'requested_size' => null,
                                'priority' => $autoPriority,
                                'request_source' => 'repair',
                                'status' => 'pending',
                                'requested_by' => $requesterId,
                                'requested_date' => now(),
                                'notes' => "[AUTO-REORDER] Triggered after repair usage for {$repairRequest->request_id}. Remaining stock: {$remainingQuantity}. Reorder level: {$reorderLevel}.",
                            ]);

                            $autoReorderMeta = [
                                'triggered' => true,
                                'request_id' => (int) $autoRequest->id,
                                'request_number' => $autoRequest->request_number,
                                'quantity_needed' => (int) $autoRequest->quantity_needed,
                            ];

                            $autoReorderNotificationPayload = [
                                'request_id' => (int) $autoRequest->id,
                                'request_number' => $autoRequest->request_number,
                                'product_name' => $inventoryItem->name,
                                'sku_code' => $inventoryItem->sku ?? '',
                                'quantity_needed' => (int) $autoRequest->quantity_needed,
                                'remaining_quantity' => (int) $remainingQuantity,
                                'reorder_level' => (int) $reorderLevel,
                                'priority' => $autoPriority,
                                'repair_request_id' => (int) $repairRequest->id,
                                'repair_request_number' => (string) ($repairRequest->request_id ?? $repairRequest->id),
                                'triggered_by_user_id' => $requesterId,
                            ];

                            $warnings[] = "Auto-reorder request {$autoRequest->request_number} was created.";
                        }
                    }
                }

                $pricingTotals = $this->syncRepairPricingTotals($repairRequest);
            });

            if ($shouldDispatchLowStockAlert) {
                try {
                    $inventoryItem->refresh();
                    event(new LowStockAlert(
                        $inventoryItem,
                        max(0, $remainingQuantity),
                        $reorderLevel
                    ));
                } catch (\Throwable $notificationError) {
                    \Log::warning('Failed to dispatch LowStockAlert event after repair usage log.', [
                        'inventory_item_id' => $inventoryItem->id,
                        'error' => $notificationError->getMessage(),
                    ]);
                }
            }

            if (is_array($autoReorderNotificationPayload)) {
                try {
                    $this->notificationService->notifyProcurementAutoReorderTriggered(
                        $shopOwnerId,
                        $autoReorderNotificationPayload
                    );
                } catch (\Throwable $notificationError) {
                    \Log::warning('Failed to send procurement auto-reorder notification.', [
                        'inventory_item_id' => $inventoryItem->id,
                        'request_number' => $autoReorderNotificationPayload['request_number'] ?? null,
                        'error' => $notificationError->getMessage(),
                    ]);
                }
            }

            $message = $quantityUsed === 0
                ? 'Material usage note logged successfully. Stock was not deducted.'
                : 'Material usage logged successfully.';

            if (!empty($warnings)) {
                $message .= ' ' . implode(' ', array_values(array_unique($warnings)));
            }

            $usagePayload = $usage ? $usage->load(['inventoryItem', 'user']) : null;

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $usagePayload,
                'meta' => [
                    'stock_status' => $stockStatus,
                    'remaining_quantity' => $remainingQuantity,
                    'reorder_level' => $reorderLevel,
                    'warnings' => array_values(array_unique($warnings)),
                    'auto_reorder' => $autoReorderMeta,
                    'pricing' => $pricingTotals,
                ],
            ], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Repair request not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to log material usage: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove a logged material usage and restore stock.
     */
    public function removeRepairMaterialUsage($id, $usageId)
    {
        try {
            $actorContext = $this->resolveRepairMaterialActorContext();
            $shopOwnerId = (int) $actorContext['shop_owner_id'];
            $actorUserId = (int) $actorContext['actor_user_id'];
            $enforceAssignment = (bool) $actorContext['enforce_assignment'];
            $canManage = (bool) $actorContext['can_manage'];

            if ($shopOwnerId <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $repairRequest = RepairRequest::query()
                ->where('id', $id)
                ->where('shop_owner_id', $shopOwnerId)
                ->firstOrFail();

            if ($enforceAssignment && (int) $repairRequest->assigned_repairer_id !== $actorUserId && !$canManage) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to remove material usage for this repair.'
                ], 403);
            }

            $usage = RepairMaterialUsage::query()
                ->with('inventoryItem')
                ->where('id', $usageId)
                ->where('repair_request_id', $repairRequest->id)
                ->firstOrFail();

            $pricingTotals = null;

            DB::transaction(function () use ($usage, $repairRequest, $actorUserId, &$pricingTotals) {
                if ($usage->inventoryItem) {
                    $restoreMovement = $usage->inventoryItem->incrementStock(
                        (int) $usage->quantity_used,
                        'return',
                        "Material usage reversal for repair {$repairRequest->request_id}",
                        $actorUserId > 0 ? $actorUserId : null
                    );

                    $restoreMovement->update([
                        'reference_type' => 'repair_request',
                        'reference_id' => $repairRequest->id,
                    ]);
                }

                $planItem = RepairMaterialPlanItem::query()
                    ->where('repair_request_id', $repairRequest->id)
                    ->where('inventory_item_id', $usage->inventory_item_id)
                    ->lockForUpdate()
                    ->first();

                if ($planItem) {
                    $planItem->actual_quantity = round(max((float) $planItem->actual_quantity - (float) $usage->quantity_used, 0), 2);
                    $planItem->save();
                }

                $usage->delete();

                $pricingTotals = $this->syncRepairPricingTotals($repairRequest);
            });

            return response()->json([
                'success' => true,
                'message' => 'Material usage removed and stock restored.',
                'meta' => [
                    'pricing' => $pricingTotals,
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Repair request or material usage not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove material usage: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function resolveRepairMaterialActorContext(): array
    {
        $user = Auth::guard('user')->user();
        if ($user) {
            $isManager = method_exists($user, 'hasRole') ? (bool) $user->hasRole('Manager') : false;

            return [
                'shop_owner_id' => (int) ($user->shop_owner_id ?? 0),
                'actor_user_id' => (int) ($user->id ?? 0),
                'enforce_assignment' => true,
                'can_manage' => $isManager,
            ];
        }

        $shopOwner = Auth::guard('shop_owner')->user();
        if ($shopOwner) {
            return [
                'shop_owner_id' => (int) ($shopOwner->id ?? 0),
                'actor_user_id' => 0,
                'enforce_assignment' => false,
                'can_manage' => true,
            ];
        }

        return [
            'shop_owner_id' => 0,
            'actor_user_id' => 0,
            'enforce_assignment' => false,
            'can_manage' => false,
        ];
    }

    private function resolveRepairMaterialFallbackRequesterId(int $shopOwnerId): int
    {
        if ($shopOwnerId <= 0) {
            return 0;
        }

        return (int) (User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->orderBy('id')
            ->value('id') ?? 0);
    }

    private function computeRepairPricingTotals(RepairRequest $repairRequest): array
    {
        $repairRequest->loadMissing(['materialUsages.inventoryItem:id,price']);

        $materialsTotal = round((float) $repairRequest->materialUsages->sum(function ($usage) {
            $unitPrice = (float) ($usage->inventoryItem->price ?? 0);
            return ((int) $usage->quantity_used) * $unitPrice;
        }), 2);

        $packagePrice = round((float) ($repairRequest->package_price ?? 0), 2);
        $addOnsTotal = round((float) ($repairRequest->add_ons_total ?? 0), 2);
        $baseTotal = !is_null($repairRequest->repair_package_id)
            ? round($packagePrice + $addOnsTotal, 2)
            : round((float) ($repairRequest->total ?? 0), 2);

        // Keep final_total stable with the billable base amount; materials_total
        // remains available in pricing_breakdown for operational visibility.
        $finalTotal = $baseTotal;

        return [
            'base_total' => $baseTotal,
            'materials_total' => $materialsTotal,
            'final_total' => $finalTotal,
        ];
    }

    /**
     * Resolve the best repairer candidate for a repair request.
     * Priority:
     * 1) Exact skill coverage of all required skills
     * 2) Best partial skill coverage
     * 3) Lowest-workload active repairer
     * 4) Active staff with repair-related permissions
     */
    private function resolveRepairerAssignmentCandidate(RepairRequest $repairRequest, ?int $excludeUserId = null): array
    {
        $workloadLimit = $this->getRepairWorkloadLimitForShop((int) $repairRequest->shop_owner_id);

        $baseRepairerQuery = User::query()
            ->where('shop_owner_id', $repairRequest->shop_owner_id)
            ->whereHas('roles', function ($query) {
                $query->where('name', 'Repairer');
            })
            ->where('status', 'active')
            ->when($excludeUserId, function ($query) use ($excludeUserId) {
                $query->where('id', '!=', $excludeUserId);
            })
            ->withCount([
                'assignedRepairs as active_repairs_count' => function ($query) {
                    $query->whereIn('status', $this->getActiveRepairStatuses());
                }
            ])
            ->having('active_repairs_count', '<', $workloadLimit);

        $workloadFallback = (clone $baseRepairerQuery)
            ->orderBy('active_repairs_count')
            ->orderBy('id')
            ->first();

        if ($workloadFallback) {
            return [
                'repairer' => $workloadFallback,
                'strategy' => 'workload_fallback',
                'matched_skill_count' => 0,
            ];
        }

        $permissionFallback = User::query()
            ->where('shop_owner_id', $repairRequest->shop_owner_id)
            ->where('status', 'active')
            ->when($excludeUserId, function ($query) use ($excludeUserId) {
                $query->where('id', '!=', $excludeUserId);
            })
            ->whereHas('permissions', function ($query) {
                $query->where('name', 'like', '%repair%');
            })
            ->withCount([
                'assignedRepairs as active_repairs_count' => function ($query) {
                    $query->whereIn('status', $this->getActiveRepairStatuses());
                }
            ])
            ->having('active_repairs_count', '<', $workloadLimit)
            ->orderBy('active_repairs_count')
            ->orderBy('id')
            ->first();

        if ($permissionFallback) {
            return [
                'repairer' => $permissionFallback,
                'strategy' => 'permission_fallback',
                'matched_skill_count' => 0,
            ];
        }

        return [
            'repairer' => null,
            'strategy' => 'no_candidate_found',
            'matched_skill_count' => 0,
        ];
    }

    private function logAssignmentDecision(
        RepairRequest $repairRequest,
        array $selection,
        string $context,
        ?int $excludedRepairerId = null
    ): void {
        try {
            $candidate = $selection['repairer'] ?? null;

            $properties = [
                'context' => $context,
                'strategy' => (string) ($selection['strategy'] ?? 'unknown'),
                'selected_repairer_id' => $candidate ? (int) $candidate->id : null,
                'selected_repairer_name' => $candidate ? (string) ($candidate->name ?? 'Repairer') : null,
                'matched_required_skill_count' => (int) ($selection['matched_skill_count'] ?? 0),
            ];

            if ($excludedRepairerId) {
                $properties['excluded_repairer_id'] = $excludedRepairerId;
            }

            $actor = Auth::guard('user')->user() ?? Auth::guard('shop_owner')->user();

            $activity = activity('repair_assignment')
                ->performedOn($repairRequest)
                ->withProperties($properties);

            if ($actor instanceof \Illuminate\Database\Eloquent\Model) {
                $activity->causedBy($actor);
            }

            $activity->log('Repair assignment decision recorded.');
        } catch (\Throwable $error) {
            logger()->warning('Failed to log repair assignment decision.', [
                'repair_request_id' => $repairRequest->id,
                'context' => $context,
                'error' => $error->getMessage(),
            ]);
        }
    }

    private function buildSuggestedRepairerPayload(RepairRequest $repairRequest, ?int $excludeUserId = null): ?array
    {
        $selection = $this->resolveRepairerAssignmentCandidate($repairRequest, $excludeUserId);
        $candidate = $selection['repairer'];

        if (!$candidate) {
            return null;
        }

        return [
            'id' => (int) $candidate->id,
            'name' => (string) ($candidate->name ?? 'Repairer'),
            'strategy' => (string) ($selection['strategy'] ?? 'unknown'),
            'required_skill_count' => 0,
            'matched_required_skill_count' => (int) ($selection['matched_skill_count'] ?? 0),
            'required_skills' => [],
        ];
    }

    private function getActiveRepairStatuses(): array
    {
        return [
            'assigned_to_repairer',
            'repairer_accepted',
            'pending',
            'received',
            'in_progress',
            'awaiting_parts',
            'ready_for_pickup',
            'waiting_customer_confirmation',
            'confirmed',
            'owner_approval_pending',
            'owner_approved',
            'manager_reviewing',
            'manager_approved',
        ];
    }

    private function getRepairWorkloadLimitForShop(int $shopOwnerId): int
    {
        $shopOwner = ShopOwner::query()->find($shopOwnerId);

        return (int) ($shopOwner?->repair_workload_limit ?? 20);
    }

    private function syncRepairPricingTotals(RepairRequest $repairRequest): array
    {
        $totals = $this->computeRepairPricingTotals($repairRequest);

        $pricingBreakdown = is_array($repairRequest->pricing_breakdown)
            ? $repairRequest->pricing_breakdown
            : [];

        $pricingBreakdown['base_total'] = $totals['base_total'];
        $pricingBreakdown['materials_total'] = $totals['materials_total'];
        $pricingBreakdown['final_total'] = $totals['final_total'];

        $repairRequest->forceFill([
            'final_total' => $totals['final_total'],
            'pricing_breakdown' => $pricingBreakdown,
        ])->save();

        return $totals;
    }

    /**
     * Backward-compatible payment gate for repair progression.
     *
     * Legacy records may still hold `partially_paid` for the deposit phase.
     * Treat that as paid when policy is deposit_50 so existing orders can be
     * marked received and moved to in-progress.
     */
    private function isPaymentSatisfiedForRepairProgress(RepairRequest $repairRequest): bool
    {
        $status = strtolower((string) ($repairRequest->payment_status ?? ''));
        if (in_array($status, ['paid', 'completed'], true)) {
            return true;
        }

        $policy = strtolower((string) ($repairRequest->payment_policy_snapshot ?: $repairRequest->payment_policy ?: 'deposit_50'));

        return $policy === 'deposit_50' && $status === 'partially_paid';
    }

    private function ensureRepairMaterialPlanItems(RepairRequest $repairRequest): void
    {
        $templatePlan = $this->buildTemplateMaterialPlan($repairRequest);
        if (empty($templatePlan)) {
            return;
        }

        $existing = RepairMaterialPlanItem::query()
            ->where('repair_request_id', $repairRequest->id)
            ->get()
            ->keyBy('inventory_item_id');

        foreach ($templatePlan as $inventoryItemId => $line) {
            /** @var RepairMaterialPlanItem|null $existingLine */
            $existingLine = $existing->get((int) $inventoryItemId);

            if ($existingLine) {
                $existingLine->planned_quantity = (float) $line['planned_quantity'];
                $existingLine->is_critical = (bool) $line['is_critical'];
                $existingLine->tolerance_percent = (float) $line['tolerance_percent'];
                $existingLine->save();
                continue;
            }

            RepairMaterialPlanItem::query()->create([
                'repair_request_id' => $repairRequest->id,
                'inventory_item_id' => (int) $inventoryItemId,
                'planned_quantity' => (float) $line['planned_quantity'],
                'actual_quantity' => 0,
                'is_critical' => (bool) $line['is_critical'],
                'tolerance_percent' => (float) $line['tolerance_percent'],
            ]);
        }
    }

    private function buildTemplateMaterialPlan(RepairRequest $repairRequest): array
    {
        $repairRequest->loadMissing([
            'repairPackage.materialTemplateItems',
            'repairPackage.services.materialTemplateItems',
            'services.materialTemplateItems',
        ]);

        $templateRows = collect();

        if ($repairRequest->repairPackage) {
            $templateRows = $templateRows->concat($repairRequest->repairPackage->materialTemplateItems);
        }

        if ($repairRequest->services->isNotEmpty()) {
            foreach ($repairRequest->services as $service) {
                $templateRows = $templateRows->concat($service->materialTemplateItems);
            }
        } elseif ($repairRequest->repairPackage) {
            foreach ($repairRequest->repairPackage->services as $packageService) {
                $templateRows = $templateRows->concat($packageService->materialTemplateItems);
            }
        }

        // Fallback for records where relationships are missing but service snapshots still exist.
        if ($templateRows->isEmpty()) {
            $snapshotRows = array_merge(
                (array) ($repairRequest->included_services_snapshot ?? []),
                (array) ($repairRequest->add_on_services_snapshot ?? [])
            );

            $snapshotServiceIds = collect($snapshotRows)
                ->map(fn ($row) => is_array($row) ? (int) ($row['id'] ?? 0) : 0)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values();

            if ($snapshotServiceIds->isNotEmpty()) {
                $snapshotServices = RepairService::query()
                    ->whereIn('id', $snapshotServiceIds)
                    ->where('shop_owner_id', $repairRequest->shop_owner_id)
                    ->with(['materialTemplateItems'])
                    ->get();

                foreach ($snapshotServices as $snapshotService) {
                    $templateRows = $templateRows->concat($snapshotService->materialTemplateItems);
                }
            }
        }

        // Fallback for archived packages that are no longer returned by the default relation.
        if ($templateRows->isEmpty() && !empty($repairRequest->repair_package_id) && !$repairRequest->repairPackage) {
            $archivedPackage = RepairPackage::withTrashed()
                ->with([
                    'materialTemplateItems',
                    'services.materialTemplateItems',
                ])
                ->find((int) $repairRequest->repair_package_id);

            if ($archivedPackage) {
                $templateRows = $templateRows->concat($archivedPackage->materialTemplateItems);

                if ($repairRequest->services->isEmpty()) {
                    foreach ($archivedPackage->services as $archivedPackageService) {
                        $templateRows = $templateRows->concat($archivedPackageService->materialTemplateItems);
                    }
                }
            }
        }

        $grouped = [];
        foreach ($templateRows as $row) {
            $inventoryItemId = (int) ($row->inventory_item_id ?? 0);
            if ($inventoryItemId <= 0) {
                continue;
            }

            if (!isset($grouped[$inventoryItemId])) {
                $grouped[$inventoryItemId] = [
                    'planned_quantity' => 0.0,
                    'is_critical' => false,
                    'tolerance_percent' => 0.0,
                ];
            }

            $grouped[$inventoryItemId]['planned_quantity'] += (float) ($row->default_quantity ?? 0);
            $grouped[$inventoryItemId]['is_critical'] =
                $grouped[$inventoryItemId]['is_critical'] || (bool) ($row->is_critical ?? false);

            $rowTolerance = (float) ($row->tolerance_percent ?? 20);
            $grouped[$inventoryItemId]['tolerance_percent'] = max(
                (float) $grouped[$inventoryItemId]['tolerance_percent'],
                $rowTolerance
            );
        }

        foreach ($grouped as $inventoryItemId => $line) {
            $grouped[$inventoryItemId]['planned_quantity'] = round(max((float) $line['planned_quantity'], 0), 2);
            $grouped[$inventoryItemId]['tolerance_percent'] = round(max((float) $line['tolerance_percent'], 0), 2);
        }

        return array_filter($grouped, fn ($line) => (float) $line['planned_quantity'] > 0);
    }

    private function repairHasConfiguredMaterialTemplates(RepairRequest $repairRequest): bool
    {
        $repairRequest->loadMissing([
            'repairPackage.materialTemplateItems:id,template_id,template_type',
            'services.materialTemplateItems:id,template_id,template_type',
        ]);

        $packageTemplateCount = (int) ($repairRequest->repairPackage?->materialTemplateItems?->count() ?? 0);
        $serviceTemplateCount = (int) $repairRequest->services->sum(
            fn ($service) => (int) $service->materialTemplateItems->count()
        );

        return ($packageTemplateCount + $serviceTemplateCount) > 0;
    }

    private function validateMaterialLoggingGateForTransition(
        RepairRequest $repairRequest,
        bool $noMaterialsUsedConfirmed,
        string $transitionLabel
    ) {
        $materialUsageExists = $repairRequest->materialUsages()->exists();
        if ($materialUsageExists) {
            return null;
        }

        if ($this->repairHasConfiguredMaterialTemplates($repairRequest)) {
            return response()->json([
                'success' => false,
                'message' => sprintf(
                    'This repair has configured material templates. Log the material usage before marking it as %s.',
                    $transitionLabel
                ),
                'requires_material_logging' => true,
            ], 422);
        }

        if (!$noMaterialsUsedConfirmed) {
            return response()->json([
                'success' => false,
                'message' => sprintf(
                    'No materials usage was logged for this repair. Confirm "No Materials Used" to continue marking it as %s, or log at least one material usage entry.',
                    $transitionLabel
                ),
                'requires_material_confirmation' => true,
            ], 422);
        }

        return null;
    }

    private function validateMaterialCompletionGateForTransition(RepairRequest $repairRequest)
    {
        $this->ensureRepairMaterialPlanItems($repairRequest);
        $repairRequest->load('materialPlanItems');

        $planner = app(RepairMaterialPlanningService::class);
        $readiness = $planner->validateCompletionReadiness($repairRequest);

        if (($readiness['readiness_state'] ?? 'ready') === 'ready') {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Resolve material variance note/review first before moving this repair forward.',
            'requires_variance_review' => true,
            'data' => $readiness,
        ], 422);
    }

    private function generateStockRequestNumber(): string
    {
        $year = now()->year;

        $last = StockRequestApproval::query()
            ->where('request_number', 'LIKE', "SR-{$year}-%")
            ->orderBy('request_number', 'desc')
            ->first();

        $nextNum = $last ? intval(substr((string) $last->request_number, -3)) + 1 : 1;

        return "SR-{$year}-" . str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);
    }
}
