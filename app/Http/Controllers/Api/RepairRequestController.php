<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\NotificationService;

class RepairRequestController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'shoe_type' => 'nullable|string|max:255',
            'brand' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'shop_owner_id' => 'nullable|exists:shop_owners,id',
            'services' => 'required|array',
            'services.*' => 'exists:repair_services,id',
            'images' => 'required|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
            'total' => 'required|numeric|min:0',
            'service_type' => 'required|in:pickup,walkin',
            'pickup_address_line' => 'required_if:service_type,pickup|string|max:255',
            'pickup_barangay' => 'required_if:service_type,pickup|string|max:255',
            'pickup_city' => 'required_if:service_type,pickup|string|max:255',
            'pickup_region' => 'required_if:service_type,pickup|string|max:255',
            'pickup_postal_code' => 'required_if:service_type,pickup|string|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Generate unique request ID
            $requestId = 'REP-' . date('Ymd') . str_pad(RepairRequest::whereDate('created_at', today())->count() + 1, 3, '0', STR_PAD_LEFT);

            // Handle image uploads
            $imagePaths = [];
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $path = $image->store('repair-requests', 'public');
                    $imagePaths[] = $path;
                }
            }

            // Get authenticated user if available
            $userId = Auth::guard('user')->check() ? Auth::guard('user')->id() : null;
            
            // Get shop owner for high value check
            $shopOwner = ShopOwner::find($request->shop_owner_id);
            $isHighValue = $shopOwner && $request->total >= $shopOwner->high_value_threshold;
            $requiresOwnerApproval = $isHighValue && $shopOwner && $shopOwner->require_two_way_approval;
            
            // Build pickup address if service type is pickup
            $pickupAddress = null;
            $deliveryMethod = $request->service_type === 'pickup' ? 'pickup' : 'walk_in';
            
            if ($request->service_type === 'pickup') {
                $pickupAddress = [
                    'address_line' => $request->pickup_address_line,
                    'barangay' => $request->pickup_barangay,
                    'city' => $request->pickup_city,
                    'region' => $request->pickup_region,
                    'postal_code' => $request->pickup_postal_code,
                ];
            }
            
            // Create repair request
            $repairRequest = RepairRequest::create([
                'request_id' => $requestId,
                'customer_name' => $request->customer_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'shoe_type' => $request->shoe_type,
                'brand' => $request->brand,
                'description' => $request->description,
                'shop_owner_id' => $request->shop_owner_id,
                'user_id' => $userId,
                'images' => json_encode($imagePaths),
                'total' => $request->total,
                'status' => 'new_request',
                'delivery_method' => $deliveryMethod,
                'pickup_address' => $pickupAddress,
                'is_high_value' => $isHighValue,
                'requires_owner_approval' => $requiresOwnerApproval,
            ]);

            // Attach services
            $repairRequest->services()->attach($request->services);

            // Get notification service
            $notificationService = app(NotificationService::class);

            // Notify shop owner of new repair request
            if ($request->shop_owner_id) {
                $notificationService->notifyNewRepairRequest($request->shop_owner_id, [
                    'request_id' => $requestId,
                    'order_number' => $requestId,
                    'customer_name' => $request->customer_name,
                    'service_type' => $request->service_type,
                    'total' => $request->total,
                    'service_count' => count($request->services),
                ]);

                // If high-value repair requiring owner approval, send additional notification
                if ($requiresOwnerApproval) {
                    $notificationService->notifyHighValueRepairApproval($request->shop_owner_id, [
                        'request_id' => $requestId,
                        'order_number' => $requestId,
                        'customer_name' => $request->customer_name,
                        'total' => $request->total,
                        'threshold' => $shopOwner->high_value_threshold,
                    ]);
                }
            }

            // AUTO-ASSIGN TO REPAIRER (Phase 2)
            $this->autoAssignRepairer($repairRequest);

            return response()->json([
                'success' => true,
                'message' => 'Repair request submitted successfully',
                'data' => [
                    'request_id' => $requestId,
                    'total' => $request->total,
                    'status' => $repairRequest->fresh()->status,
                    'assigned_repairer_id' => $repairRequest->fresh()->assigned_repairer_id,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit repair request: ' . $e->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        $query = RepairRequest::with(['services', 'shopOwner']);

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by shop owner
        if ($request->has('shop_owner_id')) {
            $query->where('shop_owner_id', $request->shop_owner_id);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('request_id', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('shoe_type', 'like', "%{$search}%");
            });
        }

        $repairRequests = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $repairRequests->map(function ($request) {
                // Images are already cast as array, so handle both formats
                $images = is_array($request->images) ? $request->images : (is_string($request->images) ? json_decode($request->images, true) : []);
                return [
                    'id' => $request->request_id,
                    'customer' => $request->customer_name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'item' => $request->shoe_type . ($request->brand ? " ({$request->brand})" : ''),
                    'service' => $request->services->pluck('name')->join(', '),
                    'total' => '₱' . number_format($request->total, 0),
                    'status' => $request->status,
                    'createdAt' => $request->created_at->format('Y-m-d h:i A'),
                    'startedAt' => $request->started_at ? $request->started_at->format('Y-m-d h:i A') : null,
                    'completedAt' => $request->completed_at ? $request->completed_at->format('Y-m-d h:i A') : null,
                    'notes' => $request->description,
                    'imageUrl' => !empty($images) ? Storage::url($images[0]) : null,
                    'repairDetails' => $request->services->pluck('description')->toArray(),
                ];
            })
        ]);
    }

    public function updateStatus(Request $request, $requestId)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:received,pending,in-progress,completed,ready-for-pickup',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $repairRequest = RepairRequest::where('request_id', $requestId)->firstOrFail();
            
            $repairRequest->status = $request->status;
            
            if ($request->status === 'in-progress' && !$repairRequest->started_at) {
                $repairRequest->started_at = now();
            }
            
            if ($request->status === 'ready-for-pickup' && !$repairRequest->completed_at) {
                $repairRequest->completed_at = now();
            }
            
            $repairRequest->save();

            return response()->json([
                'success' => true,
                'message' => 'Status updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get authenticated customer's repair requests
     */
    public function myRepairs(Request $request)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $query = RepairRequest::with(['services', 'shopOwner', 'repairer'])
            ->forCustomer($user->id);

        // Filter by status if provided
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $repairRequests = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $repairRequests->map(function ($repair) {
                // Images are already cast as array, so no need to json_decode
                $images = is_array($repair->images) ? $repair->images : (is_string($repair->images) ? json_decode($repair->images, true) : []);
                return [
                    'id' => $repair->id,
                    'order_number' => $repair->request_id,
                    'repair_type' => $repair->services->pluck('name')->join(', '),
                    'description' => $repair->description,
                    'status' => $repair->status,
                    'total_amount' => $repair->total,
                    'created_at' => $repair->created_at->toISOString(),
                    'estimated_completion' => $repair->scheduled_dropoff_date ? $repair->scheduled_dropoff_date->format('M d, Y') : null,
                    'completed_at' => $repair->completed_at ? $repair->completed_at->format('M d, Y') : null,
                    'shop_id' => $repair->shop_owner_id,
                    'shop_name' => $repair->shopOwner ? $repair->shopOwner->business_name : 'Unknown Shop',
                    'shop_address' => $repair->shopOwner ? $repair->shopOwner->business_address : '',
                    'image' => !empty($images) ? Storage::url($images[0]) : null,
                    'delivery_method' => $repair->delivery_method,
                    'pickup_address' => $repair->pickup_address,
                    'conversation_id' => $repair->conversation_id,
                    'payment_status' => $repair->payment_status ?? 'pending',
                    'payment_completed_at' => $repair->payment_completed_at ? $repair->payment_completed_at->toISOString() : null,
                    'paymongo_link_id' => $repair->paymongo_link_id,
                    'payment_enabled' => $repair->payment_enabled ?? false,
                    'payment_enabled_at' => $repair->payment_enabled_at ? $repair->payment_enabled_at->toISOString() : null,
                    'pickup_enabled' => $repair->pickup_enabled ?? false,
                    'pickup_enabled_at' => $repair->pickup_enabled_at ? $repair->pickup_enabled_at->toISOString() : null,
                ];
            })
        ]);
    }

    /**
     * Get single repair request details
     */
    public function show(Request $request, $id)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $repair = RepairRequest::with(['services', 'shopOwner', 'repairer', 'conversation'])
            ->where('id', $id)
            ->forCustomer($user->id)
            ->first();

        if (!$repair) {
            return response()->json([
                'success' => false,
                'message' => 'Repair request not found'
            ], 404);
        }

        // Images are already cast as array, so handle both formats
        $images = is_array($repair->images) ? $repair->images : (is_string($repair->images) ? json_decode($repair->images, true) : []);
        
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $repair->id,
                'request_id' => $repair->request_id,
                'customer_name' => $repair->customer_name,
                'email' => $repair->email,
                'phone' => $repair->phone,
                'shoe_type' => $repair->shoe_type,
                'brand' => $repair->brand,
                'description' => $repair->description,
                'total' => $repair->total,
                'status' => $repair->status,
                'delivery_method' => $repair->delivery_method,
                'pickup_address' => $repair->pickup_address,
                'scheduled_dropoff_date' => $repair->scheduled_dropoff_date,
                'customer_confirmed_at' => $repair->customer_confirmed_at,
                'is_high_value' => $repair->is_high_value,
                'started_at' => $repair->started_at,
                'completed_at' => $repair->completed_at,
                'picked_up_at' => $repair->picked_up_at,
                'created_at' => $repair->created_at,
                'images' => array_map(function($path) {
                    return Storage::url($path);
                }, $images ?: []),
                'services' => $repair->services->map(function($service) {
                    return [
                        'id' => $service->id,
                        'name' => $service->name,
                        'price' => $service->price,
                        'description' => $service->description,
                    ];
                }),
                'shop' => $repair->shopOwner ? [
                    'id' => $repair->shopOwner->id,
                    'name' => $repair->shopOwner->business_name,
                    'address' => $repair->shopOwner->business_address,
                    'phone' => $repair->shopOwner->phone,
                ] : null,
                'repairer' => $repair->repairer ? [
                    'id' => $repair->repairer->id,
                    'name' => $repair->repairer->name,
                ] : null,
                'conversation_id' => $repair->conversation_id,
            ]
        ]);
    }

    /**
     * Cancel repair request
     */
    public function cancel(Request $request, $id)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $repair = RepairRequest::where('id', $id)
            ->forCustomer($user->id)
            ->first();

        if (!$repair) {
            return response()->json([
                'success' => false,
                'message' => 'Repair request not found'
            ], 404);
        }

        // Check if can be cancelled
        if (in_array($repair->status, ['completed', 'picked_up', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel repair in current status'
            ], 400);
        }

        $repair->update([
            'status' => 'cancelled'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Repair request cancelled successfully'
        ]);
    }

    /**
     * Confirm pickup (Phase 9)
     */
    public function confirmPickup(Request $request, $id)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $repair = RepairRequest::where('id', $id)
            ->forCustomer($user->id)
            ->whereIn('status', ['ready-for-pickup', 'ready_for_pickup'])
            ->first();

        if (!$repair) {
            return response()->json([
                'success' => false,
                'message' => 'Repair request not found or not ready for pickup'
            ], 404);
        }

        $repair->update([
            'status' => 'picked_up',
            'picked_up_at' => now()
        ]);

        // TODO: Send notification to shop owner/repairer about successful pickup
        // TODO: Trigger review request email/notification after 24 hours

        return response()->json([
            'success' => true,
            'message' => 'Pickup confirmed! Thank you for your business. We hope to serve you again.',
            'data' => $repair->fresh(['services', 'shopOwner', 'repairer'])
        ]);
    }
    
    /**
     * Customer confirms repair after chat discussion (Phase 3)
     */
    public function confirmRepair(Request $request, $id)
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

            $repair = RepairRequest::where('id', $id)
                ->forCustomer($user->id)
                ->whereIn('status', ['repairer_accepted', 'waiting_customer_confirmation'])
                ->with(['shopOwner', 'services', 'repairer'])
                ->firstOrFail();

            // Walk-in repairs are auto-confirmed when repairer accepts, so this shouldn't be needed
            if ($repair->delivery_method === 'walk_in') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Walk-in repairs are automatically confirmed. No action needed.',
                    'data' => $repair->fresh(['services', 'shopOwner', 'repairer']),
                ], 400);
            }

            // Check if this is a high-value repair requiring owner approval
            $requiresOwnerApproval = $repair->is_high_value && $repair->requires_owner_approval;
            
            if ($requiresOwnerApproval) {
                $repair->update([
                    'status' => 'owner_approval_pending',
                    'customer_confirmed_at' => now()
                ]);

                DB::commit();

                // TODO: Notify shop owner of pending high-value approval
                
                return response()->json([
                    'success' => true,
                    'message' => 'Repair confirmed. Awaiting shop owner approval for high-value repair.',
                    'data' => $repair->fresh(['services', 'shopOwner', 'repairer']),
                    'requires_owner_approval' => true
                ]);
            }

            // Regular repair confirmation (not high-value or doesn't require approval)
            $repair->update([
                'status' => 'pending',
                'customer_confirmed_at' => now()
            ]);

            DB::commit();

            // TODO: Notify repairer that customer confirmed
            // Repairer can now start work

            return response()->json([
                'success' => true,
                'message' => 'Repair confirmed. Status updated to pending.',
                'data' => $repair->fresh(['services', 'shopOwner', 'repairer']),
                'requires_owner_approval' => false
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Repair request not found or not in correct status'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error confirming repair: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'repair_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm repair: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update PayMongo payment link for repair order
     */
    public function updatePaymentLink(Request $request, $id)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $repair = RepairRequest::where('id', $id)
                ->forCustomer($user->id)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'paymongo_link_id' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $repair->update([
                'paymongo_link_id' => $request->paymongo_link_id,
                'payment_link_created_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment link updated successfully',
                'data' => $repair
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Repair request not found'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Error updating payment link: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'repair_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment link: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Auto-assign repair request to available repairer (Phase 2)
     * Uses workload-based round-robin for fair distribution
     * Called automatically when repair request is created
     */
    private function autoAssignRepairer(RepairRequest $repairRequest)
    {
        try {
            // STRATEGY 1: Find repairers with Repairer role, sorted by current workload (least busy first)
            $repairer = User::where('shop_owner_id', $repairRequest->shop_owner_id)
                ->whereHas('employee', function($query) {
                    $query->where('status', 'active');
                })
                ->whereHas('roles', function($query) {
                    $query->where('name', 'Repairer');
                })
                ->where('status', 'active')
                ->withCount(['assignedRepairs as active_repairs_count' => function($query) {
                    // Count only active repairs (not completed/cancelled)
                    $query->whereIn('status', [
                        'assigned_to_repairer',
                        'repairer_accepted',
                        'in_progress',
                        'awaiting_parts'
                    ]);
                }])
                ->having('active_repairs_count', '<', 15) // Max capacity limit: 15 active repairs per repairer
                ->orderBy('active_repairs_count', 'asc')  // Assign to least busy first
                ->orderBy('id', 'asc')  // Tie-breaker: earliest hired repairer
                ->first();
            
            // FALLBACK STRATEGY 2: If no repairer with role, try any staff with repair permissions
            if (!$repairer) {
                $repairer = User::where('shop_owner_id', $repairRequest->shop_owner_id)
                    ->whereHas('employee', function($query) {
                        $query->where('status', 'active');
                    })
                    ->whereHas('permissions', function($query) {
                        $query->whereIn('name', [
                            'view-repair-services',
                            'manage-repair-services',
                            'view-job-orders',
                            'create-job-orders',
                            'view-repairer-conversations',
                            'send-repairer-messages'
                        ]);
                    })
                    ->where('status', 'active')
                    ->withCount(['assignedRepairs as active_repairs_count' => function($query) {
                        $query->whereIn('status', [
                            'assigned_to_repairer',
                            'repairer_accepted',
                            'in_progress',
                            'awaiting_parts'
                        ]);
                    }])
                    ->having('active_repairs_count', '<', 15)
                    ->orderBy('active_repairs_count', 'asc')
                    ->first();
            }
            
            // FALLBACK STRATEGY 3: If all repairers at capacity, assign to least busy anyway
            if (!$repairer) {
                $repairer = User::where('shop_owner_id', $repairRequest->shop_owner_id)
                    ->whereHas('employee', function($query) {
                        $query->where('status', 'active');
                    })
                    ->whereHas('roles', function($query) {
                        $query->where('name', 'Repairer');
                    })
                    ->where('status', 'active')
                    ->withCount(['assignedRepairs as active_repairs_count' => function($query) {
                        $query->whereIn('status', [
                            'assigned_to_repairer',
                            'repairer_accepted',
                            'in_progress',
                            'awaiting_parts'
                        ]);
                    }])
                    ->orderBy('active_repairs_count', 'asc')
                    ->first();
            }
            
            if ($repairer) {
                $repairRequest->update([
                    'assigned_repairer_id' => $repairer->id,
                    'status' => 'assigned_to_repairer',
                    'assigned_at' => now()
                ]);
                
                // Log successful assignment with workload info
                $workloadCount = $repairer->active_repairs_count ?? 0;
                \Log::info("✅ Repair {$repairRequest->request_id} auto-assigned to {$repairer->name} (ID: {$repairer->id}) - Current workload: {$workloadCount} active repairs");
                
                // Send notification to repairer about new assignment (ERP staff)
                $notificationService = app(NotificationService::class);
                $notificationService->sendToUser(
                    userId: $repairer->id,
                    type: \App\Enums\NotificationType::REPAIR_ASSIGNED_TO_ME,
                    title: 'New Repair Assigned',
                    message: "Repair request {$repairRequest->request_id} has been assigned to you - {$repairRequest->customer_name}",
                    data: [
                        'request_id' => $repairRequest->request_id,
                        'order_number' => $repairRequest->request_id,
                        'customer_name' => $repairRequest->customer_name,
                        'shoe_type' => $repairRequest->shoe_type,
                        'brand' => $repairRequest->brand,
                        'total' => $repairRequest->total,
                        'service_count' => $repairRequest->services->count(),
                        'is_high_value' => $repairRequest->is_high_value,
                        'delivery_method' => $repairRequest->delivery_method,
                    ],
                    actionUrl: '/erp/staff/job-orders-repair',
                    priority: $repairRequest->is_high_value ? 'high' : 'medium',
                    requiresAction: true
                );
                
            } else {
                // No repairer available - handle assignment failure
                $this->handleAssignmentFailure($repairRequest);
            }
            
        } catch (\Exception $e) {
            \Log::error("❌ Failed to auto-assign repair {$repairRequest->request_id}: " . $e->getMessage());
            $this->handleAssignmentFailure($repairRequest);
        }
    }
    
    /**
     * Handle assignment failure - notify manager and update status
     */
    private function handleAssignmentFailure(RepairRequest $repairRequest)
    {
        try {
            // Update repair status to indicate assignment failed
            $repairRequest->update([
                'status' => 'assignment_failed'
            ]);
            
            \Log::warning("⚠️ No available repairer found for repair {$repairRequest->request_id} in shop {$repairRequest->shop_owner_id}");
            
            // Find and notify manager or shop owner
            $manager = User::where('shop_owner_id', $repairRequest->shop_owner_id)
                ->whereHas('roles', function($query) {
                    $query->where('name', 'Manager');
                })
                ->where('status', 'active')
                ->first();
            
            if ($manager) {
                \Log::info("📧 Notifying manager {$manager->name} (ID: {$manager->id}) about failed assignment for repair {$repairRequest->request_id}");
                // TODO: Send notification to manager
                // event(new AssignmentFailed($repairRequest, $manager));
            } else {
                \Log::warning("⚠️ No manager found to notify for shop {$repairRequest->shop_owner_id}");
            }
            
            // Repair stays in 'assignment_failed' status - manual assignment required
            
        } catch (\Exception $e) {
            \Log::error("Failed to handle assignment failure for repair {$repairRequest->request_id}: " . $e->getMessage());
        }
    }

    /**
     * Simulate payment completion for testing (bypasses PayMongo)
     */
    public function simulatePayment(Request $request, $id)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $repair = RepairRequest::where('id', $id)
            ->forCustomer($user->id)
            ->first();

        if (!$repair) {
            return response()->json([
                'success' => false,
                'message' => 'Repair request not found'
            ], 404);
        }

        // Simulate payment completion
        $repair->update([
            'payment_status' => 'completed',
            'paymongo_payment_id' => 'TEST_PAYMENT_' . time(),
            'payment_completed_at' => now(),
        ]);

        // Determine next status based on approval requirements (same logic as webhook)
        if ($repair->is_high_value && $repair->requires_owner_approval) {
            // High-value repairs need owner approval before work begins
            $repair->update([
                'status' => 'owner_approval_pending',
            ]);
        } else {
            // Regular repairs can start work immediately after payment
            $repair->update([
                'status' => 'pending',
            ]);
        }

        \Log::info('Test payment simulated for repair: ' . $repair->request_id);

        return response()->json([
            'success' => true,
            'message' => 'Payment simulated successfully',
            'data' => $repair->fresh(['services', 'shopOwner', 'repairer'])
        ]);
    }
}
