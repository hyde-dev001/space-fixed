<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\RepairService;
use App\Models\RepairPackage;
use App\Models\ShopOwner;
use App\Services\NotificationService;
use App\Services\ShopOwnerApprovalPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

class RepairServiceController extends Controller
{
    protected NotificationService $notificationService;
    protected ShopOwnerApprovalPolicyService $shopOwnerApprovalPolicyService;

    public function __construct(
        NotificationService $notificationService,
        ShopOwnerApprovalPolicyService $shopOwnerApprovalPolicyService
    )
    {
        $this->notificationService = $notificationService;
        $this->shopOwnerApprovalPolicyService = $shopOwnerApprovalPolicyService;
    }

    /**
     * Display a listing of the repair services.
     */
    public function index(Request $request)
    {
        $query = RepairService::query();

        // Public/customer browsing path: explicit shop filter from query string.
        // This is used by the repair booking flow (/repair-process?shop=...).
        if ($request->filled('shop_id')) {
            $query->where('shop_owner_id', (int) $request->shop_id)
                ->whereIn('status', ['Active', 'active']);
        } else {
            // Backoffice path: scope by authenticated actor.
            // Filter by shop_owner_id based on authentication
            if (Auth::guard('shop_owner')->check()) {
                $query->where('shop_owner_id', Auth::guard('shop_owner')->id());
            } elseif (Auth::guard('user')->check()) {
                $user = Auth::guard('user')->user();
                if (!empty($user?->shop_owner_id)) {
                    $query->where('shop_owner_id', $user->shop_owner_id);
                }
            }
        }

        // Archived listing is backoffice-only; customer browsing (`shop_id`) always stays active-only.
        if ($request->boolean('archived') && !$request->filled('shop_id')) {
            $query->onlyTrashed();
        }

        // Filter by status if provided
        if ($request->has('status') && $request->status !== 'all') {
            $normalizedStatus = $this->normalizeServiceStatus((string) $request->status);

            if ($normalizedStatus === 'Active') {
                $query->whereIn('status', ['Active', 'active']);
            } elseif ($normalizedStatus !== null) {
                $query->where('status', $normalizedStatus);
            } else {
                $query->where('status', $request->status);
            }
        }

        // Filter by category if provided
        if ($request->has('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        // Search by name or category
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%");
            });
        }

        $services = $query
            ->with(['materialTemplateItems.inventoryItem:id,name'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (RepairService $service) {
                $payload = $service->toArray();

                $payload['material_templates'] = $service->materialTemplateItems
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'inventory_item_id' => (int) $item->inventory_item_id,
                            'inventory_item_name' => $item->inventoryItem?->name,
                            'default_quantity' => (float) $item->default_quantity,
                            'is_critical' => (bool) $item->is_critical,
                            'tolerance_percent' => (float) ($item->tolerance_percent ?? 20),
                        ];
                    })
                    ->values();

                unset($payload['material_template_items']);

