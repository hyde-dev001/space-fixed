<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\RepairPackage;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Services\ShopOwnerApprovalPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RepairPackageController extends Controller
{
    private const REPAIR_VAT_RATE_PERCENT = 12.0;
    private const MATERIAL_TEMPLATE_TABLE = 'repair_material_template_items';

    public function __construct(private ShopOwnerApprovalPolicyService $shopOwnerApprovalPolicyService)
    {
    }

    public function publicIndex(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shop_id' => 'nullable|integer|exists:shop_owners,id',
            'search' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = RepairPackage::query()
            ->active()
            ->with([
                'services:id,name,category,price,duration,status,shop_owner_id',
                'materialTemplateItems:' . $this->materialTemplateItemSelectList(),
                'materialTemplateItems.inventoryItem:id,name,available_quantity',
            ]);

        if ($request->filled('shop_id')) {
            $query->where('shop_owner_id', (int) $request->shop_id);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $packages = $query->orderByDesc('created_at')->get()->map(function (RepairPackage $package) {
            $serviceTotal = $package->services->sum(fn ($service) => (float) $service->price);
            $effectivePrice = $this->resolveEffectivePackagePrice($package);

            return [
                'id' => $package->id,
                'shop_owner_id' => $package->shop_owner_id,
                'name' => $package->name,
                'description' => $package->description,
                'package_price' => $effectivePrice,
                'effective_package_price' => $effectivePrice,
                'proposed_package_price' => (float) $package->package_price,
                'old_package_price' => $package->old_package_price !== null ? (float) $package->old_package_price : null,
                'status' => $package->status,
                'approval_status' => $package->approval_status,
                'change_reason' => $package->change_reason,
                'finance_notes' => $package->finance_notes,
                'owner_notes' => $package->owner_notes,
                'starts_at' => optional($package->starts_at)?->toIso8601String(),
                'ends_at' => optional($package->ends_at)?->toIso8601String(),
                'service_count' => $package->services->count(),
                'services_total_price' => round($serviceTotal, 2),
                'savings_amount' => round(max($serviceTotal - $effectivePrice, 0), 2),
                'services' => $package->services->map(fn ($service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'category' => $service->category,
                    'price' => (float) $service->price,
                    'duration' => $service->duration,
                    'status' => $service->status,
                ])->values(),
                'material_templates' => $package->materialTemplateItems->map(fn ($line) => [
                    'id' => $line->id,
                    'inventory_item_id' => $line->inventory_item_id,
                    'inventory_item_name' => $line->inventoryItem?->name,
                    'default_quantity' => (float) $line->default_quantity,
                    'is_critical' => (bool) $line->is_critical,
                    'tolerance_percent' => (float) $line->tolerance_percent,
                ])->values(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $packages,
        ]);
    }

    public function index(Request $request)
    {
        $query = RepairPackage::query()->with([
            'services:id,name,category,price,duration,status,shop_owner_id',
            'materialTemplateItems:' . $this->materialTemplateItemSelectList(),
            'materialTemplateItems.inventoryItem:id,name,available_quantity',
        ]);

        if ($shopOwnerId = $this->resolveShopOwnerId()) {
            $query->where('shop_owner_id', $shopOwnerId);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $packages = $query->orderByDesc('created_at')->get()->map(function (RepairPackage $package) {
            $serviceTotal = $package->services->sum(fn ($service) => (float) $service->price);
            $effectivePrice = $this->resolveEffectivePackagePrice($package);

            return [
                'id' => $package->id,
                'shop_owner_id' => $package->shop_owner_id,
                'name' => $package->name,
                'description' => $package->description,
                'package_price' => $effectivePrice,
                'effective_package_price' => $effectivePrice,
                'proposed_package_price' => (float) $package->package_price,
                'old_package_price' => $package->old_package_price !== null ? (float) $package->old_package_price : null,
                'status' => $package->status,
                'approval_status' => $package->approval_status,
                'change_reason' => $package->change_reason,
                'finance_notes' => $package->finance_notes,
                'owner_notes' => $package->owner_notes,
                'starts_at' => optional($package->starts_at)?->toIso8601String(),
                'ends_at' => optional($package->ends_at)?->toIso8601String(),
                'service_count' => $package->services->count(),
                'services_total_price' => round($serviceTotal, 2),
                'savings_amount' => round(max($serviceTotal - $effectivePrice, 0), 2),
                'services' => $package->services->map(fn ($service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'category' => $service->category,
                    'price' => (float) $service->price,
                    'duration' => $service->duration,
                    'status' => $service->status,
                ])->values(),
                'material_templates' => $package->materialTemplateItems->map(fn ($line) => [
                    'id' => $line->id,
                    'inventory_item_id' => $line->inventory_item_id,
                    'inventory_item_name' => $line->inventoryItem?->name,
                    'default_quantity' => (float) $line->default_quantity,
                    'is_critical' => (bool) $line->is_critical,
                    'tolerance_percent' => (float) $line->tolerance_percent,
                ])->values(),
                'created_at' => optional($package->created_at)?->toIso8601String(),
                'updated_at' => optional($package->updated_at)?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $packages,
        ]);
    }

    public function analytics(Request $request)
    {
        $shopOwnerId = $this->resolveShopOwnerId();
        if (!$shopOwnerId) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to resolve shop owner context.',
            ], 403);
        }

        $packages = RepairPackage::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->with('services:id,name,category,price,duration,status,shop_owner_id')
            ->orderByDesc('created_at')
            ->get();

        $packageIds = $packages->pluck('id')->all();

        $packageRequests = empty($packageIds)
            ? collect()
            : RepairRequest::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->whereIn('repair_package_id', $packageIds)
                ->orderByDesc('created_at')
                ->get([
                    'id',
                    'request_id',
                    'repair_package_id',
                    'package_price',
                    'add_ons_total',
                    'final_total',
                    'total',
                    'status',
                    'created_at',
                    'pricing_breakdown',
                ]);

        $bookingsCount = $packageRequests->count();
        $requestRevenueSnapshots = $packageRequests->mapWithKeys(function (RepairRequest $repairRequest) {
            return [$repairRequest->id => $this->resolveRepairPackageRevenueSnapshot($repairRequest)];
        });

        $packageRevenue = round($requestRevenueSnapshots->sum('total_net_revenue_ex_vat'), 2);
        $packageBaseRevenue = round($requestRevenueSnapshots->sum('package_net_revenue_ex_vat'), 2);
        $addOnRevenue = round($requestRevenueSnapshots->sum('add_on_net_revenue_ex_vat'), 2);
        $cutoff = now()->subDays(30);
        $recentRequests = $packageRequests->filter(fn ($repairRequest) => $repairRequest->created_at && $repairRequest->created_at->gte($cutoff));
        $packageLookup = $packages->keyBy('id');

        $topPackages = $packages
            ->map(function (RepairPackage $package) use ($packageRequests, $requestRevenueSnapshots) {
                $bookings = $packageRequests->where('repair_package_id', $package->id)->values();
                $serviceTotal = (float) $package->services->sum(fn ($service) => (float) $service->price);

                $netRevenue = round($bookings->sum(function (RepairRequest $repairRequest) use ($requestRevenueSnapshots) {
                    return (float) data_get($requestRevenueSnapshots, "{$repairRequest->id}.total_net_revenue_ex_vat", 0);
                }), 2);

                $netAddOnRevenue = round($bookings->sum(function (RepairRequest $repairRequest) use ($requestRevenueSnapshots) {
                    return (float) data_get($requestRevenueSnapshots, "{$repairRequest->id}.add_on_net_revenue_ex_vat", 0);
                }), 2);

                return [
                    'id' => $package->id,
                    'name' => $package->name,
                    'status' => $package->status,
                    'booking_count' => $bookings->count(),
                    'revenue' => $netRevenue,
                    'add_on_revenue' => $netAddOnRevenue,
                    'average_order_value' => $bookings->count() > 0
                        ? round($netRevenue / $bookings->count(), 2)
                        : 0,
                    'services_total_price' => round($serviceTotal, 2),
                    'package_price' => (float) $package->package_price,
                    'savings_amount' => round(max($serviceTotal - (float) $package->package_price, 0), 2),
                    'last_booked_at' => optional($bookings->max('created_at'))?->toIso8601String(),
                ];
            })
            ->sortByDesc('booking_count')
            ->values();

        $monthlyTrend = collect(range(5, 0))
            ->map(function (int $monthsAgo) use ($packageRequests, $requestRevenueSnapshots) {
                $monthDate = now()->copy()->subMonths($monthsAgo);
                $bookings = $packageRequests->filter(function ($repairRequest) use ($monthDate) {
                    return $repairRequest->created_at
                        && $repairRequest->created_at->year === $monthDate->year
                        && $repairRequest->created_at->month === $monthDate->month;
                });

                return [
                    'month' => $monthDate->format('M Y'),
                    'bookings' => $bookings->count(),
                    'revenue' => round($bookings->sum(function (RepairRequest $repairRequest) use ($requestRevenueSnapshots) {
                        return (float) data_get($requestRevenueSnapshots, "{$repairRequest->id}.total_net_revenue_ex_vat", 0);
                    }), 2),
                ];
            })
            ->values();

        $recentBookings = $packageRequests
            ->take(5)
            ->map(function ($repairRequest) use ($packageLookup) {
                $package = $packageLookup->get($repairRequest->repair_package_id);

                return [
                    'repair_request_id' => $repairRequest->id,
                    'order_number' => $repairRequest->request_id,
                    'package_id' => $repairRequest->repair_package_id,
                    'package_name' => $repairRequest->pricing_breakdown['package_name'] ?? $package?->name,
                    'booked_at' => optional($repairRequest->created_at)?->toIso8601String(),
                    'final_total' => (float) ($repairRequest->final_total ?? $repairRequest->total ?? 0),
                    'add_ons_total' => (float) ($repairRequest->add_ons_total ?? 0),
                    'status' => $repairRequest->status,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'overview' => [
                    'total_packages' => $packages->count(),
                    'active_packages' => $packages->where('status', 'active')->count(),
                    'inactive_packages' => $packages->where('status', 'inactive')->count(),
                    'total_bookings' => $bookingsCount,
                    'package_revenue' => $packageRevenue,
                    'package_base_revenue' => $packageBaseRevenue,
                    'add_on_revenue' => $addOnRevenue,
                    'average_order_value' => $bookingsCount > 0 ? round($packageRevenue / $bookingsCount, 2) : 0,
                    'add_on_attach_rate' => $bookingsCount > 0
                        ? round(($packageRequests->filter(fn ($repairRequest) => (float) ($repairRequest->add_ons_total ?? 0) > 0)->count() / $bookingsCount) * 100, 1)
                        : 0,
                    'bookings_last_30_days' => $recentRequests->count(),
                    'revenue_last_30_days' => round($recentRequests->sum(function (RepairRequest $repairRequest) use ($requestRevenueSnapshots) {
                        return (float) data_get($requestRevenueSnapshots, "{$repairRequest->id}.total_net_revenue_ex_vat", 0);
                    }), 2),
                ],
                'top_packages' => $topPackages,
                'status_breakdown' => $packageRequests
                    ->groupBy('status')
                    ->map(fn ($items, $status) => ['status' => $status, 'count' => count($items)])
                    ->values(),
                'monthly_trend' => $monthlyTrend,
                'recent_bookings' => $recentBookings,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $shopOwnerId = $this->resolveShopOwnerId();
        if (!$shopOwnerId) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to resolve shop owner context.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'package_price' => 'required|numeric|min:0',
            'status' => 'nullable|in:active,inactive',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'service_ids' => 'required|array|min:2',
            'service_ids.*' => 'integer|exists:repair_services,id',
            'material_templates' => 'required|array|min:1',
            'material_templates.*.inventory_item_id' => 'required|integer|distinct|exists:inventory_items,id',
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

        $actorId = Auth::guard('user')->id();

        $package = RepairPackage::create([
            'shop_owner_id' => $shopOwnerId,
            'name' => $request->name,
            'description' => $request->description,
            'package_price' => $request->package_price,
            'status' => $request->status ?? 'active',
            'starts_at' => $request->starts_at,
            'ends_at' => $request->ends_at,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        try {
            $package->syncIncludedServices((array) $request->service_ids);
            $this->syncMaterialTemplates($package, (array) $request->input('material_templates', []));
        } catch (ValidationException $e) {
            $package->delete();

            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Repair package created successfully.',
            'data' => $package->load([
                'services:id,name,category,price,duration,status,shop_owner_id',
                'materialTemplateItems:id,shop_owner_id,inventory_item_id,template_type,template_id,default_quantity,is_critical,tolerance_percent,created_by',
            ]),
        ], 201);
    }

    public function show(int $id)
    {
        $package = RepairPackage::with([
            'services:id,name,category,price,duration,status,shop_owner_id',
            'materialTemplateItems:' . $this->materialTemplateItemSelectList(),
            'materialTemplateItems.inventoryItem:id,name,available_quantity',
        ])->find($id);

        if (!$package) {
            return response()->json([
                'success' => false,
                'message' => 'Repair package not found.',
            ], 404);
        }

        if (!$this->canAccessPackage($package)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to access this package.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $package,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $package = RepairPackage::find($id);

        if (!$package) {
            return response()->json([
                'success' => false,
                'message' => 'Repair package not found.',
            ], 404);
        }

        if (!$this->canAccessPackage($package)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to update this package.',
            ], 403);
        }

        // Separate validation for price changes vs regular updates
        $isPriceChange = $request->has('package_price') && 
                        ((float)$request->package_price !== (float)$package->package_price);
        $isIndividualShop = $this->isIndividualRegistrationShop((int) $package->shop_owner_id);

        if ($isPriceChange && $isIndividualShop) {
            $validator = Validator::make($request->all(), [
                'package_price' => 'required|numeric|min:0',
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'status' => 'sometimes|in:active,inactive',
                'starts_at' => 'nullable|date',
                'ends_at' => 'nullable|date|after_or_equal:starts_at',
                'service_ids' => 'sometimes|required|array|min:2',
                'service_ids.*' => 'integer|exists:repair_services,id',
                'material_templates' => 'sometimes|array|min:1',
                'material_templates.*.inventory_item_id' => 'required_with:material_templates|integer|distinct|exists:inventory_items,id',
                'material_templates.*.default_quantity' => 'required_with:material_templates|integer|min:1',
                'material_templates.*.is_critical' => 'required_with:material_templates|boolean',
                'material_templates.*.tolerance_percent' => 'nullable|numeric|min:0|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $updateData = $request->only(['name', 'description', 'status', 'starts_at', 'ends_at', 'package_price']);

            if (Auth::guard('user')->check()) {
                $updateData['updated_by'] = Auth::guard('user')->id();
            }

            // Individual shops do not need pending approval metadata on package prices.
            $workflowResetData = [
                'old_package_price' => null,
                'change_reason' => $request->filled('reason') ? (string) $request->reason : null,
                'approval_status' => null,
                'approval_workflow_version' => null,
                'current_approval_level' => null,
                'approval_id' => null,
                'finance_reviewed_by' => null,
                'finance_reviewed_at' => null,
                'finance_notes' => null,
                'owner_reviewed_by' => null,
                'owner_reviewed_at' => null,
                'owner_notes' => null,
            ];

            $package->update($this->onlyExistingRepairPackageColumns(array_merge($updateData, $workflowResetData)));

            if ($request->has('service_ids')) {
                try {
                    $package->syncIncludedServices((array) $request->service_ids);
                } catch (ValidationException $e) {
                    return response()->json([
                        'success' => false,
                        'errors' => $e->errors(),
                    ], 422);
                }
            }

            if ($request->has('material_templates')) {
                try {
                    $this->syncMaterialTemplates($package, (array) $request->input('material_templates', []));
                } catch (ValidationException $e) {
                    return response()->json([
                        'success' => false,
                        'errors' => $e->errors(),
                    ], 422);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Repair package updated successfully.',
                'data' => $package->load([
                    'services:id,name,category,price,duration,status,shop_owner_id',
                    'materialTemplateItems:' . $this->materialTemplateItemSelectList(),
                ]),
            ]);
        }

        // Check for duplicate/pending price change requests
        if ($isPriceChange && $request->has('reason')) {
            $existingPending = $package->approval_status && in_array($package->approval_status, [
                'pending_finance',
                'finance_approved',
                'pending_owner',
                'owner_approved',
            ]);

            if ($existingPending) {
                return response()->json([
                    'success' => false,
                    'message' => 'A price change request for this package is already pending approval. Please wait for the current request to be processed before submitting another.',
                    'current_status' => $package->approval_status,
                    'pending_request' => [
                        'old_price' => (float)$package->old_package_price,
                        'proposed_price' => (float)$package->package_price,
                        'reason' => $package->change_reason,
                        'status' => $package->approval_status,
                    ],
                ], 409); // 409 Conflict - resource already has an active request
            }
        }

        if ($isPriceChange && $request->has('reason')) {
            // Price change request - requires reason and enters approval workflow
            $validator = Validator::make($request->all(), [
                'package_price' => 'required|numeric|min:0',
                'reason' => 'required|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $currentPrice = (float) $package->package_price;
            $proposedPrice = (float) $request->package_price;
            $requiresOwnerApproval = $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForPriceChange(
                (int) $package->shop_owner_id,
                $currentPrice,
                $proposedPrice
            );

            // Store the price change request for approval workflow
            $package->update($this->onlyExistingRepairPackageColumns([
                'old_package_price' => $package->package_price,
                'package_price' => $request->package_price,
                'change_reason' => $request->reason,
                'approval_status' => 'pending_finance', // Start approval workflow
                'approval_workflow_version' => $requiresOwnerApproval ? 'repair_finance_owner_finance' : 'repair_finance_only',
                'current_approval_level' => 1,
                'finance_reviewed_by' => null,
                'finance_reviewed_at' => null,
                'finance_notes' => null,
                'owner_reviewed_by' => null,
                'owner_reviewed_at' => null,
                'owner_notes' => null,
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Price change request submitted for Finance approval.',
                'data' => $package->load('services:id,name,category,price,duration,status,shop_owner_id'),
                'approval_status' => $package->approval_status,
                'requires_owner_approval' => $requiresOwnerApproval,
            ], 202); // 202 Accepted - request is queued for processing
        }

        // Regular update (name, description, status, services, dates) - no approval needed
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'service_ids' => 'sometimes|required|array|min:2',
            'service_ids.*' => 'integer|exists:repair_services,id',
            'material_templates' => 'required|array|min:1',
            'material_templates.*.inventory_item_id' => 'required|integer|distinct|exists:inventory_items,id',
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

        $updateData = $request->only(['name', 'description', 'status', 'starts_at', 'ends_at']);
        if (Auth::guard('user')->check()) {
            $updateData['updated_by'] = Auth::guard('user')->id();
        }

        $package->update($this->onlyExistingRepairPackageColumns($updateData));

        if ($request->has('service_ids')) {
            try {
                $package->syncIncludedServices((array) $request->service_ids);
            } catch (ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'errors' => $e->errors(),
                ], 422);
            }
        }

        if ($request->has('material_templates')) {
            try {
                $this->syncMaterialTemplates($package, (array) $request->input('material_templates', []));
            } catch (ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'errors' => $e->errors(),
                ], 422);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Repair package updated successfully.',
            'data' => $package->load([
                'services:id,name,category,price,duration,status,shop_owner_id',
                'materialTemplateItems:' . $this->materialTemplateItemSelectList(),
            ]),
        ]);
    }

    public function destroy(int $id)
    {
        $package = RepairPackage::find($id);

        if (!$package) {
            return response()->json([
                'success' => false,
                'message' => 'Repair package not found.',
            ], 404);
        }

        if (!$this->canAccessPackage($package)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to delete this package.',
            ], 403);
        }

        $package->delete();

        return response()->json([
            'success' => true,
            'message' => 'Repair package deleted successfully.',
        ]);
    }

    private function resolveShopOwnerId(): ?int
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

    private function canAccessPackage(RepairPackage $package): bool
    {
        $shopOwnerId = $this->resolveShopOwnerId();
        return $shopOwnerId !== null && $package->shop_owner_id === $shopOwnerId;
    }

    private function resolveEffectivePackagePrice(RepairPackage $package): float
    {
        $approvalStatus = strtolower((string) ($package->approval_status ?? 'none'));

        $isNotYetApplied = in_array($approvalStatus, [
            'pending_finance',
            'finance_approved',
            'pending_owner',
            'owner_approved',
            'finance_rejected',
            'owner_rejected',
        ], true);

        if ($isNotYetApplied && $package->old_package_price !== null) {
            return (float) $package->old_package_price;
        }

        return (float) $package->package_price;
    }

    private function resolveRepairPackageRevenueSnapshot(RepairRequest $repairRequest): array
    {
        $grossOrderTotal = round((float) ($repairRequest->final_total ?? $repairRequest->total ?? 0), 2);
        if ($grossOrderTotal <= 0) {
            return [
                'total_net_revenue_ex_vat' => 0.0,
                'package_net_revenue_ex_vat' => 0.0,
                'add_on_net_revenue_ex_vat' => 0.0,
            ];
        }

        $packageGross = round(max(0.0, (float) ($repairRequest->package_price ?? data_get($repairRequest->pricing_breakdown, 'package_price', 0))), 2);
        $addOnGross = round(max(0.0, (float) ($repairRequest->add_ons_total ?? data_get($repairRequest->pricing_breakdown, 'add_ons_total', 0))), 2);

        if ($packageGross <= 0.0) {
            $packageGross = round(max(0.0, $grossOrderTotal - $addOnGross), 2);
        }

        $recordedPaid = round((float) ($repairRequest->total_paid_amount ?? 0), 2);
        $recordedRefunded = round((float) ($repairRequest->total_refunded_amount ?? 0), 2);
        $paymentStatus = strtolower(trim((string) ($repairRequest->payment_status ?? 'pending')));
        $paymentPolicy = (string) ($repairRequest->payment_policy ?? 'deposit_50');

        $resolvedPaid = $recordedPaid;
        if ($resolvedPaid <= 0) {
            if ($paymentStatus === 'completed') {
                $resolvedPaid = $grossOrderTotal;
            } elseif (in_array($paymentStatus, ['paid', 'partially_paid'], true)) {
                $resolvedPaid = $paymentPolicy === 'full_upfront'
                    ? $grossOrderTotal
                    : round($grossOrderTotal * 0.5, 2);
            }
        }

        $netCollectedGross = max(0.0, round($resolvedPaid - $recordedRefunded, 2));
        $realizedRatio = $grossOrderTotal > 0
            ? min(1.0, $netCollectedGross / $grossOrderTotal)
            : 0.0;

        $taxMode = strtolower((string) data_get($repairRequest->pricing_breakdown, 'tax_mode', 'vat_inclusive'));
        $vatRate = (float) data_get($repairRequest->pricing_breakdown, 'vat_rate', self::REPAIR_VAT_RATE_PERCENT);
        if (!is_finite($vatRate) || $vatRate < 0) {
            $vatRate = self::REPAIR_VAT_RATE_PERCENT;
        }

        $vatDivisor = in_array($taxMode, ['legacy_add_on', 'legacy_additive'], true)
            ? 1.0
            : (1 + ($vatRate / 100));

        $totalNetExVat = round(($grossOrderTotal * $realizedRatio) / $vatDivisor, 2);
        $packageNetExVat = round(($packageGross * $realizedRatio) / $vatDivisor, 2);
        $addOnNetExVat = round(($addOnGross * $realizedRatio) / $vatDivisor, 2);

        return [
            'total_net_revenue_ex_vat' => max(0.0, $totalNetExVat),
            'package_net_revenue_ex_vat' => max(0.0, $packageNetExVat),
            'add_on_net_revenue_ex_vat' => max(0.0, $addOnNetExVat),
        ];
    }

    private function syncMaterialTemplates(RepairPackage $package, array $materialTemplates): void
    {
        $createdBy = Auth::guard('user')->id();

        foreach ($materialTemplates as $index => $line) {
            $inventoryItem = InventoryItem::query()
                ->where('id', (int) ($line['inventory_item_id'] ?? 0))
                ->where('shop_owner_id', $package->shop_owner_id)
                ->where('category', 'repair_materials')
                ->first();

            if (!$inventoryItem) {
                throw ValidationException::withMessages([
                    "material_templates.{$index}.inventory_item_id" => 'Selected inventory item must belong to this shop and be a repair material.',
                ]);
            }
        }

        $package->materialTemplateItems()->delete();

        collect($materialTemplates)->each(function (array $line) use ($package, $createdBy): void {
            $package->materialTemplateItems()->create($this->onlyExistingMaterialTemplateColumns([
                'shop_owner_id' => $package->shop_owner_id,
                'inventory_item_id' => (int) $line['inventory_item_id'],
                'template_type' => 'repair_package',
                'template_id' => $package->id,
                'default_quantity' => (int) $line['default_quantity'],
                'is_critical' => (bool) $line['is_critical'],
                'tolerance_percent' => (float) ($line['tolerance_percent'] ?? 20),
                'created_by' => $createdBy,
            ]));
        });
    }

    private function materialTemplateItemSelectList(): string
    {
        return implode(',', $this->materialTemplateItemSelectColumns());
    }

    private function materialTemplateItemSelectColumns(): array
    {
        static $columns = null;

        if ($columns !== null) {
            return $columns;
        }

        $desired = [
            'id',
            'shop_owner_id',
            'inventory_item_id',
            'template_type',
            'template_id',
            'default_quantity',
            'is_critical',
            'tolerance_percent',
            'created_by',
        ];

        $existingLookup = $this->repairMaterialTemplateColumnLookup();
        $columns = array_values(array_filter(
            $desired,
            fn (string $column): bool => isset($existingLookup[$column])
        ));

        if (!in_array('id', $columns, true)) {
            $columns[] = 'id';
        }

        if (!in_array('template_id', $columns, true)) {
            $columns[] = 'template_id';
        }

        return $columns;
    }

    private function onlyExistingMaterialTemplateColumns(array $payload): array
    {
        $existingLookup = $this->repairMaterialTemplateColumnLookup();

        return collect($payload)
            ->filter(fn ($_value, $key) => isset($existingLookup[$key]))
            ->all();
    }

    private function repairMaterialTemplateColumnLookup(): array
    {
        static $lookup = null;

        if ($lookup === null) {
            $lookup = array_flip(Schema::getColumnListing(self::MATERIAL_TEMPLATE_TABLE));
        }

        return $lookup;
    }

    private function onlyExistingRepairPackageColumns(array $payload): array
    {
        static $repairPackageColumnLookup = null;

        if ($repairPackageColumnLookup === null) {
            $repairPackageColumnLookup = array_flip(Schema::getColumnListing('repair_packages'));
        }

        return collect($payload)
            ->filter(fn ($_value, $key) => isset($repairPackageColumnLookup[$key]))
            ->all();
    }
}
