<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\RepairRequest;
use App\Models\User;
use App\Models\ShopOwner;
use App\Models\Conversation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationService;

class RepairWorkflowController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
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
            
            // Find an available repairer from the shop
            $repairer = User::where('shop_owner_id', $repairRequest->shop_owner_id)
                ->whereHas('roles', function($query) {
                    $query->where('name', 'Repairer');
                })
                ->where('status', 'active')
                ->inRandomOrder()
                ->first();
            
            if (!$repairer) {
                // No repairer available, assign to any staff with repair permissions
                $repairer = User::where('shop_owner_id', $repairRequest->shop_owner_id)
                    ->whereHas('permissions', function($query) {
                        $query->where('name', 'like', '%repair%');
                    })
                    ->where('status', 'active')
                    ->inRandomOrder()
                    ->first();
            }
            
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
                    'repairer_id' => $repairer->id
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'No available repairer found'
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
            $requiresOwnerApproval = $isHighValue && $shopOwner->require_two_way_approval;
            
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
            // Check if authenticated as shop owner first
            $shopOwner = Auth::guard('shop_owner')->user();
            
            if ($shopOwner) {
                // Shop owner sees all repairs for their shop
                $repairs = RepairRequest::with(['user', 'services', 'shopOwner', 'repairer'])
                    ->where('shop_owner_id', $shopOwner->id)
                    ->whereIn('status', ['new_request', 'assigned_to_repairer', 'repairer_accepted', 'waiting_customer_confirmation', 'owner_approval_pending', 'owner_approved', 'confirmed', 'pending', 'in_progress', 'awaiting_parts', 'completed', 'ready_for_pickup', 'picked_up', 'rejected', 'cancelled', 'received', 'under-review'])
                    ->orderBy('created_at', 'desc')
                    ->get();
                
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
                ->forRepairer($user->id)
                ->whereIn('status', ['assigned_to_repairer', 'repairer_accepted', 'waiting_customer_confirmation', 'owner_approval_pending', 'owner_approved', 'confirmed', 'pending', 'in_progress', 'awaiting_parts', 'completed', 'ready_for_pickup', 'picked_up', 'rejected', 'cancelled', 'received'])
                ->orderBy('created_at', 'desc')
                ->get();
            
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
                
                // Create conversation between customer and shop owner
                $conversation = Conversation::create([
                    'shop_owner_id' => $repairRequest->shop_owner_id,
                    'customer_id' => $repairRequest->user_id,
                    'assigned_to_id' => $shopOwner->id,
                    'assigned_to_type' => 'shop_owner',
                    'status' => 'open',
                    'priority' => 'medium',
                    'last_message_at' => now(),
                ]);
                
                // Determine next status based on delivery method
                $nextStatus = $repairRequest->delivery_method === 'walk_in' 
                    ? 'received' 
                    : 'repairer_accepted';
                
                // For walk-in, auto-confirm since shoes are already at shop
                $updateData = [
                    'status' => $nextStatus,
                    'conversation_id' => $conversation->id,
                ];
                
                if ($repairRequest->delivery_method === 'walk_in') {
                    $updateData['customer_confirmed_at'] = now();
                    $updateData['received_at'] = now();
                }
                
                // Update repair request
                $repairRequest->update($updateData);
                
                DB::commit();
                
                $message = $repairRequest->delivery_method === 'walk_in' 
                    ? 'Repair accepted. Shoes received and ready for processing.'
                    : 'Repair accepted. Chat conversation created with customer.';
                
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'conversation_id' => $conversation->id,
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
            
            // Create conversation between customer and repairer
            $conversation = Conversation::create([
                'shop_owner_id' => $repairRequest->shop_owner_id,
                'customer_id' => $repairRequest->user_id,
                'assigned_to_id' => $user->id,
                'assigned_to_type' => 'repairer',
                'status' => 'open',
                'priority' => 'medium',
                'last_message_at' => now(),
            ]);
            
            // Determine next status based on delivery method
            // Walk-in: Customer is present, skip customer confirmation, go straight to received
            // Pickup/Delivery: Customer must confirm remotely
            $nextStatus = $repairRequest->delivery_method === 'walk_in' 
                ? 'received' 
                : 'repairer_accepted';
            
            // For walk-in, auto-confirm since shoes are already at shop
            $updateData = [
                'status' => $nextStatus,
                'conversation_id' => $conversation->id,
            ];
            
            if ($repairRequest->delivery_method === 'walk_in') {
                $updateData['customer_confirmed_at'] = now();
                $updateData['received_at'] = now();
            }
            
            // Update repair request
            $repairRequest->update($updateData);
            
            DB::commit();
            
            // TODO: Send notification to customer
            
            $message = $repairRequest->delivery_method === 'walk_in' 
                ? 'Repair accepted. Shoes received and ready for processing.'
                : 'Repair accepted. Chat conversation created with customer.';
            
            return response()->json([
                'success' => true,
                'message' => $message,
                'conversation_id' => $conversation->id,
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
            'reason' => 'required|string|min:10|max:500'
        ]);
        
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
                    'owner_approval_notes' => $request->reason,
                    'owner_reviewed_at' => now(),
                    'owner_reviewed_by' => $shopOwner->id,
                ]);
                
                DB::commit();
                
                // TODO: Send notification to customer
                
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
            
            $repairRequest->update([
                'status' => 'repairer_rejected',
                'repairer_rejection_reason' => $request->reason,
                'repairer_rejected_at' => now(),
                'repairer_rejected_by_id' => $user->id,
                'assigned_manager_id' => $manager ? $manager->id : null,
            ]);
            
            DB::commit();
            
            // TODO: Send notification to manager
            
            return response()->json([
                'success' => true,
                'message' => 'Repair rejected. Manager has been notified for review.',
                'repair' => $repairRequest->fresh(['user', 'services', 'manager'])
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
            
            // Check if user has Manager role
            if (!$user->hasRole('Manager')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Manager role required.'
                ], 403);
            }
            
            // Get repairs that have been rejected by repairer (all statuses related to rejection)
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
                ->whereIn('status', ['repairer_rejected', 'rejected', 'assigned_to_repairer'])
                ->orderBy('repairer_rejected_at', 'desc')
                ->get();
            
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
     * Manager approves the repairer's rejection (Phase 5)
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
            
            // Check if user has Manager role
            if (!$user->hasRole('Manager')) {
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
                    'message' => 'Repair is not in rejected status'
                ], 400);
            }
            
                $repairRequest->update([
                    'status' => 'rejected',
                    'manager_decision' => 'approve_rejection',
                'manager_review_notes' => $request->notes,
                'manager_reviewed_at' => now(),
                'manager_reviewed_by' => $user->id,
            ]);
            
            DB::commit();
            
            // TODO: Send notification to customer
            
            return response()->json([
                'success' => true,
                'message' => 'Rejection approved. Customer will be notified.',
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
     * Manager overrides repairer's rejection and reassigns (Phase 5)
     */
    public function overrideRejection(Request $request, $requestId)
    {
        $request->validate([
            'notes' => 'required|string|min:10|max:500'
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
            
            // Check if user has Manager role
            if (!$user->hasRole('Manager')) {
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
                    'message' => 'Repair is not in rejected status'
                ], 400);
            }
            
            // Find another repairer (not the one who rejected)
            $newRepairer = User::where('shop_owner_id', $repairRequest->shop_owner_id)
                ->where('id', '!=', $repairRequest->repairer_rejected_by_id)
                ->whereHas('roles', function($query) {
                    $query->where('name', 'Repairer');
                })
                ->where('status', 'active')
                ->inRandomOrder()
                ->first();
            
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
                'message' => $newRepairer 
                    ? 'Rejection overridden. Repair reassigned to another repairer.' 
                    : 'Rejection overridden but no available repairer found.',
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
                    'user:id,first_name,last_name,email,phone_number',
                    'services:id,name,base_price',
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
            
            // Validate payment is completed before starting work
            if ($repairRequest->payment_enabled && $repairRequest->payment_status !== 'completed') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Payment must be completed before starting work on this repair.'
                ], 400);
            }
            
            $repairRequest->update([
                'status' => 'in_progress',
                'work_started_at' => now(),
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

            DB::beginTransaction();
            
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
            'completion_notes' => 'nullable|string|max:500'
        ]);

        try {
            // Check if authenticated as shop owner first
            $shopOwner = Auth::guard('shop_owner')->user();
            
            if ($shopOwner) {
                // Shop owner can mark any repair for their shop as completed
                DB::beginTransaction();
                
                $repairRequest = RepairRequest::where('id', $id)
                    ->where('shop_owner_id', $shopOwner->id)
                    ->where('status', 'in_progress')
                    ->firstOrFail();
                
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

            DB::beginTransaction();
            
            $repairRequest = RepairRequest::where('id', $id)
                ->where('assigned_repairer_id', $user->id)
                ->where('status', 'in_progress')
                ->firstOrFail();
            
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
            'pickup_instructions' => 'nullable|string|max:500'
        ]);

        try {
            // Check if authenticated as shop owner first
            $shopOwner = Auth::guard('shop_owner')->user();
            
            if ($shopOwner) {
                // Shop owner can mark any repair for their shop as ready
                DB::beginTransaction();
                
                $repairRequest = RepairRequest::where('id', $id)
                    ->where('shop_owner_id', $shopOwner->id)
                    ->whereIn('status', ['completed', 'in_progress'])
                    ->firstOrFail();
                
                $repairRequest->update([
                    'status' => 'ready_for_pickup',
                    'ready_for_pickup_at' => now(),
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

            DB::beginTransaction();
            
            $repairRequest = RepairRequest::where('id', $id)
                ->where('assigned_repairer_id', $user->id)
                ->whereIn('status', ['completed', 'in_progress'])
                ->firstOrFail();
            
            $repairRequest->update([
                'status' => 'ready_for_pickup',
                'ready_for_pickup_at' => now(),
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
            // Check if authenticated as shop owner first
            $shopOwner = Auth::guard('shop_owner')->user();
            
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
                
                // Check if status is ready_for_pickup
                if ($repairRequest->status !== 'ready_for_pickup') {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Pickup can only be activated when repair is ready for pickup'
                    ], 400);
                }
                
                // Check if pickup is already enabled
                if ($repairRequest->pickup_enabled) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Pickup confirmation is already activated'
                    ], 400);
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
            
            // Check if status is ready_for_pickup
            if ($repairRequest->status !== 'ready_for_pickup') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Pickup can only be activated when repair is ready for pickup'
                ], 400);
            }
            
            // Check if pickup is already enabled
            if ($repairRequest->pickup_enabled) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Pickup confirmation is already activated'
                ], 400);
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

    /**
     * Customer confirms repair (Phase 4)
     * Changes status from repairer_accepted to waiting_customer_confirmation
     * If high-value, changes to owner_approval_pending instead
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
            
            // Check if this is a high-value repair requiring owner approval
            $requiresOwnerApproval = $repairRequest->requires_owner_approval ?? false;
            
            $newStatus = $requiresOwnerApproval ? 'owner_approval_pending' : 'waiting_customer_confirmation';
            
            $repairRequest->update([
                'status' => $newStatus,
                'customer_confirmed_at' => now(),
            ]);
            
            DB::commit();
            
            // TODO: Send notification to repairer or shop owner
            
            return response()->json([
                'success' => true,
                'message' => 'Repair confirmed successfully.',
                'requires_owner_approval' => $requiresOwnerApproval,
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
}