                return $payload;
            })
            ->values();

        $shopPayload = null;
        if ($request->filled('shop_id')) {
            $shopOwner = ShopOwner::query()
                ->select(
                    'id',
                    'business_name',
                    'business_address',
                    'shop_address',
                    'city_state',
                    'postal_code',
                    'country',
                    'shop_latitude',
                    'shop_longitude'
                )
                ->find((int) $request->shop_id);

            if ($shopOwner) {
                $primaryAddress = trim((string) ($shopOwner->business_address ?: $shopOwner->shop_address ?: ''));
                $locationSuffix = trim((string) (($shopOwner->city_state ?? '') . (($shopOwner->city_state && $shopOwner->country) ? ', ' : '') . ($shopOwner->country ?? '')));
                $location = $primaryAddress !== '' ? $primaryAddress : ($locationSuffix !== '' ? $locationSuffix : 'Location not specified');

                $shopPayload = [
                    'id' => $shopOwner->id,
                    'name' => $shopOwner->business_name,
                    'address' => $primaryAddress,
                    'shop_address' => $shopOwner->shop_address,
                    'business_address' => $shopOwner->business_address,
                    'city_state' => $shopOwner->city_state,
                    'postal_code' => $shopOwner->postal_code,
                    'country' => $shopOwner->country,
                    'latitude' => $shopOwner->shop_latitude,
                    'longitude' => $shopOwner->shop_longitude,
                    'location' => $location,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $services,
            'shop' => $shopPayload,
        ]);
    }

    /**
     * Store a newly created repair service in storage.
     */
    public function store(Request $request)
    {
        $normalizedInputStatus = $this->normalizeServiceStatus($request->input('status'));
        if ($normalizedInputStatus !== null) {
            $request->merge(['status' => $normalizedInputStatus]);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'duration' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:Active,Inactive,Pending',
            'material_templates' => 'required|array|min:1',
            'material_templates.*.inventory_item_id' => 'required|integer|exists:inventory_items,id',
            'material_templates.*.default_quantity' => 'required|integer|min:1',
            'material_templates.*.is_critical' => 'required|boolean',
            'material_templates.*.tolerance_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Determine shop_owner_id and created_by based on authentication
        $shopOwnerId = null;
        $createdBy = null;
        
        // Check if authenticated as shop owner
        if (Auth::guard('shop_owner')->check()) {
            $shopOwnerId = Auth::guard('shop_owner')->id();
        } elseif (Auth::guard('user')->check()) {
            $user = Auth::guard('user')->user();
            $createdBy = $user->id;
            $shopOwnerId = $user->shop_owner_id;
        }

        if (!$shopOwnerId) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to resolve shop owner context for this service.',
                'errors' => [
                    'shop_owner_id' => ['A repair shop owner context is required before uploading services.'],
                ],
            ], 422);
        }

        $service = RepairService::create([
            'name' => $request->name,
            'category' => $request->category,
            'price' => $request->price,
            'duration' => $request->duration,
            'description' => $request->description,
            'status' => $normalizedInputStatus ?? 'Active',
            'shop_owner_id' => $shopOwnerId,
            'created_by' => $createdBy,
        ]);

        try {
            $this->syncMaterialTemplates($service, (array) $request->input('material_templates', []));
        } catch (ValidationException $e) {
            $service->delete();

            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Service created successfully',
            'data' => $service,
        ], 201);
    }

    /**
     * Display the specified repair service.
     */
    public function show($id)
    {
        $service = RepairService::find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $service,
        ]);
    }

    /**
     * Update the specified repair service in storage.
     * Price changes require approval workflow (Finance → Shop Owner).
     */
    public function update(Request $request, $id)
    {
        $service = RepairService::find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        $normalizedInputStatus = $this->normalizeServiceStatus($request->input('status'));
        if ($normalizedInputStatus !== null) {
            $request->merge(['status' => $normalizedInputStatus]);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'category' => 'sometimes|string|max:255',
            'price' => 'sometimes|numeric|min:0',
            'duration' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'reason' => 'sometimes|string|max:1000',
            'status' => 'sometimes|in:Active,Inactive,Pending,Under Review,Rejected',
            'rejection_reason' => 'nullable|string',
            'material_templates' => 'sometimes|array|min:1',
            'material_templates.*.inventory_item_id' => 'required|integer|exists:inventory_items,id',
            'material_templates.*.default_quantity' => 'required|integer|min:1',
            'material_templates.*.is_critical' => 'required|boolean',
            'material_templates.*.tolerance_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Determine baseline current price for workflow.
        // If a record is already in review and old_price exists, old_price is the authoritative current price.
        $baselineCurrentPrice = $this->resolveBaselineCurrentPrice($service);
        $isIndividualShop = $this->isIndividualRegistrationShop((int) $service->shop_owner_id);

        // Check if price is being changed against baseline current price
        $isPriceChange = $request->filled('price') && (float)$request->price !== $baselineCurrentPrice;

        if ($isPriceChange && $isIndividualShop) {
            $updateData = $request->only(['name', 'category', 'price', 'duration', 'description']);

            if ($request->filled('status')) {
                $updateData['status'] = $normalizedInputStatus ?? $request->status;
            } elseif (in_array($service->status, ['Under Review', 'Pending Owner Approval', 'Pending Finance Final Approval', 'Rejected'], true)) {
                $updateData['status'] = 'Active';
            }

            if ($request->filled('reason')) {
                $updateData['change_reason'] = $request->reason;
            }

            $updateData['updated_by'] = $this->resolveUpdaterUserId();

            // Individual shops do not need price-approval workflow metadata.
            $updateData['old_price'] = null;
            $updateData['finance_notes'] = null;
            $updateData['finance_reviewed_by'] = null;
            $updateData['finance_reviewed_at'] = null;
            $updateData['owner_reviewed_by'] = null;
            $updateData['owner_reviewed_at'] = null;
            $updateData['rejection_reason'] = null;

            $service->update($updateData);

            if ($request->has('material_templates')) {
                try {
                    $this->syncMaterialTemplates($service, (array) $request->input('material_templates', []));
                } catch (ValidationException $e) {
                    return response()->json([
                        'success' => false,
                        'errors' => $e->errors(),
                    ], 422);
                }
            }

            activity()
                ->causedBy(Auth::guard('user')->user() ?? Auth::guard('shop_owner')->user())
                ->performedOn($service)
                ->withProperties([
                    'service_name' => $service->name,
                    'old_price' => $baselineCurrentPrice,
                    'new_price' => (float) $service->price,
                    'updated_fields' => array_keys($updateData),
                ])
                ->log('Repair service price updated immediately for individual shop owner');

            return response()->json([
                'success' => true,
                'message' => 'Service updated successfully',
                'data' => $service,
            ]);
        }

        if ($isPriceChange) {
            $proposedPrice = (float) $request->price;
            $requiresOwnerApproval = $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForPriceChange(
                (int) $service->shop_owner_id,
                (float) $baselineCurrentPrice,
                $proposedPrice
            );

            // Price change requires approval workflow
            // Store current list price as old_price baseline.
            // The actual price will only be updated after final Finance approval.
            $service->update([
                'old_price' => $baselineCurrentPrice, // Keep current list price as old_price baseline
                // Never apply proposed price here; keep list price unchanged until final finance approval.
                'price' => $baselineCurrentPrice,
                'status' => 'Under Review',
                'description' => $request->description ?? $service->description,
                'change_reason' => $request->reason ?? $service->change_reason,
                'duration' => $request->duration ?? $service->duration,
                'updated_by' => $this->resolveUpdaterUserId(),
                'finance_reviewed_by' => null,
                'finance_reviewed_at' => null,
                'owner_reviewed_by' => null,
                'owner_reviewed_at' => null,
                'rejection_reason' => null,
            ]);
            
            // Store the proposed price in finance_notes as temporary storage for approval workflow
            $service->finance_notes = (string) $proposedPrice;
            $service->save();

            if ($request->has('material_templates')) {
                try {
                    $this->syncMaterialTemplates($service, (array) $request->input('material_templates', []));
                } catch (ValidationException $e) {
                    return response()->json([
                        'success' => false,
                        'errors' => $e->errors(),
                    ], 422);
                }
            }

            // Activity log for price change request
            activity()
                ->causedBy(Auth::guard('user')->user() ?? Auth::guard('shop_owner')->user())
                ->performedOn($service)
                ->withProperties([
                    'service_name' => $service->name,
                    'category' => $service->category,
                    'current_price' => (float)$service->old_price,
                    'proposed_price' => $proposedPrice,
                    'reason' => $request->reason ?? 'Price update',
                    'requires_owner_approval' => $requiresOwnerApproval,
                    'requested_by' => Auth::guard('user')->user()?->name ?? Auth::guard('shop_owner')->user()->name,
                ])
                ->log('Repair service price change requested - Awaiting Finance approval');

            return response()->json([
                'success' => true,
                'message' => 'Price change request submitted for approval. Finance will review shortly.',
                'data' => $service,
            ]);
        }

        // Non-price updates can be applied directly
        $updateData = $request->only(['name', 'category', 'duration', 'description']);
        if ($request->filled('status')) {
            $updateData['status'] = $normalizedInputStatus ?? $request->status;
        }
        $updateData['updated_by'] = $this->resolveUpdaterUserId();

        $service->update($updateData);

        if ($request->has('material_templates')) {
            try {
                $this->syncMaterialTemplates($service, (array) $request->input('material_templates', []));
            } catch (ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'errors' => $e->errors(),
                ], 422);
            }
        }

        // Activity log for non-price updates
        activity()
            ->causedBy(Auth::guard('user')->user() ?? Auth::guard('shop_owner')->user())
            ->performedOn($service)
            ->withProperties([
                'service_name' => $service->name,
                'updated_fields' => array_keys($updateData),
            ])
            ->log('Repair service updated');

        return response()->json([
            'success' => true,
            'message' => 'Service updated successfully',
            'data' => $service,
        ]);
    }

    /**
     * Remove the specified repair service from storage.
     */
    public function destroy($id)
    {
        $shopOwnerId = $this->resolveActingShopOwnerId();
        if (!$shopOwnerId) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to resolve shop owner context.',
            ], 403);
        }

        $service = RepairService::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Service not found',
            ], 404);
        }

        // Store service details before deletion for logging
        $serviceDetails = [
            'service_name' => $service->name,
            'category' => $service->category,
            'price' => $service->price,
            'duration' => $service->duration,
            'status' => $service->status,
        ];

        // Activity log before deletion
        activity()
            ->causedBy(Auth::guard('user')->user() ?? Auth::guard('shop_owner')->user())
            ->performedOn($service)
            ->withProperties($serviceDetails)
            ->log('Repair service archived');

        $service->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service archived successfully',
        ]);
    }

    /**
     * Restore archived repair service.
     */
    public function restore($id)
    {
        $shopOwnerId = $this->resolveActingShopOwnerId();
        if (!$shopOwnerId) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to resolve shop owner context.',
            ], 403);
        }

        $service = RepairService::withTrashed()
            ->where('shop_owner_id', $shopOwnerId)
            ->onlyTrashed()
            ->find($id);

        if (!$service) {
            return response()->json([
                'success' => false,
                'message' => 'Archived service not found',
            ], 404);
        }

        $service->restore();

        activity()
            ->causedBy(Auth::guard('user')->user() ?? Auth::guard('shop_owner')->user())
            ->performedOn($service)
            ->withProperties([
                'service_name' => $service->name,
                'category' => $service->category,
                'restored_by' => Auth::guard('user')->user()?->name ?? Auth::guard('shop_owner')->user()?->name,
            ])
            ->log('Repair service restored');

        return response()->json([
            'success' => true,
            'message' => 'Service restored successfully',
            'data' => $service,
        ]);
    }

    /**
     * Get services pending finance approval
     */
    public function financePending(Request $request)
    {
        $query = RepairService::with(['creator', 'updater', 'financeReviewer', 'ownerReviewer']);

        // Filter by shop_owner_id
        if (Auth::guard('user')->check()) {
            $user = Auth::guard('user')->user();
            $query->where('shop_owner_id', $user->shop_owner_id);
        }

        // Always return all statuses for metrics calculation on frontend
        // The frontend will handle filtering for display
        $query->whereIn('status', ['Under Review', 'Pending Owner Approval', 'Pending Finance Final Approval', 'Active', 'Rejected']);

        $services = $query->orderBy('updated_at', 'desc')->get();

        // Transform services data to match frontend expectations
        $transformedServices = $services->map(function(RepairService $service) {
            // Determine the status based on backend status
            $frontendStatus = 'pending';
            if ($service->status === 'Under Review') {
                $frontendStatus = 'pending';
            } elseif ($service->status === 'Pending Owner Approval') {
                $frontendStatus = 'finance_approved';
            } elseif ($service->status === 'Pending Finance Final Approval') {
                $frontendStatus = 'owner_approved'; // Owner approved, awaiting finance final
            } elseif ($service->status === 'Active' && $service->finance_reviewed_at) {
                // Active after approval workflow (either finance-only or full finance-owner-finance)
                $frontendStatus = 'owner_approved';
            } elseif ($service->status === 'Active' && !$service->finance_reviewed_at) {
                // Active services that were never reviewed (newly created) - skip these
                return null;
            } elseif ($service->status === 'Rejected' && $service->finance_reviewed_at && !$service->owner_reviewed_at) {
                $frontendStatus = 'finance_rejected';
            } elseif ($service->status === 'Rejected' && $service->owner_reviewed_at && !($service->status === 'Pending Finance Final Approval')) {
                $frontendStatus = 'owner_rejected';
            }

            // Get proposed price from finance_notes (temporary storage) if in approval workflow
            $proposedPrice = ($service->status === 'Under Review' || $service->status === 'Pending Owner Approval' || $service->status === 'Pending Finance Final Approval' || ($service->status === 'Rejected' && $service->finance_reviewed_at))
                ? $this->resolveProposedPrice($service)
                : (float)$service->price;
            $requiresOwnerApproval = $this->requiresOwnerApprovalForService($service, is_numeric($proposedPrice) ? (float) $proposedPrice : null);

            return [
                'id' => $service->id,
                'service_name' => $service->name,
                'category' => $service->category,
                'current_price' => (float)$service->old_price,
                'proposed_price' => $proposedPrice,
                'duration' => $service->duration,
                'reason' => $this->resolveChangeReason($service),
                'status' => $frontendStatus,
                'raw_status' => $service->status,
                'requires_owner_approval' => $requiresOwnerApproval,
                'created_at' => $service->created_at,
                'updated_at' => $service->updated_at,
                'type' => 'service',
                'requester' => $service->updater ? [
                    'id' => $service->updater->id,
                    'name' => $service->updater->name,
                ] : ($service->creator ? [
                    'id' => $service->creator->id,
                    'name' => $service->creator->name,
                ] : null),
                'finance_reviewer' => $service->financeReviewer ? [
                    'id' => $service->financeReviewer->id,
                    'name' => $service->financeReviewer->name,
                ] : null,
                'finance_notes' => $service->finance_notes,
                'finance_reviewed_at' => $service->finance_reviewed_at,
                'finance_rejection_reason' => $frontendStatus === 'finance_rejected' ? $service->rejection_reason : null,
                'owner_reviewer' => $service->ownerReviewer ? [
                    'id' => $service->ownerReviewer->id,
                    'name' => $service->ownerReviewer->name,
                ] : null,
                'owner_reviewed_at' => $service->owner_reviewed_at,
                'owner_rejection_reason' => $frontendStatus === 'owner_rejected' ? $service->rejection_reason : null,
            ];
        })->filter(); // Remove null values (newly created Active services)

        // Fetch packages with active approval requests
        $packageQuery = RepairPackage::with(['creator', 'updater', 'financeReviewer', 'ownerReviewer', 'services'])
            ->whereNotNull('approval_status')
            ->where('approval_status', '!=', 'none');

        // Use same shop filtering as services if authenticated user
        if (Auth::guard('user')->check()) {
            $user = Auth::guard('user')->user();
            if (!empty($user?->shop_owner_id)) {
                $packageQuery->where('shop_owner_id', $user->shop_owner_id);
            }
        }

        $packages = $packageQuery->orderBy('updated_at', 'desc')->get();

        $transformedPackages = $packages->map(function(RepairPackage $package) {
            try {
                $requiresOwnerApproval = $this->requiresOwnerApprovalForPackage($package);
                // Map approval_status to frontend status format
                $frontendStatus = match($package->approval_status) {
                    'pending_finance' => 'pending',
                    'finance_approved' => 'finance_approved',
                    'pending_owner' => 'finance_approved',
                    'owner_approved' => 'owner_approved',
                    'finalized' => 'owner_approved',
                    'finance_rejected' => 'finance_rejected',
                    'owner_rejected' => 'owner_rejected',
                    default => 'pending',
                };

                $rawStatus = match($package->approval_status) {
                    'owner_approved' => 'Pending Finance Final Approval',
                    'finalized' => 'Active',
                    default => $package->approval_status,
                };

                return [
                    'id' => $package->id,
                    'service_name' => $package->name,
                    'category' => 'Package',
                    'current_price' => (float)($package->old_package_price ?? $package->package_price),
                    'proposed_price' => (float)$package->package_price,
                    'duration' => $package->services ? ($package->services->count() . ' services') : '0 services',
                    'reason' => $package->change_reason ?? 'Price adjustment',
                    'status' => $frontendStatus,
                    'raw_status' => $rawStatus,
                    'requires_owner_approval' => $requiresOwnerApproval,
                    'request_type' => 'package',
                    'created_at' => $package->created_at,
                    'updated_at' => $package->updated_at,
                    'type' => 'package',
                    'requester' => $package->updater ? [
                        'id' => $package->updater->id,
                        'name' => $package->updater->name,
                    ] : ($package->creator ? [
                        'id' => $package->creator->id,
                        'name' => $package->creator->name,
                    ] : null),
                    'finance_reviewer' => $package->financeReviewer ? [
                        'id' => $package->financeReviewer->id,
                        'name' => $package->financeReviewer->name,
                    ] : null,
                    'finance_notes' => $package->finance_notes,
                    'finance_reviewed_at' => $package->finance_reviewed_at,
                    'finance_rejection_reason' => $frontendStatus === 'finance_rejected' ? $package->finance_notes : null,
                    'owner_reviewer' => $package->ownerReviewer ? [
                        'id' => $package->ownerReviewer->id,
                        'name' => $package->ownerReviewer->name,
                    ] : null,
                    'owner_reviewed_at' => $package->owner_reviewed_at,
                    'owner_rejection_reason' => $frontendStatus === 'owner_rejected' ? $package->owner_notes : null,
                ];
            } catch (\Exception $e) {
                report($e);
                return null;
            }
        })->filter(); // Remove null values

        // Combine services and packages, then sort by updated_at descending
        $combinedData = $transformedServices->concat($transformedPackages)
            ->sortByDesc('updated_at')
            ->values();

        return response()->json([
            'success' => true,
            'data' => $combinedData,
        ]);
    }

    /**
     * Finance approves a service or package price change
     */
    public function financeApprove(Request $request, $id)
    {
        $requestType = strtolower((string) $request->input('request_type', ''));

        if ($requestType === 'package') {
            $package = RepairPackage::find($id);
            if (!$package) {
                return response()->json([
                    'success' => false,
                    'message' => 'Package not found',
                ], 404);
            }

            return $this->financeApprovePackage($request, $package);
        }

        // Try to find as a service first, then fallback to package for backward compatibility.
        $service = RepairService::find($id);
        if (!$service) {
            $package = RepairPackage::find($id);
            if (!$package) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service or package not found',
                ], 404);
            }

            return $this->financeApprovePackage($request, $package);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $proposedPrice = $this->resolveProposedPrice($service);

        if ($proposedPrice === null) {
            return response()->json([
                'success' => false,
                'message' => 'No proposed price found for this request. Please resubmit the price change request.',
            ], 422);
        }

        $requiresOwnerApproval = $this->requiresOwnerApprovalForService($service, (float) $proposedPrice);

        if ($requiresOwnerApproval) {
            $service->update([
                'status' => 'Pending Owner Approval',
                // Keep proposed price in finance_notes so it survives the full workflow.
                'finance_notes' => $proposedPrice,
                'finance_reviewed_by' => Auth::guard('user')->id(),
                'finance_reviewed_at' => now(),
            ]);
        } else {
            $service->update([
                'status' => 'Active',
                'price' => $proposedPrice,
                'finance_notes' => $request->notes,
                'finance_reviewed_by' => Auth::guard('user')->id(),
                'finance_reviewed_at' => now(),
            ]);
        }

        // Activity log with business context
        activity()
            ->causedBy(Auth::guard('user')->user())
            ->performedOn($service)
            ->withProperties([
                'service_name' => $service->name,
                'category' => $service->category,
                'current_price' => (float)$service->old_price,
                'proposed_price' => (float) $proposedPrice,
                'finance_notes' => $request->notes,
                'approved_by_name' => Auth::guard('user')->user()->name,
                'approved_by_role' => Auth::guard('user')->user()->role ?? 'Finance Staff',
            ])
            ->log($requiresOwnerApproval
                ? 'Repair service price change approved by Finance - Forwarded to Shop Owner'
                : 'Repair service price change approved by Finance - Price applied (Owner approval not required)');

        if ($requiresOwnerApproval) {
            // Notify shop owner about repair service price approval
            $notificationService = app(NotificationService::class);
            $notificationService->notifyRepairServiceRequest($service->shop_owner_id, [
                'service_name' => $service->name,
                'current_price' => number_format($service->old_price, 2),
                'proposed_price' => number_format((float) $proposedPrice, 2),
                'service_id' => $service->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $requiresOwnerApproval
                ? 'Service approved by Finance. Pending Shop Owner approval.'
                : 'Service approved by Finance and applied. Owner approval not required by settings.',
            'data' => $service,
        ]);
    }

    /**
     * Finance approves a package price change
     */
    private function financeApprovePackage(Request $request, RepairPackage $package)
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $requiresOwnerApproval = $this->requiresOwnerApprovalForPackage($package);

        if ($requiresOwnerApproval) {
            $package->update([
                'approval_status' => 'finance_approved',
                'finance_reviewed_by' => Auth::guard('user')->id(),
                'finance_reviewed_at' => now(),
                'finance_notes' => $request->notes,
                'approval_workflow_version' => 'repair_finance_owner_finance',
                'current_approval_level' => 2,
            ]);
        } else {
            $package->update([
                'approval_status' => 'finalized',
                'finance_reviewed_by' => Auth::guard('user')->id(),
                'finance_reviewed_at' => now(),
                'finance_notes' => $request->notes,
                'approval_workflow_version' => 'repair_finance_only',
                'current_approval_level' => 1,
            ]);
        }

        // Activity log with business context
        activity()
            ->causedBy(Auth::guard('user')->user())
            ->performedOn($package)
            ->withProperties([
                'package_name' => $package->name,
                'current_price' => (float)$package->old_package_price,
                'proposed_price' => (float)$package->package_price,
                'finance_notes' => $request->notes,
                'approved_by_name' => Auth::guard('user')->user()->name,
                'approved_by_role' => Auth::guard('user')->user()->role ?? 'Finance Staff',
            ])
            ->log($requiresOwnerApproval
                ? 'Repair package price change approved by Finance - Forwarded to Shop Owner'
                : 'Repair package price change approved by Finance - Price applied (Owner approval not required)');

        if ($requiresOwnerApproval) {
            // Notify shop owner about repair package price approval
            $notificationService = app(NotificationService::class);
            $notificationService->notifyRepairServiceRequest($package->shop_owner_id, [
                'service_name' => $package->name . ' (Package)',
                'current_price' => number_format($package->old_package_price, 2),
                'proposed_price' => number_format((float) $package->package_price, 2),
                'package_id' => $package->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $requiresOwnerApproval
                ? 'Package price change approved by Finance. Pending Shop Owner approval.'
                : 'Package price change approved by Finance and applied. Owner approval not required by settings.',
            'data' => $package,
        ]);
    }

    /**
     * Finance rejects a service or package price change
     */
    public function financeReject(Request $request, $id)
    {
        $requestType = strtolower((string) $request->input('request_type', ''));

        if ($requestType === 'package') {
            $package = RepairPackage::find($id);
            if (!$package) {
                return response()->json([
                    'success' => false,
                    'message' => 'Package not found',
                ], 404);
            }

            return $this->financeRejectPackage($request, $package);
        }

        // Try to find as a service first, then fallback to package for backward compatibility.
        $service = RepairService::find($id);
        if (!$service) {
            $package = RepairPackage::find($id);
            if (!$package) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service or package not found',
                ], 404);
            }

            return $this->financeRejectPackage($request, $package);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $proposedPrice = $service->finance_notes;

        $service->update([
            'status' => 'Rejected',
            'rejection_reason' => $request->reason,
            'finance_reviewed_by' => Auth::guard('user')->id(),
            'finance_reviewed_at' => now(),
        ]);

        // Activity log with rejection reason
        activity()
            ->causedBy(Auth::guard('user')->user())
            ->performedOn($service)
            ->withProperties([
                'service_name' => $service->name,
                'category' => $service->category,
                'current_price' => (float)$service->old_price,
                'proposed_price' => (float)$proposedPrice,
                'rejection_reason' => $request->reason,
                'rejected_by_name' => Auth::guard('user')->user()->name,
                'rejected_by_role' => Auth::guard('user')->user()->role ?? 'Finance Staff',
            ])
            ->log('Repair service price change rejected by Finance');

        // Send rejection notification to shop owner
        $this->notificationService->notifyRepairServiceRejected($service->shop_owner_id, [
            'service_name' => $service->name,
            'old_price' => (float)$service->old_price,
            'price' => (float)$proposedPrice,
            'rejection_reason' => $request->reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service price change rejected by Finance.',
            'data' => $service,
        ]);
    }

    /**
     * Finance rejects a package price change
     */
    private function financeRejectPackage(Request $request, RepairPackage $package)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $package->update([
            'approval_status' => 'finance_rejected',
            'finance_reviewed_by' => Auth::guard('user')->id(),
            'finance_reviewed_at' => now(),
            'finance_notes' => $request->reason,
        ]);

        // Activity log with rejection reason
        activity()
            ->causedBy(Auth::guard('user')->user())
            ->performedOn($package)
            ->withProperties([
                'package_name' => $package->name,
                'current_price' => (float)$package->old_package_price,
                'proposed_price' => (float)$package->package_price,
                'rejection_reason' => $request->reason,
                'rejected_by_name' => Auth::guard('user')->user()->name,
                'rejected_by_role' => Auth::guard('user')->user()->role ?? 'Finance Staff',
            ])
            ->log('Repair package price change rejected by Finance');

        // Send rejection notification to shop owner
        $this->notificationService->notifyRepairServiceRejected($package->shop_owner_id, [
            'service_name' => $package->name . ' (Package)',
            'old_price' => (float)$package->old_package_price,
            'price' => (float)$package->package_price,
            'rejection_reason' => $request->reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Package price change rejected by Finance.',
            'data' => $package,
        ]);
    }

    /**
     * Get services pending owner approval
     */
    public function ownerPending()
    {
        $shopOwnerId = Auth::guard('shop_owner')->id();

        $services = RepairService::where('status', 'Pending Owner Approval')
            ->where('shop_owner_id', $shopOwnerId)
            ->with(['creator', 'updater', 'financeReviewer', 'ownerReviewer'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $packages = RepairPackage::whereIn('approval_status', ['finance_approved', 'pending_owner'])
            ->where('shop_owner_id', $shopOwnerId)
            ->with(['creator', 'updater', 'financeReviewer', 'ownerReviewer', 'services'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $serviceRows = $services->map(function (RepairService $service) {
            return [
                'id' => $service->id,
                'name' => $service->name,
                'category' => $service->category,
                'old_price' => (float) ($service->old_price ?? $service->price),
                'price' => (float) ($this->resolveProposedPrice($service) ?? $service->price),
                'change_reason' => $this->resolveChangeReason($service),
                'status' => $service->status,
                'mapped_status' => 'finance_approved',
                'approval_workflow_version' => 'v4_multi_level',
                'current_approval_level' => 2,
                'is_intermediate_approval' => true,
                'request_type' => 'service',
                'created_at' => $service->created_at,
                'updated_at' => $service->updated_at,
                'creator' => $service->creator,
                'updater' => $service->updater,
                'financeReviewer' => $service->financeReviewer,
                'ownerReviewer' => $service->ownerReviewer,
                'finance_notes' => $service->finance_notes,
                'finance_reviewed_at' => $service->finance_reviewed_at,
                'owner_reviewed_at' => $service->owner_reviewed_at,
                'rejection_reason' => $service->rejection_reason,
            ];
        });

        $packageRows = $packages->map(function (RepairPackage $package) {
            return [
                'id' => $package->id,
                'name' => $package->name,
                'category' => 'Package',
                'old_price' => (float) ($package->old_package_price ?? $package->package_price),
                'price' => (float) $package->package_price,
                'change_reason' => $package->change_reason ?? 'Package price update request',
                'status' => $package->approval_status,
                'mapped_status' => 'finance_approved',
                'approval_workflow_version' => 'v4_multi_level',
                'current_approval_level' => 2,
                'is_intermediate_approval' => true,
                'request_type' => 'package',
                'created_at' => $package->created_at,
                'updated_at' => $package->updated_at,
                'creator' => $package->creator,
                'updater' => $package->updater,
                'financeReviewer' => $package->financeReviewer,
                'ownerReviewer' => $package->ownerReviewer,
                'finance_notes' => $package->finance_notes,
                'finance_reviewed_at' => $package->finance_reviewed_at,
                'owner_reviewed_at' => $package->owner_reviewed_at,
                'rejection_reason' => $package->owner_notes,
            ];
        });

        $data = $serviceRows->concat($packageRows)->sortByDesc('updated_at')->values();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get all services for owner review (pending + approved + rejected)
     */
    public function ownerAll()
    {
        $shopOwnerId = Auth::guard('shop_owner')->id();

        $services = RepairService::whereIn('status', ['Pending Owner Approval', 'Pending Finance Final Approval', 'Active', 'Rejected'])
            ->where('shop_owner_id', $shopOwnerId)
            ->with(['creator', 'updater', 'approval', 'financeReviewer', 'ownerReviewer'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $packages = RepairPackage::whereIn('approval_status', [
                'pending_finance',
                'finance_approved',
                'pending_owner',
                'owner_approved',
                'owner_rejected',
                'finance_rejected',
                'finalized',
            ])
            ->where('shop_owner_id', $shopOwnerId)
            ->with(['creator', 'updater', 'financeReviewer', 'ownerReviewer', 'services'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $serviceRows = $services->map(function (RepairService $service) {
            $statusMap = [
                'Under Review' => 'pending',
                'Pending Owner Approval' => 'finance_approved',
                'Pending Finance Final Approval' => 'pending_finance_final',
                'Active' => 'owner_approved',
                'Rejected' => $service->finance_reviewed_at
                    ? ($service->owner_reviewed_at ? 'owner_rejected' : 'finance_rejected')
                    : 'owner_rejected',
            ];

            return [
                'id' => $service->id,
                'name' => $service->name,
                'category' => $service->category,
                'old_price' => (float) ($service->old_price ?? $service->price),
                'price' => (float) ($this->resolveProposedPrice($service) ?? $service->price),
                'change_reason' => $this->resolveChangeReason($service),
                'status' => $service->status,
                'mapped_status' => $statusMap[$service->status] ?? $service->status,
                'current_approval_level' => $service->status === 'Pending Owner Approval' ? 2 : 3,
                'approval_workflow_version' => 'v4_multi_level',
                'is_intermediate_approval' => $service->status === 'Pending Owner Approval',
                'request_type' => 'service',
                'created_at' => $service->created_at,
                'updated_at' => $service->updated_at,
                'creator' => $service->creator,
                'updater' => $service->updater,
                'financeReviewer' => $service->financeReviewer,
                'ownerReviewer' => $service->ownerReviewer,
                'finance_notes' => $service->finance_notes,
                'finance_reviewed_at' => $service->finance_reviewed_at,
                'owner_reviewed_at' => $service->owner_reviewed_at,
                'rejection_reason' => $service->rejection_reason,
            ];
        });

        $packageRows = $packages->map(function (RepairPackage $package) {
            $mappedStatus = match ($package->approval_status) {
                'pending_finance' => 'pending',
                'finance_approved', 'pending_owner' => 'finance_approved',
                'owner_approved' => 'pending_finance_final',
                'finalized' => 'owner_approved',
                'finance_rejected' => 'finance_rejected',
                'owner_rejected' => 'owner_rejected',
                default => 'pending',
            };

            $approvalLevel = match ($package->approval_status) {
                'pending_finance' => 1,
                'finance_approved', 'pending_owner' => 2,
                'owner_approved' => 3,
                default => null,
            };

            return [
                'id' => $package->id,
                'name' => $package->name,
                'category' => 'Package',
                'old_price' => (float) ($package->old_package_price ?? $package->package_price),
                'price' => (float) $package->package_price,
                'change_reason' => $package->change_reason ?? 'Package price update request',
                'status' => $package->approval_status,
                'mapped_status' => $mappedStatus,
                'current_approval_level' => $approvalLevel,
                'approval_workflow_version' => 'v4_multi_level',
                'is_intermediate_approval' => in_array($package->approval_status, ['finance_approved', 'pending_owner', 'owner_approved'], true),
                'request_type' => 'package',
                'created_at' => $package->created_at,
                'updated_at' => $package->updated_at,
                'creator' => $package->creator,
                'updater' => $package->updater,
                'financeReviewer' => $package->financeReviewer,
                'ownerReviewer' => $package->ownerReviewer,
                'finance_notes' => $package->finance_notes,
                'finance_reviewed_at' => $package->finance_reviewed_at,
                'owner_reviewed_at' => $package->owner_reviewed_at,
                'rejection_reason' => $package->owner_notes,
            ];
        });

        $data = $serviceRows->concat($packageRows)->sortByDesc('updated_at')->values();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Shop Owner approves and forwards back to Finance for final approval
     */
    public function ownerApprove(Request $request, $id)
    {
        $requestType = strtolower((string) $request->input('request_type', ''));

        if ($requestType === 'package') {
            $package = RepairPackage::find($id);
            if (!$package) {
                return response()->json([
                    'success' => false,
                    'message' => 'Package not found',
                ], 404);
            }

            return $this->ownerApprovePackage($package);
        }

        if ($requestType === 'service') {
            $service = RepairService::find($id);

            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not found',
                ], 404);
            }

            $proposedPrice = $service->finance_notes;

            $service->update([
                'status' => 'Pending Finance Final Approval',
                'owner_reviewed_by' => Auth::guard('shop_owner')->id(),
                'owner_reviewed_at' => now(),
            ]);

            activity()
                ->causedBy(Auth::guard('shop_owner')->user())
                ->performedOn($service)
                ->withProperties([
                    'service_name' => $service->name,
                    'category' => $service->category,
                    'current_price' => (float)$service->old_price,
                    'proposed_price' => (float)$proposedPrice,
                    'approved_by_name' => Auth::guard('shop_owner')->user()->name,
                    'approval_level' => 'Shop Owner',
                ])
                ->log('Repair service price change approved by Shop Owner - Forwarded to Finance for final approval');

            return response()->json([
                'success' => true,
                'message' => 'Service approved by Shop Owner. Forwarding to Finance for final approval.',
                'data' => $service,
            ]);
        }

        // Backward-compatible fallback when request_type is missing:
        // prefer service first to avoid ID collisions with packages.
        $service = RepairService::find($id);
        if ($service) {
            $proposedPrice = $service->finance_notes;

            $service->update([
                'status' => 'Pending Finance Final Approval',
                'owner_reviewed_by' => Auth::guard('shop_owner')->id(),
                'owner_reviewed_at' => now(),
            ]);

            activity()
                ->causedBy(Auth::guard('shop_owner')->user())
                ->performedOn($service)
                ->withProperties([
                    'service_name' => $service->name,
                    'category' => $service->category,
                    'current_price' => (float)$service->old_price,
                    'proposed_price' => (float)$proposedPrice,
                    'approved_by_name' => Auth::guard('shop_owner')->user()->name,
                    'approval_level' => 'Shop Owner',
                ])
                ->log('Repair service price change approved by Shop Owner - Forwarded to Finance for final approval');

            return response()->json([
                'success' => true,
                'message' => 'Service approved by Shop Owner. Forwarding to Finance for final approval.',
                'data' => $service,
            ]);
        }

        $package = RepairPackage::find($id);
        if ($package) {
            return $this->ownerApprovePackage($package);
        }

        return response()->json([
            'success' => false,
            'message' => 'Service or package not found',
        ], 404);
    }

    /**
     * Finance gives final approval and applies the price change
     */
    public function financeApproveFinal(Request $request, $id)
    {
        $requestType = strtolower((string) $request->input('request_type', ''));

        if ($requestType === 'package') {
            $package = RepairPackage::find($id);
            if (!$package) {
                return response()->json([
                    'success' => false,
                    'message' => 'Package not found',
                ], 404);
            }

            return $this->financeApproveFinalPackage($request, $package);
        }

        // Try to find as a service first, then fallback to package for backward compatibility.
        $service = RepairService::find($id);
        if (!$service) {
            $package = RepairPackage::find($id);
            if (!$package) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service or package not found',
                ], 404);
            }

            return $this->financeApproveFinalPackage($request, $package);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Apply the proposed price from finance_notes
        $proposedPrice = $this->resolveProposedPrice($service);

        if ($proposedPrice === null) {
            $recoveredProposedPrice = $this->recoverProposedPriceFromActivity($service);
            if ($recoveredProposedPrice !== null) {
                $proposedPrice = $recoveredProposedPrice;
                // Restore workflow state for this in-flight record.
                $service->finance_notes = (string) $recoveredProposedPrice;
                $service->save();
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot apply final approval because proposed price is missing.',
                ], 422);
            }
        }

        $service->update([
            'status' => 'Active',
            'price' => $proposedPrice, // Apply the price change now
            'finance_notes' => $request->notes,
            'finance_reviewed_by' => Auth::guard('user')->id(),
            'finance_reviewed_at' => now(),
        ]);

        // Activity log for final approval with price applied
        activity()
            ->causedBy(Auth::guard('user')->user())
            ->performedOn($service)
            ->withProperties([
                'service_name' => $service->name,
                'category' => $service->category,
                'old_price' => (float)$service->old_price,
                'new_price' => (float)$proposedPrice,
                'finance_notes' => $request->notes,
                'approved_by_name' => Auth::guard('user')->user()->name,
                'approval_level' => 'Finance (Final)',
            ])
            ->log('Repair service price change final approval by Finance - Price applied and activated');

        return response()->json([
            'success' => true,
            'message' => 'Service price change approved and applied.',
            'data' => $service,
        ]);
    }

    /**
     * Finance gives final approval to a package price change and applies the price
     */
    private function financeApproveFinalPackage(Request $request, RepairPackage $package)
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $package->update([
            'approval_status' => 'finalized',
            'finance_reviewed_by' => Auth::guard('user')->id(),
            'finance_reviewed_at' => now(),
            'finance_notes' => $request->notes,
        ]);

        // Activity log for final approval with price applied
        activity()
            ->causedBy(Auth::guard('user')->user())
            ->performedOn($package)
            ->withProperties([
                'package_name' => $package->name,
                'old_price' => (float)$package->old_package_price,
                'new_price' => (float)$package->package_price,
                'finance_notes' => $request->notes,
                'approved_by_name' => Auth::guard('user')->user()->name,
                'approval_level' => 'Finance (Final)',
            ])
            ->log('Repair package price change final approval by Finance - Price applied and activated');

        return response()->json([
            'success' => true,
            'message' => 'Package price change approved and applied.',
            'data' => $package,
        ]);
    }

    private function recoverProposedPriceFromActivity(RepairService $service): ?float
    {
        $activities = Activity::query()
            ->where('subject_type', RepairService::class)
            ->where('subject_id', $service->id)
            ->latest('id')
            ->limit(25)
            ->get();

        foreach ($activities as $activity) {
            $proposed = data_get($activity->properties, 'proposed_price');
            if (is_numeric($proposed)) {
                return (float) $proposed;
            }

            $newPrice = data_get($activity->properties, 'new_price');
            if (is_numeric($newPrice)) {
                return (float) $newPrice;
            }
        }

        return null;
    }

    private function resolveBaselineCurrentPrice(RepairService $service): float
    {
        // For in-flight approval rows, old_price is the authoritative current list price.
        if (
            in_array($service->status, ['Under Review', 'Pending Owner Approval', 'Pending Finance Final Approval'], true)
            && $service->old_price !== null
        ) {
            return (float) $service->old_price;
        }

        return (float) $service->price;
    }

    private function normalizeServiceStatus(mixed $status): ?string
    {
        if (!is_string($status)) {
            return null;
        }

        $normalized = strtolower(trim($status));

        return match ($normalized) {
            'active' => 'Active',
            'inactive' => 'Inactive',
            'pending' => 'Pending',
            'under review' => 'Under Review',
            'rejected' => 'Rejected',
            default => null,
        };
    }

    private function resolveActingShopOwnerId(): ?int
    {
        if (Auth::guard('shop_owner')->check()) {
            return (int) Auth::guard('shop_owner')->id();
        }

        if (Auth::guard('user')->check()) {
            $shopOwnerId = Auth::guard('user')->user()?->shop_owner_id;
            return $shopOwnerId ? (int) $shopOwnerId : null;
        }

        return null;
    }

    private function isIndividualRegistrationShop(int $shopOwnerId): bool
    {
        $shopOwner = ShopOwner::query()
            ->select('id', 'registration_type')
            ->find($shopOwnerId);

        return (bool) ($shopOwner && $shopOwner->isIndividual());
    }

    private function resolveUpdaterUserId(): ?int
    {
        // repair_services.updated_by is a foreign key to users.id
        $staffUserId = Auth::guard('user')->id();
        return $staffUserId ? (int) $staffUserId : null;
    }

    private function resolveProposedPrice(RepairService $service): ?float
    {
        if (is_numeric($service->finance_notes)) {
            return (float) $service->finance_notes;
        }

        // Recovery fallback for previously corrupted rows where proposed price was written to price
        // while old_price still holds the baseline current price.
        if (
            in_array($service->status, ['Under Review', 'Pending Owner Approval', 'Pending Finance Final Approval'], true)
            && $service->old_price !== null
            && (float) $service->price !== (float) $service->old_price
        ) {
            return (float) $service->price;
        }

        return null;
    }

    private function syncMaterialTemplates(RepairService $service, array $materialTemplates): void
    {
        foreach ($materialTemplates as $index => $line) {
            $inventoryItem = InventoryItem::query()
                ->where('id', (int) ($line['inventory_item_id'] ?? 0))
                ->where('shop_owner_id', $service->shop_owner_id)
                ->where('category', 'repair_materials')
                ->first();

            if (!$inventoryItem) {
                throw ValidationException::withMessages([
                    "material_templates.{$index}.inventory_item_id" => 'Selected inventory item must belong to this shop and be a repair material.',
                ]);
            }
        }

        $service->materialTemplateItems()->delete();

        $createdBy = Auth::guard('user')->id();

        collect($materialTemplates)->each(function (array $line) use ($service, $createdBy): void {
            $service->materialTemplateItems()->create([
                'shop_owner_id' => $service->shop_owner_id,
                'inventory_item_id' => (int) $line['inventory_item_id'],
                'template_type' => 'repair_service',
                'template_id' => $service->id,
                'default_quantity' => (int) $line['default_quantity'],
                'is_critical' => (bool) $line['is_critical'],
                'tolerance_percent' => (float) ($line['tolerance_percent'] ?? 20),
                'created_by' => $createdBy,
            ]);
        });
    }

    private function resolveChangeReason(RepairService $service): string
    {
        if (!empty($service->change_reason)) {
            return (string) $service->change_reason;
        }

        $activities = Activity::query()
            ->where('subject_type', RepairService::class)
            ->where('subject_id', $service->id)
            ->latest('id')
            ->limit(25)
            ->get();

        foreach ($activities as $activity) {
            $reason = data_get($activity->properties, 'reason');
            if (is_string($reason) && trim($reason) !== '') {
                return $reason;
            }
        }

        return (string) ($service->description ?? 'Price update request');
    }

    private function requiresOwnerApprovalForService(RepairService $service, ?float $proposedPrice = null): bool
    {
        $currentPrice = (float) ($service->old_price ?? $service->price);
        $targetPrice = $proposedPrice ?? $this->resolveProposedPrice($service) ?? (float) $service->price;

        return $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForPriceChange(
            (int) $service->shop_owner_id,
            $currentPrice,
            (float) $targetPrice
        );
    }

    private function requiresOwnerApprovalForPackage(RepairPackage $package): bool
    {
        $currentPrice = (float) ($package->old_package_price ?? $package->package_price);
        $proposedPrice = (float) $package->package_price;

        return $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForPriceChange(
            (int) $package->shop_owner_id,
            $currentPrice,
            $proposedPrice
        );
    }

    /**
     * Shop Owner rejects the service price change
     */
    public function ownerReject(Request $request, $id)
    {
        $requestType = strtolower((string) $request->input('request_type', ''));

        if ($requestType === 'package') {
            $package = RepairPackage::find($id);
            if (!$package) {
                return response()->json([
                    'success' => false,
                    'message' => 'Package not found',
                ], 404);
            }

            return $this->ownerRejectPackage($request, $package);
        }

        if ($requestType === 'service') {
            $service = RepairService::find($id);

            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not found',
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'reason' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $proposedPrice = $service->finance_notes;

            $service->update([
                'status' => 'Rejected',
                'rejection_reason' => $request->reason,
                'owner_reviewed_by' => Auth::guard('shop_owner')->id(),
                'owner_reviewed_at' => now(),
            ]);

            activity()
                ->causedBy(Auth::guard('shop_owner')->user())
                ->performedOn($service)
                ->withProperties([
                    'service_name' => $service->name,
                    'category' => $service->category,
                    'current_price' => (float)$service->old_price,
                    'proposed_price' => (float)$proposedPrice,
                    'rejection_reason' => $request->reason,
                    'rejected_by_name' => Auth::guard('shop_owner')->user()->name,
                    'rejection_level' => 'Shop Owner (Final)',
                ])
                ->log('Repair service price change rejected by Shop Owner (Final Decision)');

            $financeUsers = \App\Models\User::whereHas('roles', function ($q) {
                $q->where('name', 'Finance');
            })
            ->where('shop_owner_id', $service->shop_owner_id)
            ->get();

            foreach ($financeUsers as $financeUser) {
                $this->notificationService->notifyRepairServiceRejected($service->shop_owner_id, [
                    'service_name' => $service->name,
                    'old_price' => (float)$service->old_price,
                    'price' => (float)$proposedPrice,
                    'rejection_reason' => $request->reason,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Service price change rejected.',
                'data' => $service,
            ]);
        }

        // Backward-compatible fallback when request_type is missing:
        // prefer service first to avoid ID collisions with packages.
        $service = RepairService::find($id);
        if ($service) {
            $validator = Validator::make($request->all(), [
                'reason' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $proposedPrice = $service->finance_notes;

            $service->update([
                'status' => 'Rejected',
                'rejection_reason' => $request->reason,
                'owner_reviewed_by' => Auth::guard('shop_owner')->id(),
                'owner_reviewed_at' => now(),
            ]);

            activity()
                ->causedBy(Auth::guard('shop_owner')->user())
                ->performedOn($service)
                ->withProperties([
                    'service_name' => $service->name,
                    'category' => $service->category,
                    'current_price' => (float)$service->old_price,
                    'proposed_price' => (float)$proposedPrice,
                    'rejection_reason' => $request->reason,
                    'rejected_by_name' => Auth::guard('shop_owner')->user()->name,
                    'rejection_level' => 'Shop Owner (Final)',
                ])
                ->log('Repair service price change rejected by Shop Owner (Final Decision)');

            $financeUsers = \App\Models\User::whereHas('roles', function ($q) {
                $q->where('name', 'Finance');
            })
            ->where('shop_owner_id', $service->shop_owner_id)
            ->get();

            foreach ($financeUsers as $financeUser) {
                $this->notificationService->notifyRepairServiceRejected($service->shop_owner_id, [
                    'service_name' => $service->name,
                    'old_price' => (float)$service->old_price,
                    'price' => (float)$proposedPrice,
                    'rejection_reason' => $request->reason,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Service price change rejected.',
                'data' => $service,
            ]);
        }

        $package = RepairPackage::find($id);
        if ($package) {
            return $this->ownerRejectPackage($request, $package);
        }

        return response()->json([
            'success' => false,
            'message' => 'Service or package not found',
        ], 404);
    }

    private function ownerApprovePackage(RepairPackage $package)
    {
        if (!in_array($package->approval_status, ['finance_approved', 'pending_owner'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Package is not pending owner approval',
            ], 400);
        }

        $package->update([
            'approval_status' => 'owner_approved',
            'owner_reviewed_by' => Auth::guard('shop_owner')->id(),
            'owner_reviewed_at' => now(),
        ]);

        activity()
            ->causedBy(Auth::guard('shop_owner')->user())
            ->performedOn($package)
            ->withProperties([
                'package_name' => $package->name,
                'current_price' => (float)$package->old_package_price,
                'proposed_price' => (float)$package->package_price,
                'approved_by_name' => Auth::guard('shop_owner')->user()?->name,
                'approval_level' => 'Shop Owner',
            ])
            ->log('Repair package price change approved by Shop Owner - Forwarded to Finance for final approval');

        return response()->json([
            'success' => true,
            'message' => 'Package approved by Shop Owner. Forwarding to Finance for final approval.',
            'data' => $package,
        ]);
    }

    private function ownerRejectPackage(Request $request, RepairPackage $package)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $package->update([
            'approval_status' => 'owner_rejected',
            'owner_reviewed_by' => Auth::guard('shop_owner')->id(),
            'owner_reviewed_at' => now(),
            'owner_notes' => $request->reason,
        ]);

        activity()
            ->causedBy(Auth::guard('shop_owner')->user())
            ->performedOn($package)
            ->withProperties([
                'package_name' => $package->name,
                'current_price' => (float)$package->old_package_price,
                'proposed_price' => (float)$package->package_price,
                'rejection_reason' => $request->reason,
                'rejected_by_name' => Auth::guard('shop_owner')->user()?->name,
                'rejection_level' => 'Shop Owner (Final)',
            ])
            ->log('Repair package price change rejected by Shop Owner');

        return response()->json([
            'success' => true,
            'message' => 'Package price change rejected.',
            'data' => $package,
        ]);
    }
}
