<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\RepairRequest;
use App\Models\RepairReview;
use App\Models\RepairPackage;
use App\Models\RepairPaymentSession;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\ShopPolicyVersion;
use App\Models\User;
use App\Models\Logistics\Shipment;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceItem;
use App\Models\PosPaymentLine;
use App\Models\PosTransaction;
use App\Support\Tax\VatInclusiveCalculator;
use App\Services\NotificationService;
use App\Services\PaymentSettlementService;
use App\Services\RepairPosPaymentService;
use App\Services\RepairPosReceiptService;
use App\Services\RepairPosRefundService;
use App\Services\RepairDeliveryService;
use App\Services\ShopOwnerApprovalPolicyService;
use App\Services\PolicyAcceptanceService;

class RepairRequestController extends Controller
{
    private const REPAIR_VAT_RATE_PERCENT = 12.0;
    private const PAYMENT_RETURN_TOKEN_TTL_SECONDS = 86400;

    public function __construct(
        private ShopOwnerApprovalPolicyService $shopOwnerApprovalPolicyService
    ) {}

    public function store(
        Request $request,
        PolicyAcceptanceService $policyAcceptanceService,
        RepairDeliveryService $repairDeliveryService,
    )
    {
        if (!Auth::guard('user')->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Please log in to submit a repair request.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'customer_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'shoe_type' => 'required|string|max:255',
            'brand' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'shop_owner_id' => 'nullable|exists:shop_owners,id',
            'repair_package_id' => 'nullable|exists:repair_packages,id',
            'services' => 'nullable|array|required_without:repair_package_id|min:1',
            'services.*' => 'exists:repair_services,id',
            'add_on_service_ids' => 'nullable|array',
            'add_on_service_ids.*' => 'exists:repair_services,id',
            'images' => 'required|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
            'total' => 'required|numeric|min:0',
            'preferred_date' => 'nullable|date|after:today',
            'service_type' => 'nullable|required_without:intake_delivery_method|in:pickup,walkin',
            'intake_delivery_method' => 'nullable|in:walk_in,customer_delivery,shop_pickup',
            'intake_address_id' => 'nullable|integer',
            'pickup_address_line' => 'nullable|string|max:255',
            'pickup_barangay' => 'nullable|string|max:255',
            'pickup_city' => 'nullable|string|max:255',
            'pickup_region' => 'nullable|string|max:255',
            'pickup_postal_code' => 'nullable|string|max:10',
            'return_delivery_method' => 'nullable|in:walk_in,customer_pickup,shop_delivery',
            'return_address_id' => 'nullable|integer',
            'same_as_intake_address' => 'nullable|boolean',
            'return_address_line' => 'nullable|string|max:255',
            'return_barangay' => 'nullable|string|max:255',
            'return_city' => 'nullable|string|max:255',
            'return_region' => 'nullable|string|max:255',
            'return_postal_code' => 'nullable|string|max:10',
            'accepted_shop_policy_version_id' => 'nullable|integer|exists:shop_policy_versions,id',
            'policy_accepted' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $usesExplicitLogistics = $request->filled('intake_delivery_method');
        $intakeDeliveryMethod = $usesExplicitLogistics
            ? (string) $request->input('intake_delivery_method')
            : ($request->input('service_type') === 'pickup' ? 'customer_delivery' : 'walk_in');
        $returnDeliveryMethod = (string) ($request->input('return_delivery_method')
            ?: ($intakeDeliveryMethod === 'walk_in' ? 'walk_in' : 'customer_pickup'));
        $sameAsIntakeAddress = $usesExplicitLogistics && $request->boolean('same_as_intake_address', true);
        $logisticsErrors = [];

        if ($usesExplicitLogistics) {
            if ($intakeDeliveryMethod !== 'walk_in' && ! $request->filled('intake_address_id')) {
                $logisticsErrors['intake_address_id'][] = 'Choose a saved address for sending your shoes to the shop.';
            }
            if ($returnDeliveryMethod !== 'walk_in'
                && (! $sameAsIntakeAddress || $intakeDeliveryMethod === 'walk_in')
                && ! $request->filled('return_address_id')) {
                $logisticsErrors['return_address_id'][] = 'Choose a saved address for returning your repaired shoes.';
            }
            if (in_array($intakeDeliveryMethod, ['shop_pickup'], true)
                || in_array($returnDeliveryMethod, ['shop_delivery'], true)) {
                if (! $request->filled('shop_owner_id')) {
                    $logisticsErrors['shop_owner_id'][] = 'Choose a shop before using shop-owned logistics.';
                }
            }
        } elseif ($request->filled('return_delivery_method') && $returnDeliveryMethod !== 'walk_in') {
            foreach ([
                'return_address_line' => 'Return address line',
                'return_barangay' => 'Return barangay',
                'return_city' => 'Return city',
                'return_region' => 'Return province',
                'return_postal_code' => 'Return postal code',
            ] as $field => $label) {
                if (! $request->filled($field)) {
                    $logisticsErrors[$field][] = "{$label} is required.";
                }
            }
        }

        $customer = Auth::guard('user')->user();
        $shopOwner = $request->filled('shop_owner_id')
            ? ShopOwner::find((int) $request->input('shop_owner_id'))
            : null;
        $intakeSavedAddress = null;
        $returnSavedAddress = null;

        if ($usesExplicitLogistics && $intakeDeliveryMethod !== 'walk_in' && $request->filled('intake_address_id')) {
            $intakeSavedAddress = $customer?->addresses()->find((int) $request->input('intake_address_id'));
            if (! $intakeSavedAddress) {
                $logisticsErrors['intake_address_id'][] = 'Choose one of your saved addresses.';
            }
        }

        if ($usesExplicitLogistics && $returnDeliveryMethod !== 'walk_in') {
            if ($sameAsIntakeAddress && $intakeDeliveryMethod !== 'walk_in') {
                $returnSavedAddress = $intakeSavedAddress;
            } elseif ($request->filled('return_address_id')) {
                $returnSavedAddress = $customer?->addresses()->find((int) $request->input('return_address_id'));
                if (! $returnSavedAddress) {
                    $logisticsErrors['return_address_id'][] = 'Choose one of your saved addresses.';
                }
            }
        }

        if ($logisticsErrors !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $logisticsErrors,
            ], 422);
        }

        $pickupAddress = null;
        $intakeAddress = null;
        $returnAddress = null;
        $intakeLogisticsQuote = null;
        $returnLogisticsQuote = null;
        $intakeDeliveryFee = 0.0;
        $returnDeliveryFee = 0.0;

        if ($usesExplicitLogistics) {
            if ($intakeSavedAddress) {
                $intakeAddress = $repairDeliveryService->snapshot($intakeSavedAddress, $intakeDeliveryMethod);
                $pickupAddress = $intakeAddress;
            }
            if ($returnSavedAddress) {
                $returnAddress = $repairDeliveryService->snapshot($returnSavedAddress, $returnDeliveryMethod);
            }

            if ($intakeDeliveryMethod === 'shop_pickup' && $shopOwner && $intakeSavedAddress) {
                $intakeLogisticsQuote = $repairDeliveryService->quote($shopOwner, $intakeSavedAddress);
                if (! $intakeLogisticsQuote['available']) {
                    return $this->repairLogisticsValidationFailure('intake_address_id', $intakeLogisticsQuote['reason']);
                }
                $intakeDeliveryFee = (float) $intakeLogisticsQuote['fee'];
                $intakeLogisticsQuote['address_version'] = $intakeAddress['version'];
                $intakeLogisticsQuote['method'] = $intakeDeliveryMethod;
            }

            if ($returnDeliveryMethod === 'shop_delivery' && $shopOwner && $returnSavedAddress) {
                $returnLogisticsQuote = $repairDeliveryService->quote($shopOwner, $returnSavedAddress);
                if (! $returnLogisticsQuote['available']) {
                    return $this->repairLogisticsValidationFailure('return_address_id', $returnLogisticsQuote['reason']);
                }
                $returnDeliveryFee = (float) $returnLogisticsQuote['fee'];
                $returnLogisticsQuote['address_version'] = $returnAddress['version'];
                $returnLogisticsQuote['method'] = $returnDeliveryMethod;
            }
        } else {
            if ($intakeDeliveryMethod === 'customer_delivery' && $shopOwner) {
                $shopAddressLine = $shopOwner->shop_address
                    ?? $shopOwner->business_address
                    ?? $shopOwner->city_state
                    ?? 'Shop address unavailable';
                $parts = array_values(array_filter(array_map('trim', explode(',', (string) ($shopOwner->city_state ?? '')))));
                $intakeAddress = [
                    'address_line' => $shopAddressLine,
                    'barangay' => null,
                    'city' => $parts[0] ?? null,
                    'region' => $parts[1] ?? null,
                    'postal_code' => $shopOwner->postal_code,
                ];
                $pickupAddress = $intakeAddress;
            }
            if ($returnDeliveryMethod !== 'walk_in') {
                $returnAddress = [
                    'address_line' => $request->return_address_line,
                    'barangay' => $request->return_barangay,
                    'city' => $request->return_city,
                    'region' => $request->return_region,
                    'postal_code' => $request->return_postal_code,
                ];
            }
        }

        $deliveryMethod = $intakeDeliveryMethod === 'walk_in' ? 'walk_in' : 'pickup';
        $autoEnableOnlinePayment = $intakeDeliveryMethod === 'walk_in';

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
            $userId = Auth::guard('user')->id();

            $selectedPackage = null;
            $includedServiceIds = [];
            $addOnServiceIds = [];
            $serviceIds = [];
            $includedServicesSnapshot = [];
            $addOnServicesSnapshot = [];
            $packageTotal = null;
            $addOnsTotal = 0.0;
            $requestTotal = 0.0;

            if ($request->filled('repair_package_id')) {
                $selectedPackage = RepairPackage::query()
                    ->with('services:id,name,category,price,duration,status,shop_owner_id')
                    ->find($request->repair_package_id);

                if (!$selectedPackage) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected package was not found.',
                    ], 422);
                }

                if ($request->filled('shop_owner_id') && (int) $request->shop_owner_id !== (int) $selectedPackage->shop_owner_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected package does not belong to the selected shop.',
                    ], 422);
                }

                if ($selectedPackage->status !== 'active') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected package is not active.',
                    ], 422);
                }

                $now = now();
                if (($selectedPackage->starts_at && $selectedPackage->starts_at->gt($now))
                    || ($selectedPackage->ends_at && $selectedPackage->ends_at->lt($now))) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected package is outside its availability window.',
                    ], 422);
                }

                $includedServiceIds = $selectedPackage->services->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
                if (empty($includedServiceIds)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected package has no included services.',
                    ], 422);
                }

                $includedServicesSnapshot = $selectedPackage->services->map(fn ($service) => [
                    'id' => (int) $service->id,
                    'name' => $service->name,
                    'category' => $service->category,
                    'price' => (float) $service->price,
                    'duration' => $service->duration,
                ])->values()->all();

                $packageTotal = $this->resolveEffectivePackagePrice($selectedPackage);

                $requestedAddOnIds = collect((array) $request->add_on_service_ids)
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values();

                if ($requestedAddOnIds->isNotEmpty()) {
                    $invalidIncludedIds = $requestedAddOnIds->intersect($includedServiceIds)->values()->all();
                    if (!empty($invalidIncludedIds)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Included package services cannot be submitted as add-ons.',
                        ], 422);
                    }

                    $addOnServices = RepairService::query()
                        ->whereIn('id', $requestedAddOnIds)
                        ->where('shop_owner_id', $selectedPackage->shop_owner_id)
                        ->get(['id', 'name', 'category', 'price', 'duration', 'shop_owner_id']);

                    if ($addOnServices->count() !== $requestedAddOnIds->count()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Some add-on services are invalid for the selected package/shop.',
                        ], 422);
                    }

                    $addOnServiceIds = $addOnServices->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
                    $addOnsTotal = (float) $addOnServices->sum(fn ($service) => (float) $service->price);
                    $addOnServicesSnapshot = $addOnServices->map(fn ($service) => [
                        'id' => (int) $service->id,
                        'name' => $service->name,
                        'category' => $service->category,
                        'price' => (float) $service->price,
                        'duration' => $service->duration,
                    ])->values()->all();
                }

                $requestTotal = $packageTotal + $addOnsTotal;
                $serviceIds = array_values(array_unique(array_merge($includedServiceIds, $addOnServiceIds)));
            } else {
                $serviceIds = collect((array) $request->services)
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                $standardServices = RepairService::query()
                    ->whereIn('id', $serviceIds)
                    ->get(['id', 'name', 'category', 'price', 'duration', 'shop_owner_id']);

                if ($standardServices->count() !== count($serviceIds)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Some selected services are invalid.',
                    ], 422);
                }

                if ($request->filled('shop_owner_id') && $standardServices->contains(fn ($service) => (int) $service->shop_owner_id !== (int) $request->shop_owner_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected services must belong to the chosen shop.',
                    ], 422);
                }

                $includedServicesSnapshot = $standardServices->map(fn ($service) => [
                    'id' => (int) $service->id,
                    'name' => $service->name,
                    'category' => $service->category,
                    'price' => (float) $service->price,
                    'duration' => $service->duration,
                ])->values()->all();

                $requestTotal = (float) $standardServices->sum(fn ($service) => (float) $service->price);
            }
            
            // Get shop owner for high value check
            $isHighValue = $shopOwner && $requestTotal >= $shopOwner->high_value_threshold;
            $requiresOwnerApprovalByPolicy = $shopOwner
                ? $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForRepairReject((int) $shopOwner->id, (float) $requestTotal)
                : false;
            $requiresOwnerApproval = $shopOwner
                && $shopOwner->require_two_way_approval
                && $requiresOwnerApprovalByPolicy;

            if ($requiresOwnerApprovalByPolicy) {
                $isHighValue = true;
            }

            $activePolicyVersion = null;
            if ($shopOwner) {
                $activePolicyVersion = ShopPolicyVersion::query()
                    ->where('shop_owner_id', (int) $shopOwner->id)
                    ->where('status', 'published')
                    ->latest('version_number')
                    ->first();
            }

            if ($activePolicyVersion) {
                $providedVersionId = (int) ($request->input('accepted_shop_policy_version_id') ?? 0);
                $policyAccepted = filter_var($request->input('policy_accepted'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;

                if (!$policyAccepted || $providedVersionId !== (int) $activePolicyVersion->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You must accept the latest shop terms before submitting this repair request.',
                        'errors' => [
                            'policy_accepted' => ['Latest shop terms acceptance is required.'],
                            'accepted_shop_policy_version_id' => ['Active policy version mismatch. Please reopen and accept the latest terms.'],
                        ],
                    ], 422);
                }
            }
            
            // Create request and acceptance evidence atomically.
            $repairRequest = DB::transaction(function () use (
                $request,
                $requestId,
                $selectedPackage,
                $userId,
                $imagePaths,
                $requestTotal,
                $packageTotal,
                $addOnsTotal,
                $includedServicesSnapshot,
                $addOnServicesSnapshot,
                $addOnServiceIds,
                $deliveryMethod,
                $pickupAddress,
                $intakeDeliveryMethod,
                $intakeAddress,
                $returnDeliveryMethod,
                $returnAddress,
                $intakeDeliveryFee,
                $returnDeliveryFee,
                $sameAsIntakeAddress,
                $intakeLogisticsQuote,
                $returnLogisticsQuote,
                $isHighValue,
                $requiresOwnerApproval,
                $shopOwner,
                $autoEnableOnlinePayment,
                $serviceIds,
                $activePolicyVersion,
                $policyAcceptanceService
            ) {
                $repairRequest = RepairRequest::create([
                    'request_id' => $requestId,
                    'customer_name' => $request->customer_name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'shoe_type' => $request->shoe_type,
                    'brand' => $request->brand,
                    'description' => $request->description,
                    'shop_owner_id' => $request->shop_owner_id,
                    'repair_package_id' => $selectedPackage?->id,
                    'user_id' => $userId,
                    'images' => json_encode($imagePaths),
                    'total' => $requestTotal,
                    'package_price' => $packageTotal,
                    'add_ons_total' => $addOnsTotal,
                    'final_total' => $requestTotal,
                    'included_services_snapshot' => !empty($includedServicesSnapshot) ? $includedServicesSnapshot : null,
                    'add_on_services_snapshot' => !empty($addOnServicesSnapshot) ? $addOnServicesSnapshot : null,
                    'pricing_breakdown' => [
                        'mode' => $selectedPackage ? 'package' : 'services',
                        'package_id' => $selectedPackage?->id,
                        'package_name' => $selectedPackage?->name,
                        'included_services_total' => $selectedPackage
                            ? (float) $selectedPackage->services->sum(fn ($service) => (float) $service->price)
                            : $requestTotal,
                        'package_price' => $packageTotal,
                        'add_ons_total' => $addOnsTotal,
                        'base_total' => $requestTotal,
                        'materials_total' => 0.0,
                        'final_total' => $requestTotal,
                        'add_on_count' => count($addOnServiceIds),
                        'tax_mode' => 'vat_inclusive',
                    ],
                    'status' => 'new_request',
                    'delivery_method' => $deliveryMethod,
                    'pickup_address' => $pickupAddress,
                    'intake_delivery_method' => $intakeDeliveryMethod,
                    'intake_address' => $intakeAddress,
                    'return_delivery_method' => $returnDeliveryMethod,
                    'return_address' => $returnAddress,
                    'intake_delivery_fee' => $intakeDeliveryFee,
                    'return_delivery_fee' => $returnDeliveryFee,
                    'same_as_intake_address' => $sameAsIntakeAddress,
                    'intake_logistics_quote' => $intakeLogisticsQuote,
                    'return_logistics_quote' => $returnLogisticsQuote,
                    'is_high_value' => $isHighValue,
                    'requires_owner_approval' => $requiresOwnerApproval,
                    'scheduled_dropoff_date' => $request->preferred_date ? \Carbon\Carbon::parse($request->preferred_date)->startOfDay() : null,
                    'payment_policy' => $shopOwner
                        ? $this->normalizeRepairPaymentPolicy($shopOwner->repair_payment_policy ?? 'deposit_50')
                        : 'deposit_50',
                    'payment_enabled' => $autoEnableOnlinePayment,
                    'payment_enabled_at' => $autoEnableOnlinePayment ? now() : null,
                ]);

                if (!empty($serviceIds)) {
                    $repairRequest->services()->attach($serviceIds);
                }

                if ($activePolicyVersion) {
                    $repairRequest->accepted_shop_policy_version_id = (int) $activePolicyVersion->id;
                    $repairRequest->save();

                    $policyAcceptanceService->record([
                        'shop_owner_id' => (int) $shopOwner?->id,
                        'shop_policy_version_id' => (int) $activePolicyVersion->id,
                        'actor_user_id' => (int) $userId,
                        'context_type' => 'repair_request',
                        'context_id' => (int) $repairRequest->id,
                        'accepted_at' => now(),
                        'accepted_from_ip' => $request->ip(),
                        'accepted_user_agent' => (string) $request->userAgent(),
                    ]);
                }

                return $repairRequest;
            });

            // Get notification service
            $notificationService = app(NotificationService::class);

            // Notify shop owner of new repair request
            if ($request->shop_owner_id) {
                $notificationService->notifyNewRepairRequest($request->shop_owner_id, [
                    'request_id' => $requestId,
                    'order_number' => $requestId,
                    'customer_name' => $request->customer_name,
                    'service_type' => $request->service_type,
                    'total' => $requestTotal,
                    'service_count' => count($serviceIds),
                ]);

                // If high-value repair requiring owner approval, send additional notification
                if ($requiresOwnerApproval) {
                    $notificationService->notifyHighValueRepairApproval($request->shop_owner_id, [
                        'request_id' => $requestId,
                        'order_number' => $requestId,
                        'customer_name' => $request->customer_name,
                        'total' => $requestTotal,
                        'threshold' => $shopOwner->high_value_threshold,
                    ]);
                }
            }

            // Notify all staff with repair order permissions
            if ($request->shop_owner_id) {
                $notificationService->notifyAllStaffNewRepair($request->shop_owner_id, [
                    'request_id' => $requestId,
                    'order_number' => $requestId,
                    'customer_name' => $request->customer_name,
                    'service_type' => $request->service_type,
                    'total' => $requestTotal,
                    'service_count' => count($serviceIds),
                ]);
            }

            // AUTO-ASSIGN TO REPAIRER (Phase 2)
            $this->autoAssignRepairer($repairRequest);

            return response()->json([
                'success' => true,
                'message' => 'Repair request submitted successfully',
                'data' => [
                    'request_id' => $requestId,
                    'total' => $requestTotal,
                    'status' => $repairRequest->fresh()->status,
                    'assigned_repairer_id' => $repairRequest->fresh()->assigned_repairer_id,
                    'repair_package_id' => $repairRequest->fresh()->repair_package_id,
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

    private function reconcilePendingRepairPaymentWithGateway($repair, PaymentSettlementService $settlementService): bool
    {
        if (!$repair instanceof RepairRequest) {
            return false;
        }

        $normalizedPolicy = $settlementService->normalizeRepairPaymentPolicy($repair->payment_policy ?? 'deposit_50');

        if ($settlementService->isRepairSettled($repair, $normalizedPolicy)) {
            return false;
        }

        if (!$settlementService->isRepairPaymentDueNow($repair, $normalizedPolicy)) {
            return false;
        }

        $currentPaymentStatus = strtolower(trim((string) ($repair->payment_status ?? 'pending')));
        if (in_array($currentPaymentStatus, ['completed', 'refunded', 'partially_refunded'], true)) {
            return false;
        }

        if (
            $normalizedPolicy === 'deposit_50'
            && $currentPaymentStatus === 'paid'
            && !$this->isRepairRemainingBalancePhase($repair)
        ) {
            return false;
        }

        $checkoutSessionId = trim((string) ($repair->paymongo_link_id ?? ''));
        if ($checkoutSessionId === '') {
            return false;
        }

        $apiKey = trim((string) ($repair->shopOwner?->paymongo_secret_key ?? ''));
        if ($apiKey === '') {
            return false;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode($apiKey . ':'),
            ])->get("https://api.paymongo.com/v1/checkout_sessions/{$checkoutSessionId}");

            if ($response->failed()) {
                return false;
            }

            $attributes = $response->json('data.attributes') ?? [];
            $paymentStatus = strtolower((string) ($attributes['payment_status'] ?? ''));
            $payments = $attributes['payments'] ?? [];
            $firstPayment = $payments[0] ?? [];
            $firstPaymentStatus = strtolower((string) ($firstPayment['data']['attributes']['status'] ?? $firstPayment['attributes']['status'] ?? ''));
            $paymentId = (string) ($firstPayment['data']['id'] ?? $firstPayment['id'] ?? '');

            $isVerified = in_array('paid', [$paymentStatus, $firstPaymentStatus], true);

            if ($isVerified) {
                $settlement = $settlementService->settleRepairPaid(
                    repair: $repair,
                    paymentId: $paymentId !== '' ? $paymentId : null,
                    ignoreExpiry: true,
                );

                return ($settlement['result'] ?? null) === 'settled';
            }

            $isFailed = in_array('failed', [$paymentStatus, $firstPaymentStatus], true);
            $isExpiredSignal = in_array('expired', [$paymentStatus, $firstPaymentStatus], true);
            $isCancelled = in_array('cancelled', [$paymentStatus, $firstPaymentStatus], true)
                || in_array('canceled', [$paymentStatus, $firstPaymentStatus], true);

            if ($isFailed || $isExpiredSignal || $isCancelled) {
                $settlementService->recordRepairPaymentFailure(
                    $repair,
                    $isExpiredSignal
                        ? 'paymongo_session_expired'
                        : ($isCancelled ? 'paymongo_payment_cancelled' : 'paymongo_payment_failed')
                );

                return true;
            }
        } catch (\Throwable $e) {
            \Log::warning('Repair payment reconciliation failed on myRepairs fetch', [
                'repair_id' => (int) ($repair->id ?? 0),
                'session_id' => $checkoutSessionId,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
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
            
            // Store old status before update
            $oldStatus = $repairRequest->status;
            
            $repairRequest->status = $request->status;
            
            if ($request->status === 'in-progress' && !$repairRequest->started_at) {
                $repairRequest->started_at = now();
            }
            
            if ($request->status === 'ready-for-pickup' && !$repairRequest->completed_at) {
                $repairRequest->completed_at = now();
            }
            
            $repairRequest->save();

            // Log the status change with business context
            $user = Auth::guard('user')->user();
            activity()
                ->causedBy($user)
                ->performedOn($repairRequest)
                ->withProperties([
                    'request_id' => $repairRequest->request_id,
                    'customer_name' => $repairRequest->customer_name,
                    'old_status' => $oldStatus,
                    'new_status' => $request->status,
                    'total_amount' => $repairRequest->total,
                    'updated_by_name' => $user ? $user->name : 'System',
                    'updated_by_role' => $user ? $user->role : 'System',
                    'started_at' => $request->status === 'in-progress' ? now()->toDateTimeString() : null,
                    'completed_at' => $request->status === 'ready-for-pickup' ? now()->toDateTimeString() : null,
                ])
                ->log("Repair job status updated from {$oldStatus} to {$request->status}");

            // Notify customer of key repair status changes
            if ($repairRequest->user_id) {
                try {
                    $notificationService = app(NotificationService::class);
                    $repairData = [
                        'order_number' => $repairRequest->request_id,
                        'repair_id'    => $repairRequest->id,
                        'status'       => $request->status,
                    ];
                    if ($request->status === 'in-progress') {
                        $notificationService->notifyRepairInProgress($repairRequest->user_id, $repairData);
                    } elseif (in_array($request->status, ['ready-for-pickup', 'ready_for_pickup'])) {
                        $notificationService->notifyRepairReadyForPickup($repairRequest->user_id, $repairData);
                    } elseif ($request->status === 'completed') {
                        $notificationService->notifyRepairCompleted($repairRequest->user_id, $repairData);
                    }
                } catch (\Exception $e) {
                    \Log::warning('Could not notify customer of repair status change: ' . $e->getMessage());
                }
            }

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

        // Legacy normalization: old payment flows could leave paid repairs stuck in
        // owner_approval_pending. Owner approval is now only used for rejection workflows.
        RepairRequest::query()
            ->where('user_id', $user->id)
            ->where('status', 'owner_approval_pending')
            ->whereIn('payment_status', ['paid', 'completed'])
            ->whereNull('repairer_rejected_at')
            ->whereNull('manager_decision')
            ->whereNull('owner_decision')
            ->update(['status' => 'pending']);

        $query = RepairRequest::with([
                'services',
                'shopOwner',
                'repairer',
            'parentRepairRequest:id,total_paid_amount',
                'materialUsages.inventoryItem:id,price',
                'latestPosTransaction:id,metadata',
                'posTransactions:id,module_reference_id,module_type,paid_amount,status',
                'posTransactions.paymentLines:id,pos_transaction_id,tender_type,provider_reference,amount,status',
            ])
            ->withSum([
                'posTransactions as pos_paid_amount_ledger' => function ($builder) {
                    $builder->whereIn('status', ['paid', 'partially_refunded', 'refunded']);
                },
            ], 'paid_amount')
            ->forCustomer($user->id);

        // Filter by status if provided
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $repairRequests = $query->orderBy('created_at', 'desc')->get();

        if ($request->boolean('reconcile_payments')) {
            $settlementService = app(PaymentSettlementService::class);
            $hasReconciledChanges = false;

            foreach ($repairRequests as $repair) {
                if ($this->reconcilePendingRepairPaymentWithGateway($repair, $settlementService)) {
                    $hasReconciledChanges = true;
                }
            }

            if ($hasReconciledChanges) {
                $repairRequests = $query->orderBy('created_at', 'desc')->get();
            }
        }

        $repairIds = $repairRequests->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->all();

        $parentRepairIds = $repairRequests->pluck('parent_repair_request_id')
            ->filter(fn ($value) => (int) $value > 0)
            ->map(fn ($value) => (int) $value)
            ->all();

        $allRelevantRepairIds = array_values(array_unique(array_merge($repairIds, $parentRepairIds)));

        $logisticsShipmentLookup = Shipment::query()
            ->where('source_type', 'repair_request')
            ->whereIn('source_id', $allRelevantRepairIds)
            ->orderByDesc('id')
            ->get(['id', 'source_id', 'purpose'])
            ->groupBy('source_id')
            ->map(fn ($shipments) => $shipments->map(fn (Shipment $shipment) => [
                'id' => (int) $shipment->id,
                'purpose' => $shipment->purpose,
            ])->values()->all())
            ->all();

        $childrenByParent = [];
        foreach ($repairRequests as $repairRow) {
            $parentId = (int) ($repairRow->parent_repair_request_id ?? 0);
            if ($parentId <= 0) {
                continue;
            }

            $childrenByParent[$parentId] ??= [];
            $childrenByParent[$parentId][] = (int) $repairRow->id;
        }

        $reviewedRepairIds = [];
        if (!empty($allRelevantRepairIds)) {
            $reviewedRepairIds = RepairReview::query()
                ->whereIn('repair_request_id', $allRelevantRepairIds)
                ->pluck('repair_request_id')
                ->map(fn ($value) => (int) $value)
                ->all();
        }

        $reviewedLookup = array_fill_keys($reviewedRepairIds, true);

        return response()->json([
            'success' => true,
            'data' => $repairRequests->map(function (RepairRequest $repair) use ($childrenByParent, $reviewedLookup, $logisticsShipmentLookup) {
                // Images are already cast as array, so no need to json_decode
                $images = is_array($repair->images) ? $repair->images : (is_string($repair->images) ? json_decode($repair->images, true) : []);
                $pricingSnapshot = $this->calculateRepairPricingSnapshot($repair);
                $taxMode = $this->resolveRepairTaxMode($repair);
                $taxSummary = $this->calculateRepairTaxSummary((float) $pricingSnapshot['final_total'], $taxMode);
                $storedPaidAmount = round((float) ($repair->total_paid_amount ?? 0), 2);
                $posLedgerPaidAmount = round((float) ($repair->pos_paid_amount_ledger ?? 0), 2);
                $resolvedPaidAmount = $this->resolveCustomerFacingTotalPaidAmount(
                    $repair,
                    (float) $taxSummary['grand_total'],
                    $storedPaidAmount,
                    $posLedgerPaidAmount,
                );
                $parentStoredPaidAmount = round((float) ($repair->parentRepairRequest?->total_paid_amount ?? 0), 2);
                $displayPaidAmount = (bool) ($repair->is_warranty_job ?? false)
                    ? round(max($resolvedPaidAmount, $parentStoredPaidAmount), 2)
                    : $resolvedPaidAmount;
                $refundPaymentProfile = $this->resolveRefundPaymentProfile($repair, $resolvedPaidAmount);

                $anchorRepairId = ((bool) ($repair->is_warranty_job ?? false) && (int) ($repair->parent_repair_request_id ?? 0) > 0)
                    ? (int) $repair->parent_repair_request_id
                    : (int) $repair->id;

                $relatedRepairIds = array_values(array_unique(array_merge(
                    [$anchorRepairId],
                    $childrenByParent[$anchorRepairId] ?? []
                )));

                $hasReview = false;
                foreach ($relatedRepairIds as $relatedRepairId) {
                    if (isset($reviewedLookup[(int) $relatedRepairId])) {
                        $hasReview = true;
                        break;
                    }
                }

                return [
                    'id' => $repair->id,
                    'order_number' => $repair->request_id,
                    'repair_type' => $repair->services->pluck('name')->join(', '),
                    'services' => $repair->services->map(fn (RepairService $service) => [
                        'id' => (int) $service->id,
                        'name' => $service->name,
                        'category' => $service->category,
                        'price' => (float) $service->price,
                        'duration' => $service->duration,
                    ])->values(),
                    'description' => $repair->description,
                    'status' => $repair->status,
                    'total_amount' => $pricingSnapshot['final_total'],
                    'created_at' => $repair->created_at->toISOString(),
                    'estimated_completion' => $repair->estimated_delivery_date
                        ? $repair->estimated_delivery_date->format('M d, Y')
                        : ($repair->scheduled_dropoff_date ? $repair->scheduled_dropoff_date->format('M d, Y') : null),
                    'estimated_delivery_date' => $repair->estimated_delivery_date ? $repair->estimated_delivery_date->format('M d, Y') : null,
                    'completed_at' => $repair->completed_at ? $repair->completed_at->format('M d, Y') : null,
                    'shop_id' => $repair->shop_owner_id,
                    'shop_owner_id' => $repair->shop_owner_id,
                    'shop_name' => $repair->shopOwner ? $repair->shopOwner->business_name : 'Unknown Shop',
                    'shop_address' => $repair->shopOwner ? $repair->shopOwner->business_address : '',
                    'image' => !empty($images) ? Storage::url($images[0]) : null,
                    'delivery_method' => $repair->delivery_method,
                    'pickup_address' => $repair->pickup_address,
                    'intake_delivery_method' => $repair->intake_delivery_method ?? ($repair->delivery_method === 'walk_in' ? 'walk_in' : 'customer_delivery'),
                    'intake_address' => $repair->intake_address ?? $repair->pickup_address,
                    'intake_delivery_fee' => (float) $repair->intake_delivery_fee,
                    'intake_logistics_quote' => $repair->intake_logistics_quote,
                    'intake_logistics_locked_at' => $repair->intake_logistics_locked_at?->toISOString(),
                    'return_delivery_method' => $repair->return_delivery_method ?? ($repair->delivery_method === 'walk_in' ? 'walk_in' : 'customer_pickup'),
                    'return_address' => $repair->return_address,
                    'return_delivery_fee' => (float) $repair->return_delivery_fee,
                    'return_logistics_quote' => $repair->return_logistics_quote,
                    'return_logistics_locked_at' => $repair->return_logistics_locked_at?->toISOString(),
                    'same_as_intake_address' => (bool) $repair->same_as_intake_address,
                    'return_address_confirmed_at' => $repair->return_address_confirmed_at?->toISOString(),
                    'return_address_confirmed_version' => $repair->return_address_confirmed_version,
                    'conversation_id' => $repair->conversation_id,
                    'payment_status' => $repair->payment_status ?? 'pending',
                    'payment_completed_at' => $repair->payment_completed_at ? $repair->payment_completed_at->toISOString() : null,
                    'paymongo_link_id' => $repair->paymongo_link_id,
                    'payment_enabled' => $repair->payment_enabled ?? false,
                    'payment_enabled_at' => $repair->payment_enabled_at ? $repair->payment_enabled_at->toISOString() : null,
                    'pickup_enabled' => $repair->pickup_enabled ?? false,
                    'pickup_enabled_at' => $repair->pickup_enabled_at ? $repair->pickup_enabled_at->toISOString() : null,
                    'tracking_number' => $repair->tracking_number,
                    'carrier_company' => $repair->carrier_company,
                    'carrier_name' => $repair->carrier_name,
                    'carrier_phone' => $repair->carrier_phone,
                    'tracking_link' => $repair->tracking_link,
                    'logistics_shipments' => $logisticsShipmentLookup[(int) $repair->id] ?? [],
                    'shipped_at' => $repair->shipped_at ? $repair->shipped_at->toISOString() : null,
                    'assigned_repairer_id' => $repair->assigned_repairer_id,
                    'repairer_name' => $repair->repairer ? $repair->repairer->name : null,
                    'payment_policy' => $repair->payment_policy ?? 'deposit_50',
                    'is_warranty_job' => (bool) ($repair->is_warranty_job ?? false),
                    'parent_repair_request_id' => $repair->parent_repair_request_id,
                    'billing_mode' => $repair->billing_mode,
                    'warranty_display_alias' => $repair->warranty_display_alias,
                    'repair_package_id' => $repair->repair_package_id,
                    'package_price' => $repair->package_price,
                    'add_ons_total' => $repair->add_ons_total,
                    'total_paid_amount' => $resolvedPaidAmount,
                    'display_total_paid_amount' => $displayPaidAmount,
                    'total_refunded_amount' => (float) $repair->total_refunded_amount,
                    'latest_pos_transaction_id' => $repair->latest_pos_transaction_id,
                    'refund_payment_type' => $refundPaymentProfile['payment_type'],
                    'refund_requires_payout_destination' => $refundPaymentProfile['requires_payout_destination'],
                    'refund_original_method_only' => $refundPaymentProfile['original_method_only'],
                    'has_review' => $hasReview,
                    'vat_rate' => self::REPAIR_VAT_RATE_PERCENT,
                    'vat_amount' => $taxSummary['vat_amount'],
                    'grand_total' => $taxSummary['grand_total'],
                    'tax_mode' => $taxMode,
                    'materials_total' => $pricingSnapshot['materials_total'],
                    'final_total' => $pricingSnapshot['final_total'],
                    'included_services_snapshot' => $repair->included_services_snapshot,
                    'add_on_services_snapshot' => $repair->add_on_services_snapshot,
                    'pricing_breakdown' => $pricingSnapshot['pricing_breakdown'],
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

        $repair = RepairRequest::with(['services', 'shopOwner', 'repairer', 'conversation', 'repairPackage', 'materialUsages.inventoryItem:id,price'])
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
        $pricingSnapshot = $this->calculateRepairPricingSnapshot($repair);
        
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
                'package_price' => $repair->package_price,
                'add_ons_total' => $repair->add_ons_total,
                'materials_total' => $pricingSnapshot['materials_total'],
                'final_total' => $pricingSnapshot['final_total'],
                'status' => $repair->status,
                'delivery_method' => $repair->delivery_method,
                'pickup_address' => $repair->pickup_address,
                'intake_delivery_method' => $repair->intake_delivery_method ?? ($repair->delivery_method === 'walk_in' ? 'walk_in' : 'customer_delivery'),
                'intake_address' => $repair->intake_address ?? $repair->pickup_address,
                'return_delivery_method' => $repair->return_delivery_method ?? ($repair->delivery_method === 'walk_in' ? 'walk_in' : 'customer_pickup'),
                'return_address' => $repair->return_address,
                'scheduled_dropoff_date' => $repair->scheduled_dropoff_date,
                'customer_confirmed_at' => $repair->customer_confirmed_at,
                'is_high_value' => $repair->is_high_value,
                'started_at' => $repair->started_at,
                'completed_at' => $repair->completed_at,
                'picked_up_at' => $repair->picked_up_at,
                'tracking_number' => $repair->tracking_number,
                'carrier_company' => $repair->carrier_company,
                'carrier_name' => $repair->carrier_name,
                'carrier_phone' => $repair->carrier_phone,
                'tracking_link' => $repair->tracking_link,
                'estimated_delivery_date' => $repair->estimated_delivery_date,
                'shipped_at' => $repair->shipped_at,
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
                'repair_package' => $repair->repairPackage ? [
                    'id' => $repair->repairPackage->id,
                    'name' => $repair->repairPackage->name,
                    'description' => $repair->repairPackage->description,
                ] : null,
                'included_services_snapshot' => $repair->included_services_snapshot,
                'add_on_services_snapshot' => $repair->add_on_services_snapshot,
                'pricing_breakdown' => $pricingSnapshot['pricing_breakdown'],
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

    public function requestRefundFromMyRepair(Request $request, int $id, RepairPosRefundService $refundService)
    {
        $user = Auth::guard('user')->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $repair = RepairRequest::query()
            ->where('id', $id)
            ->forCustomer($user->id)
            ->first();

        if (!$repair) {
            return response()->json([
                'success' => false,
                'message' => 'Repair request not found',
            ], 404);
        }

        $anchorRepairId = ((bool) ($repair->is_warranty_job ?? false) && (int) ($repair->parent_repair_request_id ?? 0) > 0)
            ? (int) $repair->parent_repair_request_id
            : (int) $repair->id;

        $relatedRepairIds = RepairRequest::query()
            ->where('user_id', (int) $user->id)
            ->where(function ($query) use ($anchorRepairId) {
                $query->where('id', $anchorRepairId)
                    ->orWhere('parent_repair_request_id', $anchorRepairId);
            })
            ->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->all();

        if (empty($relatedRepairIds)) {
            $relatedRepairIds = [$anchorRepairId];
        }

        $hasReview = RepairReview::query()
            ->whereIn('repair_request_id', $relatedRepairIds)
            ->exists();

        if ($hasReview) {
            return response()->json([
                'success' => false,
                'message' => 'Refund request is no longer allowed because a review has already been submitted for this repair.',
            ], 422);
        }

        if ($request->has('customer_payout_consent')) {
            $rawConsent = $request->input('customer_payout_consent');

            if (is_string($rawConsent)) {
                $normalizedConsent = filter_var($rawConsent, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($normalizedConsent !== null) {
                    $request->merge(['customer_payout_consent' => $normalizedConsent]);
                }
            }
        }

        $validated = $request->validate([
            'source_transaction_id' => ['nullable', 'integer', 'exists:pos_transactions,id'],
            'request_type' => ['required', 'in:full,partial'],
            'requested_amount' => ['required', 'numeric', 'min:0.01'],
            'reason_code' => ['required', 'string', 'max:80'],
            'reason_notes' => ['nullable', 'string', 'max:2000'],
            'evidence' => ['nullable', 'array', 'min:1'],
            'evidence.*.type' => ['required_with:evidence', 'in:photo,video'],
            'evidence.*.url' => ['required_with:evidence', 'url'],
            'media' => ['nullable', 'array', 'min:1'],
            'media.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/x-msvideo,video/x-matroska,video/webm', 'max:262144'],
            'preferred_return_channel' => ['nullable', 'in:gcash,card,bank_transfer,manual_cash'],
            'preferred_return_account_name' => ['nullable', 'string', 'max:120'],
            'preferred_return_account_ref' => ['nullable', 'string', 'max:120'],
            'customer_payout_consent' => ['nullable', 'boolean'],
        ]);

        $uploadedEvidence = [];
        if ($request->hasFile('media')) {
            $maxImageBytes = 20 * 1024 * 1024;
            $maxVideoBytes = 256 * 1024 * 1024;

            foreach (($request->file('media') ?? []) as $index => $mediaFile) {
                if (!$mediaFile) {
                    continue;
                }

                $mimeType = strtolower((string) $mediaFile->getMimeType());
                $isVideo = str_starts_with($mimeType, 'video/');
                $maxAllowedBytes = $isVideo ? $maxVideoBytes : $maxImageBytes;
                $fileSize = (int) ($mediaFile->getSize() ?? 0);

                if ($fileSize > $maxAllowedBytes) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "media.{$index}" => [
                            $isVideo
                                ? 'Video evidence must be 256MB or smaller.'
                                : 'Image evidence must be 20MB or smaller.',
                        ],
                    ]);
                }

                $path = $mediaFile->store('refund-evidence/repair-' . $repair->id, 'public');
                $uploadedEvidence[] = [
                    'type' => $isVideo ? 'video' : 'photo',
                    'url' => Storage::url($path),
                ];
            }
        }

        $evidenceSnapshot = !empty($uploadedEvidence)
            ? $uploadedEvidence
            : (is_array($validated['evidence'] ?? null) ? $validated['evidence'] : []);

        if (count($evidenceSnapshot) < 1) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'evidence' => ['Please upload at least one photo or video evidence file.'],
            ]);
        }

        $pricingSnapshot = $this->calculateRepairPricingSnapshot($repair);
        $taxMode = $this->resolveRepairTaxMode($repair);
        $taxSummary = $this->calculateRepairTaxSummary((float) ($pricingSnapshot['final_total'] ?? 0), $taxMode);
        $storedPaidAmount = round((float) ($repair->total_paid_amount ?? 0), 2);
        $posLedgerPaidAmount = round((float) PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->sum('paid_amount'), 2);
        $resolvedPaidAmount = $this->resolveCustomerFacingTotalPaidAmount(
            $repair,
            (float) ($taxSummary['grand_total'] ?? 0),
            $storedPaidAmount,
            $posLedgerPaidAmount,
        );
        $refundPaymentProfile = $this->resolveRefundPaymentProfile($repair, $resolvedPaidAmount);
        $isOriginalMethodOnly = (bool) ($refundPaymentProfile['original_method_only'] ?? false);
        $paymentType = (string) ($refundPaymentProfile['payment_type'] ?? 'mixed');

        $requestedSourceTransactionId = (int) ($validated['source_transaction_id'] ?? 0);
        $sourceTransaction = PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->when($requestedSourceTransactionId > 0, fn ($query) => $query->where('id', $requestedSourceTransactionId))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->first();

        if (!$sourceTransaction) {
            $sourceTransaction = $this->backfillPureOnlineRepairSourceTransaction(
                repair: $repair,
                actorUserId: (int) $user->id,
                paymentType: $paymentType,
                requestedSourceTransactionId: $requestedSourceTransactionId,
                resolvedPaidAmount: $resolvedPaidAmount,
            );
        }

        if (!$sourceTransaction) {
            return response()->json([
                'success' => false,
                'message' => 'No paid transaction record found for this repair request.',
            ], 422);
        }

        $resolvedPreferredReturnChannel = $validated['preferred_return_channel'] ?? null;
        $resolvedPreferredReturnAccountName = $validated['preferred_return_account_name'] ?? null;
        $resolvedPreferredReturnAccountRef = $validated['preferred_return_account_ref'] ?? null;
        $resolvedCustomerPayoutConsent = (bool) ($validated['customer_payout_consent'] ?? false);

        if ($isOriginalMethodOnly) {
            $resolvedPreferredReturnChannel = null;
            $resolvedPreferredReturnAccountName = null;
            $resolvedPreferredReturnAccountRef = null;
            $resolvedCustomerPayoutConsent = false;
        } elseif ($paymentType === 'manual_only') {
            // Pure walk-in refunds are not PayMongo-connected.
            // Force payout channel to GCash and require destination details.
            $resolvedPreferredReturnChannel = 'gcash';

            if (trim((string) $resolvedPreferredReturnAccountRef) === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'preferred_return_account_ref' => ['GCash number or reference is required for walk-in payment refunds.'],
                ]);
            }

            if (trim((string) $resolvedPreferredReturnAccountName) === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'preferred_return_account_name' => ['Account name is required for walk-in payment refunds.'],
                ]);
            }

            if (!$resolvedCustomerPayoutConsent) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'customer_payout_consent' => ['Please confirm payout destination details for walk-in payment refunds.'],
                ]);
            }
        } elseif ($paymentType === 'mixed') {
            $allowedMixedChannels = ['gcash', 'card', 'bank_transfer'];

            if (!in_array((string) ($resolvedPreferredReturnChannel ?? ''), $allowedMixedChannels, true)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'preferred_return_channel' => ['Choose a payout channel (GCash, Card, or Bank Transfer) for the POS-paid portion of mixed refunds.'],
                ]);
            }

            if (trim((string) $resolvedPreferredReturnAccountRef) === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'preferred_return_account_ref' => ['Account reference/number is required for the POS-paid portion of mixed refunds.'],
                ]);
            }

            if (trim((string) $resolvedPreferredReturnAccountName) === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'preferred_return_account_name' => ['Account name is required for the POS-paid portion of mixed refunds.'],
                ]);
            }

            if (!$resolvedCustomerPayoutConsent) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'customer_payout_consent' => ['Please confirm payout destination details for the POS-paid portion of mixed refunds.'],
                ]);
            }
        }

        $serverRefundableAmount = round($refundService->computeRepairRefundableAmount((int) $repair->id), 2);
        if ($serverRefundableAmount <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'requested_amount' => ['No refundable balance is available for this repair request.'],
            ]);
        }

        $requestedAmount = round((float) ($validated['requested_amount'] ?? 0), 2);
        if ((string) ($validated['request_type'] ?? 'full') === 'full') {
            // My Repairs full refund always follows the latest server-side refundable balance.
            $requestedAmount = $serverRefundableAmount;
        } elseif ($requestedAmount > $serverRefundableAmount) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'requested_amount' => [
                    sprintf('Requested amount exceeds refundable balance. Available refundable amount is %.2f.', $serverRefundableAmount),
                ],
            ]);
        }

        $refund = DB::transaction(function () use (
            $refundService,
            $sourceTransaction,
            $validated,
            $user,
            $repair,
            $evidenceSnapshot,
            $requestedAmount,
            $resolvedPreferredReturnChannel,
            $resolvedPreferredReturnAccountName,
            $resolvedPreferredReturnAccountRef,
            $resolvedCustomerPayoutConsent
        ) {
            $paymentReferences = collect(is_array($repair->paymongo_payment_ids) ? $repair->paymongo_payment_ids : [])
                ->push((string) ($repair->paymongo_payment_id ?? ''))
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn ($value) => $value !== '')
                ->unique()
                ->values()
                ->all();

            $refund = $refundService->createRefundWithSplitLegs($sourceTransaction, [
                'workflow_source' => 'online_myrepair',
                'request_type' => $validated['request_type'],
                'requested_amount' => $requestedAmount,
                'reason_code' => $validated['reason_code'],
                'reason_notes' => $validated['reason_notes'] ?? null,
                'paymongo_payment_id' => $repair->paymongo_payment_id,
                'paymongo_payment_ids' => $paymentReferences,
                'preferred_return_channel' => $resolvedPreferredReturnChannel,
                'preferred_return_account_name' => $resolvedPreferredReturnAccountName,
                'preferred_return_account_ref' => $resolvedPreferredReturnAccountRef,
                'customer_payout_consent' => $resolvedCustomerPayoutConsent,
            ], (int) $user->id);

            $refund->update([
                'repairer_status' => 'pending',
                'evidence_snapshot' => $evidenceSnapshot,
            ]);

            return $refund->fresh();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $refund->id,
                'status' => $refund->status,
                'repairer_status' => $refund->repairer_status,
                'finance_status' => $refund->finance_status,
                'shop_owner_status' => $refund->shop_owner_status,
                'workflow_source' => $refund->workflow_source,
                'requested_amount' => (float) $refund->requested_amount,
                'reason_code' => $refund->reason_code,
                'evidence_snapshot' => $refund->evidence_snapshot,
            ],
        ]);
    }

    /**
     * Cancel repair request
     */
    public function cancel(Request $request, $id, RepairPosRefundService $refundService)
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

        DB::transaction(function () use ($repair, $refundService, $user): void {
            $repair->update([
                'status' => 'cancelled',
            ]);

            if ((float) $repair->total_paid_amount <= 0 || !$repair->latest_pos_transaction_id) {
                return;
            }

            $sourceTransaction = PosTransaction::query()->find((int) $repair->latest_pos_transaction_id);
            if (!$sourceTransaction) {
                return;
            }

            $requestedAmount = $refundService->computeRepairRefundableAmount((int) $repair->id);
            if ($requestedAmount <= 0) {
                return;
            }

            $refundService->requestRefund($sourceTransaction, [
                'request_type' => 'full',
                'requested_amount' => $requestedAmount,
                'reason_code' => 'customer_cancelled_repair',
                'reason_notes' => 'Auto-created when customer cancelled a paid repair request.',
            ], (int) $user->id);
        });

        return response()->json([
            'success' => true,
            'message' => 'Repair request cancelled successfully'
        ]);
    }

    /**
     * Set the preferred drop-off date — called from myRepairs "Set Your Schedule" modal.
     * Only allowed after the repairer has accepted the request.
     *
     * PATCH /api/customer/repairs/{id}/schedule
     */
    public function setSchedule(Request $request, $id)
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $repair = RepairRequest::where('id', $id)
            ->forCustomer($user->id)
            ->first();

        if (!$repair) {
            return response()->json(['success' => false, 'message' => 'Repair request not found'], 404);
        }

        if (!in_array($repair->status, ['repairer_accepted', 'pending'])) {
            return response()->json([
                'success' => false,
                'message' => 'You can only set a schedule before the repair is in progress.',
            ], 422);
        }

        $validated = $request->validate([
            'preferred_date' => 'required|date|after:today',
        ]);

        $repair->update([
            'scheduled_dropoff_date' => \Carbon\Carbon::parse($validated['preferred_date'])->startOfDay(),
        ]);

        // Notify the assigned repairer
        if ($repair->assigned_repairer_id) {
            try {
                $notificationService = app(NotificationService::class);
                $notificationService->sendToUser(
                    userId: $repair->assigned_repairer_id,
                    type: \App\Enums\NotificationType::REPAIR_ASSIGNED_TO_ME,
                    title: 'Customer Set Drop-off Date',
                    message: "Customer set drop-off date for repair {$repair->request_id} on " . \Carbon\Carbon::parse($validated['preferred_date'])->format('M d, Y'),
                    data: [
                        'repair_id' => $repair->id,
                        'repair_request_id' => $repair->id,
                        'request_id' => $repair->request_id,
                        'order_number' => $repair->request_id,
                        'customer_name' => $repair->customer_name,
                    ],
                    actionUrl: '/erp/staff/job-orders-repair',
                    priority: 'medium',
                    requiresAction: false
                );
            } catch (\Exception $e) {
                \Log::warning('Could not notify repairer of schedule: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'preferred_date' => $repair->fresh()->scheduled_dropoff_date->format('M d, Y'),
        ]);
    }

    /**
     * Change the delivery method for the authenticated customer's repair.
     * Allowed while the repair is ready for pickup, before final receipt confirmation.
     */
    public function changeDeliveryMethod(Request $request, $id)
    {
        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $validated = $request->validate([
            'delivery_method' => ['nullable', 'in:walk_in,pickup,customer_pickup,shop_delivery'],
            'intake_delivery_method' => ['nullable', 'in:walk_in,customer_delivery,shop_pickup'],
            'intake_address_id' => ['nullable', 'integer'],
            'return_delivery_method' => ['nullable', 'in:walk_in,pickup,customer_pickup,shop_delivery'],
            'return_address_id' => ['nullable', 'integer'],
            'same_as_intake_address' => ['nullable', 'boolean'],
            'return_address_line' => ['nullable', 'string', 'max:255'],
            'return_barangay' => ['nullable', 'string', 'max:255'],
            'return_city' => ['nullable', 'string', 'max:255'],
            'return_region' => ['nullable', 'string', 'max:255'],
            'return_postal_code' => ['nullable', 'string', 'max:10'],
        ]);

        $hasIntakeUpdate = array_key_exists('intake_delivery_method', $validated)
            || array_key_exists('intake_address_id', $validated);
        $hasReturnUpdate = array_key_exists('return_delivery_method', $validated)
            || array_key_exists('delivery_method', $validated)
            || array_key_exists('return_address_id', $validated)
            || array_key_exists('same_as_intake_address', $validated);

        if (! $hasIntakeUpdate && ! $hasReturnUpdate) {
            return response()->json([
                'success' => false,
                'message' => 'At least one delivery-plan field is required.',
            ], 422);
        }

        return DB::transaction(function () use ($id, $user, $validated, $hasIntakeUpdate, $hasReturnUpdate) {
            $repair = RepairRequest::query()
                ->with('shopOwner')
                ->whereKey($id)
                ->forCustomer($user->id)
                ->lockForUpdate()
                ->first();

            if (! $repair) {
                return response()->json([
                    'success' => false,
                    'message' => 'Repair request not found',
                ], 404);
            }
            $shopSponsoredWarranty = (bool) ($repair->is_warranty_job ?? false)
                || (string) ($repair->billing_mode ?? '') === 'warranty_no_charge';
            if ($hasIntakeUpdate && $repair->intake_logistics_locked_at !== null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'intake_delivery_method' => ['The paid intake delivery plan is locked and can no longer be changed.'],
                ]);
            }
            if ($hasReturnUpdate && $repair->return_logistics_locked_at !== null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'return_delivery_method' => ['The paid return delivery plan is locked and can no longer be changed.'],
                ]);
            }

            $delivery = app(RepairDeliveryService::class);
            $sameAsIntake = array_key_exists('same_as_intake_address', $validated)
                ? (bool) $validated['same_as_intake_address']
                : (bool) $repair->same_as_intake_address;
            $buildPlan = function (string $leg, string $method, int $addressId) use ($delivery, $repair, $user): array {
                if ($method === 'walk_in') {
                    return [null, 0.0, null];
                }

                $address = $user->addresses()->find($addressId);
                if (! $address) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "{$leg}_address_id" => ['Choose one of your saved addresses.'],
                    ]);
                }

                $snapshot = $delivery->snapshot($address, $method);
                $quote = null;
                $fee = 0.0;
                $shopOwned = $method === ($leg === 'intake' ? 'shop_pickup' : 'shop_delivery');
                if ($shopOwned) {
                    $quote = $delivery->quote($repair->shopOwner, $address);
                    if (! ($quote['available'] ?? false)) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            "{$leg}_address_id" => ['The selected address is not covered by shop-owned logistics.'],
                        ]);
                    }
                    $fee = (float) $quote['fee'];
                    $quote['address_version'] = $snapshot['version'];
                    $quote['method'] = $method;
                }

                return [$snapshot, $fee, $quote];
            };

            $intakeMethod = (string) ($validated['intake_delivery_method']
                ?? $repair->intake_delivery_method
                ?? ($repair->delivery_method === 'walk_in' ? 'walk_in' : 'customer_delivery'));
            $intakeSnapshot = $repair->intake_address;
            $intakeFee = (float) $repair->intake_delivery_fee;
            $intakeQuote = $repair->intake_logistics_quote;
            if ($hasIntakeUpdate) {
                $intakeAddressId = (int) ($validated['intake_address_id']
                    ?? data_get($repair->intake_address, 'address_id')
                    ?? 0);
                [$intakeSnapshot, $intakeFee, $intakeQuote] = $buildPlan(
                    'intake',
                    $intakeMethod,
                    $intakeAddressId,
                );
            }
            $intakeChanged = $hasIntakeUpdate
                && ((string) ($repair->intake_delivery_method ?? '') !== $intakeMethod
                    || (string) data_get($repair->intake_address, 'version', '') !== (string) data_get($intakeSnapshot, 'version', '')
                    || round((float) $repair->intake_delivery_fee, 2) !== round($intakeFee, 2));

            $requestedReturnMethod = $validated['return_delivery_method'] ?? $validated['delivery_method'] ?? null;
            $returnMethod = (string) ($requestedReturnMethod
                ?? $repair->return_delivery_method
                ?? ($repair->delivery_method === 'walk_in' ? 'walk_in' : 'customer_pickup'));
            if ($returnMethod === 'pickup') {
                $returnMethod = 'customer_pickup';
            }
            $refreshLinkedReturn = $sameAsIntake
                && $repair->return_logistics_locked_at === null
                && $intakeMethod !== 'walk_in'
                && ($intakeChanged || array_key_exists('same_as_intake_address', $validated));
            $rebuildReturn = $hasReturnUpdate || $refreshLinkedReturn;
            $returnSnapshot = $repair->return_address;
            $returnFee = (float) $repair->return_delivery_fee;
            $returnQuote = $repair->return_logistics_quote;
            if ($rebuildReturn) {
                $returnAddressId = (int) ($validated['return_address_id']
                    ?? ($sameAsIntake ? data_get($intakeSnapshot, 'address_id') : null)
                    ?? data_get($repair->return_address, 'address_id')
                    ?? 0);
                [$returnSnapshot, $returnFee, $returnQuote] = $buildPlan(
                    'return',
                    $returnMethod,
                    $returnAddressId,
                );
            }
            $returnChanged = $rebuildReturn
                && ((string) ($repair->return_delivery_method ?? '') !== $returnMethod
                    || (string) data_get($repair->return_address, 'version', '') !== (string) data_get($returnSnapshot, 'version', '')
                    || round((float) $repair->return_delivery_fee, 2) !== round($returnFee, 2));
            $replannedAt = $shopSponsoredWarranty ? now() : null;

            $repair->update([
                ...($hasIntakeUpdate ? [
                    'delivery_method' => $intakeMethod === 'walk_in' ? 'walk_in' : 'pickup',
                    'pickup_address' => $intakeSnapshot,
                    'intake_delivery_method' => $intakeMethod,
                    'intake_address' => $intakeSnapshot,
                    'intake_delivery_fee' => $intakeFee,
                    'intake_logistics_quote' => $intakeQuote,
                    ...($shopSponsoredWarranty ? ['intake_logistics_locked_at' => $replannedAt] : []),
                ] : []),
                ...($rebuildReturn ? [
                    'return_delivery_method' => $returnMethod,
                    'return_address' => $returnSnapshot,
                    'return_delivery_fee' => $returnFee,
                    'return_logistics_quote' => $returnQuote,
                    ...($shopSponsoredWarranty ? [
                        'return_logistics_locked_at' => $replannedAt,
                        'return_address_confirmed_at' => $returnMethod === 'shop_delivery' ? $replannedAt : null,
                        'return_address_confirmed_version' => $returnMethod === 'shop_delivery'
                            ? data_get($returnSnapshot, 'version')
                            : null,
                    ] : []),
                ] : []),
                'same_as_intake_address' => $sameAsIntake,
                ...(! $shopSponsoredWarranty && $returnChanged ? [
                    'return_address_confirmed_at' => null,
                    'return_address_confirmed_version' => null,
                ] : []),
            ]);

            if ($intakeChanged) {
                RepairPaymentSession::query()
                    ->where('repair_request_id', $repair->id)
                    ->where('phase', 'initial')
                    ->where('status', 'pending')
                    ->get()
                    ->each(function (RepairPaymentSession $session) use ($intakeSnapshot, $intakeMethod, $intakeFee): void {
                        $matches = (string) ($session->snapshot_version ?? '') === (string) data_get($intakeSnapshot, 'version', '')
                            && (string) ($session->delivery_method ?? '') === $intakeMethod
                            && round((float) $session->delivery_amount, 2) === round($intakeFee, 2);
                        if (! $matches) {
                            $session->update(['status' => 'invalidated', 'invalidated_at' => now()]);
                        }
                    });
            }
            if ($returnChanged) {
                RepairPaymentSession::query()
                    ->where('repair_request_id', $repair->id)
                    ->where('phase', 'final')
                    ->where('status', 'pending')
                    ->get()
                    ->each(function (RepairPaymentSession $session) use ($returnSnapshot, $returnMethod, $returnFee): void {
                        $matches = (string) ($session->snapshot_version ?? '') === (string) data_get($returnSnapshot, 'version', '')
                            && (string) ($session->delivery_method ?? '') === $returnMethod
                            && round((float) $session->delivery_amount, 2) === round($returnFee, 2);
                        if (! $matches) {
                            $session->update(['status' => 'invalidated', 'invalidated_at' => now()]);
                        }
                    });
            }

            $updatedRepair = $repair->fresh();

            return response()->json([
                'success' => true,
                'delivery_method' => $updatedRepair->delivery_method,
                'intake_delivery_method' => $updatedRepair->intake_delivery_method,
                'intake_address' => $updatedRepair->intake_address,
                'intake_delivery_fee' => (float) $updatedRepair->intake_delivery_fee,
                'intake_logistics_quote' => $updatedRepair->intake_logistics_quote,
                'return_delivery_method' => $updatedRepair->return_delivery_method,
                'return_address' => $updatedRepair->return_address,
                'return_delivery_fee' => (float) $updatedRepair->return_delivery_fee,
                'return_logistics_quote' => $updatedRepair->return_logistics_quote,
                'same_as_intake_address' => (bool) $updatedRepair->same_as_intake_address,
                'message' => 'Delivery plan updated.',
            ]);
        }, 3);
    }

    public function confirmReturnAddress(Request $request, $id)
    {
        $user = Auth::guard('user')->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $repair = DB::transaction(function () use ($id, $user): RepairRequest {
            $repair = RepairRequest::query()
                ->whereKey($id)
                ->forCustomer($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $version = (string) data_get($repair->return_address, 'version', '');
            if ($version === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'return_address' => ['Choose a saved return address before confirming delivery.'],
                ]);
            }

            if ($repair->return_logistics_locked_at !== null) {
                $paidSession = RepairPaymentSession::query()
                    ->where('repair_request_id', $repair->id)
                    ->where('phase', 'final')
                    ->where('status', 'paid')
                    ->latest('id')
                    ->first();
                $paidPos = PosTransaction::query()
                    ->where('module_type', 'repair')
                    ->where('module_reference_id', $repair->id)
                    ->where('due_type', 'balance')
                    ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
                    ->latest('id')
                    ->first();
                $paidVersion = $paidSession?->snapshot_version
                    ?? data_get($paidPos?->metadata, 'snapshot_version');
                $paidMethod = $paidSession?->delivery_method
                    ?? data_get($paidPos?->metadata, 'delivery_method');
                $paidAmount = $paidSession?->delivery_amount
                    ?? data_get($paidPos?->metadata, 'delivery_amount');

                if ($paidVersion === null
                    || (string) $paidVersion !== $version
                        || (string) $paidMethod !== (string) $repair->return_delivery_method
                        || round((float) $paidAmount, 2) !== round((float) $repair->return_delivery_fee, 2)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'return_address' => ['The paid return delivery plan does not match the current address version.'],
                    ]);
                }
            }

            $repair->update([
                'return_address_confirmed_at' => now(),
                'return_address_confirmed_version' => $version,
            ]);

            return $repair->fresh();
        }, 3);

        $shipment = app(RepairDeliveryService::class)->tryCreateReturnShipment($repair);

        return response()->json([
            'success' => true,
            'message' => 'Return address and delivery plan confirmed.',
            'return_address_confirmed_at' => $repair->return_address_confirmed_at?->toISOString(),
            'return_address_confirmed_version' => $repair->return_address_confirmed_version,
            'shipment_id' => $shipment?->id,
        ]);
    }

    public function updateExternalTracking(Request $request, $id)
    {
        $user = Auth::guard('user')->user();
        abort_unless($user, 401);

        $validated = $request->validate([
            'leg' => ['required', 'in:intake,return'],
            'carrier' => ['required', 'string', 'max:100'],
            'tracking_number' => ['required', 'string', 'max:100'],
            'tracking_url' => ['nullable', 'url', 'max:500'],
        ]);

        $repair = DB::transaction(function () use ($id, $user, $validated): RepairRequest {
            $repair = RepairRequest::query()
                ->whereKey($id)
                ->forCustomer($user->id)
                ->lockForUpdate()
                ->firstOrFail();
            $isIntake = $validated['leg'] === 'intake';
            $method = (string) ($isIntake
                ? $repair->intake_delivery_method
                : $repair->return_delivery_method);
            $requiredMethod = $isIntake ? 'customer_delivery' : 'customer_pickup';
            $lockField = $isIntake ? 'intake_logistics_locked_at' : 'return_logistics_locked_at';
            $addressField = $isIntake ? 'intake_address' : 'return_address';

            if ($method !== $requiredMethod) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'leg' => ['External tracking is available only for customer-arranged delivery.'],
                ]);
            }
            if ($repair->{$lockField} !== null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'leg' => ['This delivery handoff is locked and tracking can no longer be changed.'],
                ]);
            }

            $snapshot = is_array($repair->{$addressField}) ? $repair->{$addressField} : [];
            $snapshot['external_tracking'] = [
                'carrier' => $validated['carrier'],
                'tracking_number' => $validated['tracking_number'],
                'tracking_url' => $validated['tracking_url'] ?? null,
                'updated_at' => now()->toISOString(),
            ];
            $repair->update([$addressField => $snapshot]);

            return $repair->fresh();
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'Tracking details saved.',
            'data' => $repair,
        ]);
    }

    /**
     * Confirm customer receipt after the shop records the exact return handoff.
     */
    public function confirmPickup(Request $request, $id, RepairDeliveryService $repairDeliveryService)
    {
        $user = Auth::guard('user')->user();
        abort_unless($user, 401);

        $repair = DB::transaction(function () use ($id, $user, $repairDeliveryService): RepairRequest {
            $repair = RepairRequest::query()
                ->whereKey($id)
                ->forCustomer($user->id)
                ->lockForUpdate()
                ->firstOrFail();
            $method = match ((string) ($repair->return_delivery_method
                ?: (($repair->delivery_method ?? null) === 'walk_in' ? 'walk_in' : 'customer_pickup'))) {
                'pickup' => 'customer_pickup',
                default => (string) ($repair->return_delivery_method
                    ?: (($repair->delivery_method ?? null) === 'walk_in' ? 'walk_in' : 'customer_pickup')),
            };
            $allowedStatuses = $method === 'walk_in'
                ? ['ready_for_pickup', 'ready-for-pickup']
                : ['shipped'];

            if (! in_array((string) $repair->status, $allowedStatuses, true)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => ['This repair is not awaiting customer receipt confirmation.'],
                ]);
            }
            if (! (bool) $repair->pickup_enabled || $repair->return_logistics_locked_at === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => ['The shop has not recorded the return handoff yet.'],
                ]);
            }
            if ($method === 'shop_delivery'
                && ! $repairDeliveryService->hasApprovedProof($repair, 'repair_return')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'proof' => ['Dispatcher approval of the rider delivery proof is required before receipt confirmation.'],
                ]);
            }

            $repair->update([
                'status' => 'picked_up',
                'picked_up_at' => now(),
            ]);

            try {
                $this->autoGenerateInvoiceForPickedUpRepair($repair);
            } catch (\Throwable $invoiceError) {
                \Log::warning('Failed to auto-generate invoice for picked-up repair', [
                    'repair_id' => $repair->id,
                    'request_id' => $repair->request_id,
                    'error' => $invoiceError->getMessage(),
                ]);
            }

            return $repair->fresh(['services', 'shopOwner', 'repairer']);
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'Receipt confirmed. Thank you for choosing this repair shop.',
            'data' => $repair,
        ]);
    }
    
    public function updateServices(Request $request, int $id)
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $validated = $request->validate([
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'distinct', 'exists:repair_services,id'],
            'remove_package' => ['sometimes', 'boolean'],
        ]);

        $repair = DB::transaction(function () use ($validated, $id, $user) {
            $repair = RepairRequest::query()
                ->with('services:id,name')
                ->whereKey($id)
                ->forCustomer($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($repair->status !== 'repairer_accepted' || !$repair->conversation_id) {
                abort(409, 'Services can only be modified after repairer acceptance and before confirmation.');
            }

            $paidStatuses = [
                'paid',
                'completed',
                'down_payment_paid',
                'partially_paid',
                'partially_refunded',
                'refunded',
            ];
            $hasRecordedPayment = (float) $repair->total_paid_amount > 0
                || $repair->payment_completed_at
                || in_array(strtolower((string) $repair->payment_status), $paidStatuses, true)
                || $repair->posTransactions()
                    ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
                    ->exists();

            if ($hasRecordedPayment) {
                abort(409, 'Paid repairs can no longer be modified.');
            }

            if ($repair->repair_package_id && !($validated['remove_package'] ?? false)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'remove_package' => ['Remove the package before choosing individual services.'],
                ]);
            }

            $requestedIds = collect($validated['service_ids'])
                ->map(fn ($value) => (int) $value)
                ->unique()
                ->values();
            $services = RepairService::query()
                ->whereIn('id', $requestedIds)
                ->where('shop_owner_id', $repair->shop_owner_id)
                ->whereIn('status', ['Active', 'active'])
                ->get(['id', 'name', 'category', 'price', 'duration']);

            if ($services->count() !== $requestedIds->count()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'service_ids' => ['Every selected service must be active and belong to this repair shop.'],
                ]);
            }

            $oldNames = $repair->services->pluck('name');
            $newNames = $services->pluck('name');
            $total = round((float) $services->sum(
                fn (RepairService $service) => (float) $service->price
            ), 2);
            $shopOwner = ShopOwner::find($repair->shop_owner_id);
            $requiresOwnerApprovalByPolicy = $shopOwner
                ? $this->shopOwnerApprovalPolicyService->requiresOwnerApprovalForRepairReject(
                    (int) $shopOwner->id,
                    $total
                )
                : false;
            $isHighValue = (bool) ($shopOwner && (
                $total >= (float) $shopOwner->high_value_threshold
                || $requiresOwnerApprovalByPolicy
            ));
            $snapshot = $services->map(fn (RepairService $service) => [
                'id' => (int) $service->id,
                'name' => $service->name,
                'category' => $service->category,
                'price' => (float) $service->price,
                'duration' => $service->duration,
            ])->values()->all();

            $repair->services()->sync($services->pluck('id')->all());
            $repair->update([
                'repair_package_id' => null,
                'package_price' => null,
                'add_ons_total' => 0,
                'total' => $total,
                'final_total' => $total,
                'included_services_snapshot' => $snapshot,
                'add_on_services_snapshot' => null,
                'is_high_value' => $isHighValue,
                'requires_owner_approval' => (bool) ($shopOwner
                    && $shopOwner->require_two_way_approval
                    && $requiresOwnerApprovalByPolicy),
                'pricing_breakdown' => [
                    'mode' => 'services',
                    'package_id' => null,
                    'package_name' => null,
                    'included_services_total' => $total,
                    'package_price' => null,
                    'add_ons_total' => 0,
                    'base_total' => $total,
                    'materials_total' => 0,
                    'final_total' => $total,
                    'add_on_count' => 0,
                    'tax_mode' => 'vat_inclusive',
                ],
                'paymongo_link_id' => null,
                'payment_link_created_at' => null,
                'payment_expires_at' => null,
                'payment_failed_at' => null,
                'payment_failure_reason' => null,
                'payment_expired_at' => null,
            ]);

            $added = $newNames->diff($oldNames)->values()->join(', ') ?: 'None';
            $removed = $oldNames->diff($newNames)->values()->join(', ') ?: 'None';
            $message = ConversationMessage::create([
                'conversation_id' => $repair->conversation_id,
                'sender_type' => 'system',
                'sender_id' => $user->id,
                'content' => "Services updated by customer.\n\n"
                    . "Added: {$added}\n"
                    . "Removed: {$removed}\n"
                    . 'New total: PHP ' . number_format($total, 2),
            ]);
            Conversation::whereKey($repair->conversation_id)
                ->update(['last_message_at' => $message->created_at]);

            return $repair->fresh('services:id,name,category,price,duration');
        });

        return response()->json([
            'success' => true,
            'message' => 'Repair services updated.',
            'data' => $repair,
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

            // Always confirm to pending status (owner approval only applies to rejections, not payment workflow)
            $repair->update([
                'status' => 'pending',
                'customer_confirmed_at' => now()
            ]);

            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Repair confirmed. Proceed to payment.',
                'data' => $repair->fresh(['services', 'shopOwner', 'repairer']),
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

            $paymentSession = $repair->paymentSessions()
                ->where('provider', 'paymongo')
                ->where('provider_link_id', $request->paymongo_link_id)
                ->where('status', 'pending')
                ->whereNull('invalidated_at')
                ->first();

            if (! $paymentSession) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => [
                        'paymongo_link_id' => ['The payment link was not created by the server for this repair phase.'],
                    ],
                ], 422);
            }

            $policy = $this->normalizeRepairPaymentPolicy($repair->payment_policy ?? 'deposit_50');

            if ($this->isRepairPaymentSettled($repair, $policy)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Repair is already fully paid'
                ], 409);
            }

            if (!$repair->payment_enabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment is not enabled for this repair request yet.'
                ], 409);
            }

            if (!$this->isRepairPaymentDueNow($repair, $policy)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This repair has no payable phase right now.'
                ], 409);
            }

            $nextPaymentStatus = $this->nextRepairPaymentStatusForRetry($repair, $policy);

            $repair->update([
                'paymongo_link_id' => $request->paymongo_link_id,
                'payment_link_created_at' => now(),
                'payment_expires_at' => now()->addHour(),
                'payment_failed_at' => null,
                'payment_failure_reason' => null,
                'payment_expired_at' => null,
                'payment_status' => $nextPaymentStatus,
            ]);

            \Log::info('Payment link updated for repair', [
                'repair_id'        => $repair->id,
                'paymongo_link_id' => $request->paymongo_link_id,
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
     * Create a fresh PayMongo checkout session for an existing unpaid repair request.
     */
    public function retryPaymentSession(Request $request, $id, PaymentSettlementService $settlementService)
    {
        try {
            $user = Auth::guard('user')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $repair = RepairRequest::with('shopOwner')
                ->where('id', $id)
                ->forCustomer($user->id)
                ->first();

            if (!$repair) {
                return response()->json([
                    'success' => false,
                    'message' => 'Repair request not found'
                ], 404);
            }

            $policy = $this->normalizeRepairPaymentPolicy($repair->payment_policy ?? 'deposit_50');

            if ($this->isRepairPaymentSettled($repair, $policy)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Repair is already fully paid'
                ], 409);
            }

            if ((string) $repair->status === 'cancelled') {
                return response()->json([
                    'success' => false,
                    'message' => 'This repair request is cancelled and cannot be paid.'
                ], 409);
            }

            if (!$repair->payment_enabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment is not enabled for this repair request yet.'
                ], 409);
            }

            if (!$this->isRepairPaymentDueNow($repair, $policy)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This repair has no payable phase right now.'
                ], 409);
            }

            $phaseBreakdown = $settlementService->repairPaymentBreakdown($repair);
            $phase = $phaseBreakdown['phase'] === 'final'
                ? 'final payment'
                : ($policy === 'full_upfront' ? 'full payment' : 'down payment');

            $taxMode = $settlementService->repairTaxMode($repair);
            if ($taxMode === 'vat_inclusive') {
                $taxBreakdown = VatInclusiveCalculator::extract((float) $phaseBreakdown['service_amount'], self::REPAIR_VAT_RATE_PERCENT);
                $serviceAmount = (float) $taxBreakdown['total'];
                $dueSubtotal = round((float) $taxBreakdown['net'] + (float) $phaseBreakdown['delivery_amount'], 2);
                $vatAmount = (float) $taxBreakdown['vat'];
            } else {
                $serviceSubtotal = (float) $phaseBreakdown['service_amount'];
                $vatAmount = round($serviceSubtotal * (self::REPAIR_VAT_RATE_PERCENT / 100), 2);
                $serviceAmount = round($serviceSubtotal + $vatAmount, 2);
                $dueSubtotal = round($serviceSubtotal + (float) $phaseBreakdown['delivery_amount'], 2);
            }
            $amount = round($serviceAmount + (float) $phaseBreakdown['delivery_amount'], 2);

            if ($amount <= 0) {
                $settledRepair = DB::transaction(function () use ($repair, $settlementService): RepairRequest {
                    $lockedRepair = RepairRequest::query()->lockForUpdate()->findOrFail($repair->id);
                    $currentBreakdown = $settlementService->repairPaymentBreakdown($lockedRepair);

                    if (round((float) $currentBreakdown['total_amount'], 2) !== 0.0) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'payment' => ['The payable amount changed. Please try again.'],
                        ]);
                    }

                    return $settlementService->settleRepairPhasePaid($lockedRepair, $currentBreakdown);
                });

                return response()->json([
                    'success' => true,
                    'zero_amount_settled' => true,
                    'checkout_url' => null,
                    'link_id' => null,
                    'repair_id' => $settledRepair->id,
                    'subtotal_amount' => 0,
                    'vat_amount' => 0,
                    'vat_rate' => self::REPAIR_VAT_RATE_PERCENT,
                    'total_amount' => 0,
                    'tax_mode' => $taxMode,
                ]);
            }

            $apiKey = $repair->shopOwner?->paymongo_secret_key;
            if (! $apiKey) {
                return response()->json([
                    'success' => false,
                    'error' => 'shop_payment_not_configured',
                    'message' => 'This shop has not set up payment processing yet. Please contact the shop owner.',
                ], 503);
            }

            $description = 'SoleSpace Repair #' . ($repair->request_id ?: $repair->id) . ' (' . $phase . ')';
            $returnTimestamp = now()->timestamp;
            $returnSignature = $this->buildPaymentReturnSignature('repair', (int) $repair->id, $returnTimestamp);
            $successUrl = url('/my-repairs') . '?' . http_build_query([
                'paymongo_success' => 1,
                'pending_repair_id' => $repair->id,
                'return_ts' => $returnTimestamp,
                'return_sig' => $returnSignature,
            ]);
            $failedUrl = url('/my-repairs') . '?' . http_build_query([
                'paymongo_failed' => 1,
                'pending_repair_id' => $repair->id,
                'return_ts' => $returnTimestamp,
                'return_sig' => $returnSignature,
            ]);

            $paymentMethodTypes = ['card', 'gcash', 'paymaya', 'grab_pay'];

            $paymentResponse = \Illuminate\Support\Facades\Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($apiKey . ':'),
            ])->post('https://api.paymongo.com/v1/checkout_sessions', [
                'data' => [
                    'attributes' => [
                        'success_url' => $successUrl,
                        'cancel_url' => $failedUrl,
                        'description' => $description,
                        'send_email_receipt' => false,
                        'show_description' => true,
                        'show_line_items' => true,
                        'line_items' => array_values(array_filter([
                            $serviceAmount > 0 ? [
                                'currency' => 'PHP',
                                'amount' => (int) round($serviceAmount * 100),
                                'name' => $description,
                                'quantity' => 1,
                            ] : null,
                            (float) $phaseBreakdown['delivery_amount'] > 0 ? [
                                'currency' => 'PHP',
                                'amount' => (int) round((float) $phaseBreakdown['delivery_amount'] * 100),
                                'name' => $phaseBreakdown['leg'] === 'intake' ? 'Shop pickup fee' : 'Shop return delivery fee',
                                'quantity' => 1,
                            ] : null,
                        ])),
                        'payment_method_types' => $paymentMethodTypes,
                    ],
                ],
            ]);

            if ($paymentResponse->failed()) {
                $errorMsg = $paymentResponse->json('message') ?? $paymentResponse->json('error') ?? 'PayMongo API failed';
                $errors = $paymentResponse->json('errors');

                \Log::error('Repair retry payment session creation failed', [
                    'repair_id' => $repair->id,
                    'status' => $paymentResponse->status(),
                    'message' => $errorMsg,
                    'errors' => $errors,
                    'response' => $paymentResponse->json(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $errors[0]['detail'] ?? $errorMsg ?? 'Failed to create payment session',
                ], $paymentResponse->status());
            }

            $responseData = $paymentResponse->json();
            $checkoutUrl = $responseData['data']['attributes']['checkout_url'] ?? null;
            $linkId = $responseData['data']['id'] ?? null;

            if (!$checkoutUrl || !$linkId) {
                \Log::error('Repair retry payment session missing checkout data', [
                    'repair_id' => $repair->id,
                    'response' => $responseData,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Incomplete payment session response',
                ], 500);
            }

            DB::transaction(function () use ($repair, $phaseBreakdown, $serviceAmount, $linkId, $policy, $taxMode, $settlementService): void {
                $lockedRepair = RepairRequest::query()->lockForUpdate()->findOrFail($repair->id);
                $lockedPolicy = $settlementService->normalizeRepairPaymentPolicy(
                    $lockedRepair->payment_policy_snapshot ?: $lockedRepair->payment_policy
                );

                if (! $settlementService->isRepairPaymentDueNow($lockedRepair, $lockedPolicy)
                    || $settlementService->isRepairPaymentPhaseSettled($lockedRepair, $phaseBreakdown['phase'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'payment' => ['This payment phase was already settled. Refresh the repair before trying again.'],
                    ]);
                }

                $currentBreakdown = $settlementService->repairPaymentBreakdown($lockedRepair);
                $currentTaxMode = $settlementService->repairTaxMode($lockedRepair);
                $paymentPlanChanged = (string) $currentBreakdown['phase'] !== (string) $phaseBreakdown['phase']
                    || (string) $currentBreakdown['policy'] !== (string) $phaseBreakdown['policy']
                    || (string) ($currentBreakdown['snapshot_version'] ?? '') !== (string) ($phaseBreakdown['snapshot_version'] ?? '')
                    || (string) $currentBreakdown['delivery_method'] !== (string) $phaseBreakdown['delivery_method']
                    || round((float) $currentBreakdown['service_amount'], 2) !== round((float) $phaseBreakdown['service_amount'], 2)
                    || round((float) $currentBreakdown['delivery_amount'], 2) !== round((float) $phaseBreakdown['delivery_amount'], 2)
                    || $currentTaxMode !== $taxMode;

                if ($paymentPlanChanged) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'payment' => ['The payable amount or delivery plan changed. Refresh and try again.'],
                    ]);
                }

                RepairPaymentSession::query()
                    ->where('repair_request_id', $lockedRepair->id)
                    ->where('phase', $currentBreakdown['phase'])
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'invalidated',
                        'invalidated_at' => now(),
                    ]);

                RepairPaymentSession::create([
                    'repair_request_id' => $lockedRepair->id,
                    'provider' => 'paymongo',
                    'provider_link_id' => $linkId,
                    'phase' => $currentBreakdown['phase'],
                    'status' => 'pending',
                    'snapshot_version' => $currentBreakdown['snapshot_version'],
                    'delivery_method' => $currentBreakdown['delivery_method'],
                    'service_amount' => $serviceAmount,
                    'delivery_amount' => $currentBreakdown['delivery_amount'],
                    'quote' => [
                        ...(is_array($currentBreakdown['quote']) ? $currentBreakdown['quote'] : []),
                        'payment_policy' => $currentBreakdown['policy'],
                        'payment_phase' => $currentBreakdown['phase'],
                        'service_base_amount' => $currentBreakdown['service_amount'],
                        'tax_mode' => $currentTaxMode,
                    ],
                ]);

                $lockedRepair->update([
                    'paymongo_link_id' => $linkId,
                    'payment_link_created_at' => now(),
                    'payment_expires_at' => now()->addHour(),
                    'payment_failed_at' => null,
                    'payment_failure_reason' => null,
                    'payment_expired_at' => null,
                    'payment_status' => $this->nextRepairPaymentStatusForRetry($lockedRepair, $policy),
                ]);
            });

            return response()->json([
                'success' => true,
                'checkout_url' => $checkoutUrl,
                'link_id' => $linkId,
                'repair_id' => $repair->id,
                'subtotal_amount' => $dueSubtotal,
                'vat_amount' => $vatAmount,
                'vat_rate' => self::REPAIR_VAT_RATE_PERCENT,
                'total_amount' => $amount,
                'tax_mode' => $taxMode,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Retry payment session failed for repair', [
                'repair_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create retry payment session',
            ], 500);
        }
    }

    /**
     * Auto-assign repair request to available repairer (Phase 2)
     * Uses workload-based round-robin for fair distribution
     * Called automatically when repair request is created
     * ONLY assigns to users with Repairer ROLE (not permission-based)
     */
    private function autoAssignRepairer(RepairRequest $repairRequest)
    {
        try {
            $shopWorkloadLimit = (int) (ShopOwner::query()
                ->where('id', $repairRequest->shop_owner_id)
                ->value('repair_workload_limit') ?? 20);

            $activeStatuses = [
                'assigned_to_repairer',
                'repairer_accepted',
                'pending',
                'received',
                'in-progress',
                'in_progress',
                'awaiting_parts',
                'waiting_customer_confirmation',
                'completed',
                'ready-for-pickup',
                'ready_for_pickup',
            ];

            // Determine if the customer specified a preferred drop-off date
            $preferredDate = $repairRequest->scheduled_dropoff_date
                ? $repairRequest->scheduled_dropoff_date->format('Y-m-d')
                : null;
            $preferredMonthKey = $preferredDate ? substr($preferredDate, 0, 7) : null;

            // Helper: get IDs of repairers who have blocked the preferred date
            $blockedRepairerIds = collect();
            if ($preferredDate && $preferredMonthKey) {
                $rows = \Illuminate\Support\Facades\DB::table('repairer_unavailability')
                    ->where('shop_owner_id', $repairRequest->shop_owner_id)
                    ->where('month_key', $preferredMonthKey)
                    ->get(['repairer_id', 'unavailable_dates']);

                foreach ($rows as $row) {
                    $dates = json_decode($row->unavailable_dates, true) ?? [];
                    if (in_array($preferredDate, $dates)) {
                        $blockedRepairerIds->push($row->repairer_id);
                    }
                }
            }

            // Base query builder shared across strategies
            $baseQuery = fn() => User::where('shop_owner_id', $repairRequest->shop_owner_id)
                ->whereHas('employee', function($query) {
                    $query->where('status', 'active');
                })
                ->whereHas('roles', function($query) {
                    $query->where('name', 'Repairer');
                })
                ->where('status', 'active')
                ->withCount(['assignedRepairs as active_repairs_count' => function($query) use ($activeStatuses) {
                    $query->whereIn('status', $activeStatuses);
                }]);

            // STRATEGY 1: Least-busy repairer who is FREE on the preferred date (under capacity)
            $repairer = null;
            if ($preferredDate && $blockedRepairerIds->isNotEmpty()) {
                // Some repairers have blocked the preferred date — exclude them
                $repairer = $baseQuery()
                    ->whereNotIn('id', $blockedRepairerIds)
                    ->having('active_repairs_count', '<', $shopWorkloadLimit)
                    ->orderBy('active_repairs_count', 'asc')
                    ->orderBy('id', 'asc')
                    ->first();
            }

            // STRATEGY 2: Normal least-busy pick under capacity (preferred date either not set, or no one blocked it)
            if (!$repairer) {
                $repairer = $baseQuery()
                    ->having('active_repairs_count', '<', $shopWorkloadLimit)
                    ->orderBy('active_repairs_count', 'asc')  // Assign to least busy first
                    ->orderBy('id', 'asc')  // Tie-breaker: earliest hired repairer
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
                        'repair_id' => $repairRequest->id,
                        'repair_request_id' => $repairRequest->id,
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

        // Apply payment based on the shop's policy for this repair
        $this->applyPaymentCompletion($repair, 'TEST_PAYMENT_' . time());

        return response()->json([
            'success' => true,
            'message' => 'Payment simulated successfully',
            'data' => $repair->fresh(['services', 'shopOwner', 'repairer'])
        ]);
    }

    public function checkoutViaPos(Request $request, RepairPosPaymentService $service)
    {
        $validated = $request->validate([
            'repair_request_id' => ['required', 'integer', 'exists:repair_requests,id'],
            'due_type' => ['required', 'string', 'in:deposit,balance,full'],
            'customer_type' => ['required', 'string', 'in:registered,walk_in'],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'walk_in_name' => ['nullable', 'string', 'max:255'],
            'walk_in_phone' => ['nullable', 'string', 'max:30'],
            'walk_in_email' => ['nullable', 'email', 'max:255'],
            'payment_lines' => ['required', 'array', 'min:1'],
            'payment_lines.*.tender_type' => ['required', 'string', 'in:cash,paymongo_card,paymongo_wallet'],
            'payment_lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payment_lines.*.provider_reference' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($validated['payment_lines'] as $index => $line) {
            $isNonCash = in_array($line['tender_type'] ?? '', ['paymongo_card', 'paymongo_wallet'], true);
            $reference = trim((string) ($line['provider_reference'] ?? ''));

            if ($isNonCash && $reference === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "payment_lines.{$index}.provider_reference" => ['Reference is required for GCash/Card payments.'],
                ]);
            }
        }

        $repair = RepairRequest::findOrFail((int) $validated['repair_request_id']);
        $actorId = (int) (Auth::guard('user')->id() ?? 0);

        $transaction = $service->checkout($repair, $validated, $actorId);

        return response()->json([
            'success' => true,
            'transaction_id' => $transaction->id,
            'transaction_no' => $transaction->transaction_no,
        ]);
    }

    /**
     * Shared helper: apply a completed payment to a repair request, respecting the shop's payment policy.
     *
     * Policies:
     *   deposit_50  – two-phase: first payment → 'paid' (50% deposit), second → 'completed' (remaining 50%)
     *   full_upfront – single payment before drop-off → 'completed' immediately
     */
    private function applyPaymentCompletion(RepairRequest $repair, string $paymentId): void
    {
        $settlement = app(PaymentSettlementService::class)
            ->settleRepairPaid($repair, $paymentId);

        if (($settlement['result'] ?? null) !== 'settled') {
            return;
        }

        $phase = (string) ($settlement['phase'] ?? '');
        if ($phase === 'full_upfront') {
            \Log::info('Full-upfront payment applied for repair: ' . $repair->request_id);
        } elseif ($phase === 'deposit_50') {
            \Log::info('Deposit (50%) payment applied for repair: ' . $repair->request_id);
        } elseif ($phase === 'remaining_balance') {
            \Log::info('Remaining-balance (50%) payment applied for repair: ' . $repair->request_id);
        }
    }

    /**
     * Verify payment status directly with PayMongo API.
     * Called when the customer is redirected back from the PayMongo checkout page.
     * Does NOT rely on webhooks — polls the link status endpoint instead.
     */
    public function verifyPayment(Request $request, $id)
    {
        $settlementService = app(PaymentSettlementService::class);

        $user = Auth::guard('user')->user();
        $hasValidPublicReturnSignature = $this->hasValidPublicPaymentReturnSignature($request, 'repair', (int) $id);

        if (!$user && !$hasValidPublicReturnSignature) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $repairQuery = RepairRequest::where('id', $id);

        if ($user) {
            $repairQuery->forCustomer($user->id);
        }

        $repair = $repairQuery->first();

        if (!$repair) {
            return response()->json(['success' => false, 'message' => 'Repair request not found'], 404);
        }

        // Idempotent: skip verification if truly fully paid.
        // For deposit_50: 'paid' = only the deposit was paid, 'completed' = both payments done.
        // For full_upfront: a single 'paid' should be treated as fully paid.
        $normalizedPolicy = $settlementService->normalizeRepairPaymentPolicy($repair->payment_policy ?? 'deposit_50');
        if (data_get($repair->logistics_payment_reconciliation, 'status') === 'pending') {
            return response()->json([
                'success' => false,
                'payment_verified' => false,
                'requires_reconciliation' => true,
                'message' => 'Payment was received, but the amount or delivery plan changed. The shop must reconcile it before processing can continue.',
            ], 409);
        }

        $isFullyPaid = $settlementService->isRepairSettled($repair, $normalizedPolicy);

        if ($isFullyPaid) {
            return response()->json([
                'success'          => true,
                'payment_verified' => true,
                'already_paid'     => true,
                'data'             => $repair->fresh(['services', 'shopOwner', 'repairer']),
            ]);
        }

        if (
            $normalizedPolicy === 'deposit_50'
            && (string) $repair->payment_status === 'paid'
            && !$this->isRepairRemainingBalancePhase($repair)
        ) {
            return response()->json([
                'success' => true,
                'payment_verified' => true,
                'already_paid' => true,
                'partial_paid' => true,
                'message' => 'Initial deposit is already paid. Remaining balance is payable when the repair is ready for pickup.',
                'data' => $repair->fresh(['services', 'shopOwner', 'repairer']),
            ]);
        }

        $isExpired = $settlementService->isRepairExpired($repair, $normalizedPolicy);

        if (!$settlementService->isRepairPaymentDueNow($repair, $normalizedPolicy)) {
            return response()->json([
                'success' => false,
                'payment_verified' => false,
                'message' => 'No payable repair phase is currently due.',
            ], 409);
        }

        if (!$repair->paymongo_link_id) {
            return response()->json([
                'success'          => false,
                'payment_verified' => false,
                'message'          => 'No payment link found for this repair',
            ], 404);
        }

        // Use the shop's own PayMongo key (same key that created the checkout session)
        $apiKey = $repair->shopOwner?->paymongo_secret_key;

        if (!$apiKey) {
            return response()->json([
                'success'          => false,
                'payment_verified' => false,
                'message'          => 'Payment gateway not configured for this shop.',
            ], 503);
        }

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Basic ' . base64_encode($apiKey . ':'),
        ])->get("https://api.paymongo.com/v1/checkout_sessions/{$repair->paymongo_link_id}");

        \Log::info('PayMongo session check', [
            'repair_id'  => $repair->id,
            'session_id' => $repair->paymongo_link_id,
            'http_status' => $response->status(),
            'raw_body'   => $response->json(),
        ]);

        if ($response->failed()) {
            \Log::error('PayMongo session status check failed', [
                'repair_id'  => $repair->id,
                'session_id' => $repair->paymongo_link_id,
                'status'     => $response->status(),
                'body'       => $response->json(),
            ]);
            return response()->json([
                'success'          => false,
                'payment_verified' => false,
                'message'          => 'Could not reach PayMongo to verify payment',
            ], 502);
        }

        $data          = $response->json();
        $paymentStatus = $data['data']['attributes']['payment_status'] ?? null;
        // Also check nested payments array for paid status
        $payments      = $data['data']['attributes']['payments'] ?? [];
        $firstPayment  = $payments[0] ?? null;
        $firstPaymentStatus = $firstPayment['data']['attributes']['status'] ?? ($firstPayment['attributes']['status'] ?? null);
        $paymentId     = $firstPayment['data']['id'] ?? ($firstPayment['id'] ?? $data['data']['id'] ?? null);

        \Log::info('PayMongo payment_status extracted', [
            'repair_id'         => $repair->id,
            'payment_status'    => $paymentStatus,
            'first_payment_id'  => $paymentId,
            'first_pay_status'  => $firstPaymentStatus,
            'payments_count'    => count($payments),
        ]);

        // Accept 'paid' on the session OR a paid individual payment in the payments array
        $isVerified = ($paymentStatus === 'paid') || ($firstPaymentStatus === 'paid');

        if (!$isVerified) {
            if ($isExpired) {
                $settlementService->recordRepairPaymentFailure($repair, 'paymongo_session_expired');

                return response()->json([
                    'success'          => false,
                    'payment_verified' => false,
                    'expired'          => true,
                    'message'          => 'Payment session expired. Please create a new payment session.',
                ], 410);
            }

            $statusSignals = array_filter([
                strtolower((string) $paymentStatus),
                strtolower((string) $firstPaymentStatus),
            ]);

            $isFailed = in_array('failed', $statusSignals, true);
            $isExpiredSignal = in_array('expired', $statusSignals, true);
            $isCancelled = in_array('cancelled', $statusSignals, true) || in_array('canceled', $statusSignals, true);

            if ($isFailed || $isExpiredSignal || $isCancelled) {
                $settlementService->recordRepairPaymentFailure(
                    $repair,
                    $isExpiredSignal
                        ? 'paymongo_session_expired'
                        : ($isCancelled ? 'paymongo_payment_cancelled' : 'paymongo_payment_failed')
                );
            }

            return response()->json([
                'success'          => false,
                'payment_verified' => false,
                'payment_status'   => $paymentStatus,
                'message'          => 'Payment has not been completed yet',
            ]);
        }

        // Payment confirmed — apply policy-aware completion
        $settlement = $settlementService->settleRepairPaid($repair, $paymentId, true);
        $settlementResult = $settlement['result'] ?? 'settled';

        if ($settlementResult === 'expired') {
            return response()->json([
                'success' => false,
                'payment_verified' => false,
                'expired' => true,
                'message' => 'Payment session expired. Please create a new payment session.',
            ], 410);
        }

        if ($settlementResult === 'not_due') {
            return response()->json([
                'success' => false,
                'payment_verified' => false,
                'message' => 'No payable repair phase is currently due.',
            ], 409);
        }

        if ($settlementResult === 'reconciliation') {
            return response()->json([
                'success' => false,
                'payment_verified' => false,
                'requires_reconciliation' => true,
                'message' => 'Payment was received, but the amount or delivery plan changed. The shop must reconcile it before processing can continue.',
            ], 409);
        }

        \Log::info('Payment verified via PayMongo API for repair: ' . $repair->request_id, [
            'policy'     => $normalizedPolicy,
            'link_id'    => $repair->paymongo_link_id,
            'payment_id' => $paymentId,
            'result'     => $settlementResult,
        ]);

        return response()->json([
            'success'          => true,
            'payment_verified' => true,
            'data'             => $repair->fresh(['services', 'shopOwner', 'repairer']),
        ]);
    }

    private function buildPaymentReturnSignature(string $scope, int $resourceId, int $timestamp): string
    {
        return hash_hmac(
            'sha256',
            sprintf('%s:return:%d:%d', trim($scope), $resourceId, $timestamp),
            (string) config('app.key')
        );
    }

    private function hasValidPublicPaymentReturnSignature(Request $request, string $scope, int $resourceId): bool
    {
        $timestamp = (int) $request->input('return_ts', 0);
        $signature = trim((string) $request->input('return_sig', ''));

        if ($timestamp <= 0 || $signature === '') {
            return false;
        }

        $now = now()->timestamp;
        if ($timestamp > ($now + 300)) {
            return false;
        }

        if (($now - $timestamp) > self::PAYMENT_RETURN_TOKEN_TTL_SECONDS) {
            return false;
        }

        $expected = $this->buildPaymentReturnSignature($scope, $resourceId, $timestamp);
        return hash_equals($expected, $signature);
    }

    /**
     * Return the current active repair count and configured workload limit for a shop.
     * Used by the customer side to show whether the shop is at capacity.
     * GET /api/customer/shop/{shopOwnerId}/repair-capacity
     */
    public function shopRepairCapacity(Request $request, $shopOwnerId)
    {
        $shopOwner = ShopOwner::find($shopOwnerId);

        if (!$shopOwner) {
            return response()->json(['success' => false, 'message' => 'Shop not found'], 404);
        }

        $activeStatuses = ['assigned_to_repairer', 'repairer_accepted', 'pending', 'received',
            'in-progress', 'in_progress', 'awaiting_parts', 'waiting_customer_confirmation',
            'completed', 'ready-for-pickup', 'ready_for_pickup'];

        $perRepairerLimit = (int) ($shopOwner->repair_workload_limit ?? 20);

        $repairerCount = User::query()
            ->where('shop_owner_id', $shopOwner->id)
            ->where('status', 'active')
            ->whereHas('roles', function ($query) {
                $query->where('name', 'Repairer');
            })
            ->count();

        if ($repairerCount > 0) {
            $activeCount = RepairRequest::query()
                ->where('shop_owner_id', $shopOwner->id)
                ->whereNotNull('assigned_repairer_id')
                ->whereIn('status', $activeStatuses)
                ->count();

            $limit = $repairerCount * $perRepairerLimit;
        } else {
            // Fallback for owner-operated shops with no staff repairers
            $activeCount = RepairRequest::query()
                ->where('shop_owner_id', $shopOwner->id)
                ->whereIn('status', $activeStatuses)
                ->count();

            $limit = $perRepairerLimit;
        }

        return response()->json([
            'success'      => true,
            'active_count' => $activeCount,
            'limit'        => $limit,
            'repairer_count' => $repairerCount,
            'limit_per_repairer' => $perRepairerLimit,
            'is_full'      => $activeCount >= $limit,
        ]);
    }

    private function normalizeRepairPaymentPolicy(?string $policy): string
    {
        return app(PaymentSettlementService::class)->normalizeRepairPaymentPolicy($policy);
    }

    private function isRepairRemainingBalancePhase(RepairRequest $repair): bool
    {
        return in_array((string) $repair->status, ['ready_for_pickup', 'ready-for-pickup'], true);
    }

    private function isRepairPaymentSettled(RepairRequest $repair, string $policy): bool
    {
        return app(PaymentSettlementService::class)->isRepairSettled($repair, $policy);
    }

    private function isRepairPaymentDueNow(RepairRequest $repair, string $policy): bool
    {
        return app(PaymentSettlementService::class)->isRepairPaymentDueNow($repair, $policy);
    }

    private function isRepairPaymentSessionExpired(RepairRequest $repair, string $policy): bool
    {
        return app(PaymentSettlementService::class)->isRepairExpired($repair, $policy);
    }

    private function nextRepairPaymentStatusForRetry(RepairRequest $repair, string $policy): string
    {
        $currentStatus = (string) ($repair->payment_status ?? 'pending');

        if (in_array($currentStatus, ['failed', 'expired', ''], true)) {
            if ($policy === 'deposit_50' && $this->isRepairRemainingBalancePhase($repair)) {
                return 'paid';
            }

            return 'pending';
        }

        return $currentStatus;
    }

    private function resolveRepairTaxMode(RepairRequest $repair): string
    {
        $pricingTaxMode = strtolower((string) data_get($repair->pricing_breakdown, 'tax_mode', ''));
        if (in_array($pricingTaxMode, ['vat_inclusive', 'legacy_add_on'], true)) {
            return $pricingTaxMode;
        }

        $pricingMode = strtolower((string) data_get($repair->pricing_breakdown, 'mode', ''));
        if ($pricingMode === 'manual_pos') {
            return 'vat_inclusive';
        }

        $latestPosTaxMode = strtolower((string) data_get($repair->latestPosTransaction?->metadata, 'tax_mode', ''));
        if (in_array($latestPosTaxMode, ['vat_inclusive', 'legacy_add_on'], true)) {
            return $latestPosTaxMode;
        }

        return 'legacy_add_on';
    }

    private function calculateRepairTaxSummary(float $finalTotal, string $taxMode): array
    {
        $total = round(max(0.0, $finalTotal), 2);

        if ($taxMode === 'vat_inclusive') {
            $breakdown = VatInclusiveCalculator::extract($total, self::REPAIR_VAT_RATE_PERCENT);

            return [
                'vat_amount' => (float) $breakdown['vat'],
                'grand_total' => (float) $breakdown['total'],
            ];
        }

        $vatAmount = round($total * (self::REPAIR_VAT_RATE_PERCENT / 100), 2);

        return [
            'vat_amount' => $vatAmount,
            'grand_total' => round($total + $vatAmount, 2),
        ];
    }

    private function resolveCustomerFacingTotalPaidAmount(
        RepairRequest $repair,
        float $grandTotal,
        float $storedPaidAmount,
        float $posLedgerPaidAmount,
    ): float {
        $resolved = max(0.0, $storedPaidAmount, $posLedgerPaidAmount);
        $paymentStatus = strtolower(trim((string) ($repair->payment_status ?? 'pending')));
        $policy = $this->normalizeRepairPaymentPolicy((string) ($repair->payment_policy ?? 'deposit_50'));

        if ($paymentStatus === 'completed') {
            return round(max($resolved, $grandTotal), 2);
        }

        if ($paymentStatus === 'paid') {
            $phaseAmount = $policy === 'full_upfront'
                ? $grandTotal
                : round($grandTotal * 0.5, 2);

            return round(max($resolved, $phaseAmount), 2);
        }

        return round($resolved, 2);
    }

    private function resolveRefundPaymentProfile(RepairRequest $repair, float $resolvedPaidAmount): array
    {
        $repair->loadMissing([
            'posTransactions:id,module_reference_id,module_type,paid_amount,status',
            'posTransactions.paymentLines:id,pos_transaction_id,tender_type,provider_reference,amount,status',
        ]);

        $eligibleStatuses = ['paid', 'partially_refunded', 'refunded'];
        $gatewayTenderTypes = ['paymongo_card', 'paymongo_wallet'];

        $posTransactions = $repair->posTransactions
            ->filter(fn (PosTransaction $tx) => in_array((string) $tx->status, $eligibleStatuses, true))
            ->values();

        $posPaidAmount = round((float) $posTransactions->sum(fn (PosTransaction $tx) => (float) ($tx->paid_amount ?? 0)), 2);

        $storedGatewayPaymentId = trim((string) ($repair->paymongo_payment_id ?? ''));
        $hasStoredGatewayPaymentId = $this->looksLikeGatewayProviderReference($storedGatewayPaymentId);

        $gatewayPaidFromLines = round((float) $posTransactions
            ->flatMap(fn (PosTransaction $tx) => $tx->paymentLines ?? collect())
            ->filter(function ($line) use ($gatewayTenderTypes, $hasStoredGatewayPaymentId) {
                if ((string) ($line->status ?? '') !== 'paid') {
                    return false;
                }

                if (!in_array((string) ($line->tender_type ?? ''), $gatewayTenderTypes, true)) {
                    return false;
                }

                if ($hasStoredGatewayPaymentId) {
                    return true;
                }

                return $this->looksLikeGatewayProviderReference((string) ($line->provider_reference ?? ''));
            })
            ->sum(fn ($line) => (float) ($line->amount ?? 0)), 2);

        $canInferGatewayPaid = $hasStoredGatewayPaymentId || $gatewayPaidFromLines > 0.01;
        $inferredGatewayPaid = $canInferGatewayPaid
            ? max(0.0, round($resolvedPaidAmount - $posPaidAmount, 2))
            : 0.0;
        $gatewayPaidAmount = round(max($gatewayPaidFromLines, $inferredGatewayPaid), 2);
        $manualPaidAmount = max(0.0, round($resolvedPaidAmount - $gatewayPaidAmount, 2));

        $paymentType = 'manual_only';
        if ($gatewayPaidAmount > 0 && $manualPaidAmount <= 0.01) {
            $paymentType = 'pure_online';
        } elseif ($gatewayPaidAmount > 0 && $manualPaidAmount > 0.01) {
            $paymentType = 'mixed';
        }

        return [
            'payment_type' => $paymentType,
            'requires_payout_destination' => $paymentType !== 'pure_online',
            'original_method_only' => $paymentType === 'pure_online',
            'gateway_paid_amount' => round($gatewayPaidAmount, 2),
            'manual_paid_amount' => round($manualPaidAmount, 2),
        ];
    }

    private function looksLikeGatewayProviderReference(?string $reference): bool
    {
        $value = strtolower(trim((string) ($reference ?? '')));
        if ($value === '') {
            return false;
        }

        return str_starts_with($value, 'pay_')
            || str_starts_with($value, 'pi_')
            || str_starts_with($value, 'src_')
            || str_starts_with($value, 'pmw_')
            || str_starts_with($value, 'pmc_');
    }

    private function backfillPureOnlineRepairSourceTransaction(
        RepairRequest $repair,
        int $actorUserId,
        string $paymentType,
        int $requestedSourceTransactionId,
        float $resolvedPaidAmount,
    ): ?PosTransaction {
        if ($requestedSourceTransactionId > 0) {
            return null;
        }

        if ($paymentType !== 'pure_online') {
            return null;
        }

        $paymongoPaymentId = trim((string) ($repair->paymongo_payment_id ?? ''));
        if (!$this->looksLikeGatewayProviderReference($paymongoPaymentId)) {
            return null;
        }

        $paidAmount = round($resolvedPaidAmount, 2);
        if ($paidAmount <= 0) {
            return null;
        }

        $existing = PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $taxBreakdown = VatInclusiveCalculator::extract($paidAmount, self::REPAIR_VAT_RATE_PERCENT);
        $isCardLikeReference = str_starts_with(strtolower($paymongoPaymentId), 'pmc_');

        return DB::transaction(function () use ($repair, $actorUserId, $paidAmount, $taxBreakdown, $paymongoPaymentId, $isCardLikeReference) {
            $transaction = PosTransaction::create([
                'transaction_no' => 'POS-BKF-' . now()->format('YmdHis') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
                'shop_owner_id' => (int) $repair->shop_owner_id,
                'module_type' => 'repair',
                'module_reference_id' => (int) $repair->id,
                'customer_type' => 'registered',
                'customer_id' => (int) $repair->user_id,
                'due_type' => 'full',
                'subtotal' => round((float) ($taxBreakdown['net'] ?? $paidAmount), 2),
                'tax_amount' => round((float) ($taxBreakdown['vat'] ?? 0), 2),
                'discount_amount' => 0,
                'total_amount' => $paidAmount,
                'paid_amount' => $paidAmount,
                'status' => 'paid',
                'paid_at' => now(),
                'created_by' => $actorUserId > 0 ? $actorUserId : null,
                'metadata' => [
                    'tax_mode' => 'vat_inclusive',
                    'source' => 'myrepair_refund_online_backfill',
                ],
            ]);

            PosPaymentLine::create([
                'pos_transaction_id' => $transaction->id,
                'tender_type' => $isCardLikeReference ? 'paymongo_card' : 'paymongo_wallet',
                'provider_reference' => $paymongoPaymentId,
                'amount' => $paidAmount,
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            app(RepairPosReceiptService::class)->issue($transaction->fresh('paymentLines'));

            if (!$repair->latest_pos_transaction_id) {
                $repair->update(['latest_pos_transaction_id' => $transaction->id]);
            }

            return $transaction;
        });
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
        $deliveryFees = $repair->paidShopOwnedDeliveryFees();
        $grandTotal = round($finalTotal + $deliveryFees['total'], 2);

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
            'total' => $grandTotal,
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
                'service_amount' => $finalTotal,
                'intake_delivery_fee' => $deliveryFees['intake'],
                'return_delivery_fee' => $deliveryFees['return'],
                'shipping_fee' => $deliveryFees['total'],
                'grand_total' => $grandTotal,
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

        foreach ([
            'Shop-owned intake pickup' => $deliveryFees['intake'],
            'Shop-owned return delivery' => $deliveryFees['return'],
        ] as $description => $fee) {
            if ($fee <= 0) {
                continue;
            }

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $description,
                'quantity' => 1,
                'unit_price' => $fee,
                'tax_rate' => 0,
                'amount' => $fee,
                'account_id' => null,
            ]);
        }

        activity()
            ->performedOn($repair)
            ->withProperties([
                'invoice_id' => $invoice->id,
                'invoice_reference' => $invoice->reference,
                'repair_request_id' => $repair->id,
                'repair_request_number' => $repair->request_id,
                'total' => $grandTotal,
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
        // Keep customer-facing final total anchored to the billable base amount.
        // Materials remain tracked in pricing_breakdown for operational visibility.
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

    private function repairLogisticsValidationFailure(string $field, ?string $reason)
    {
        $message = match ($reason) {
            'address_needs_pin' => 'Pin the exact address before choosing shop-owned logistics.',
            'outside_coverage' => 'This address is outside the shop coverage. Choose walk-in or a third-party courier.',
            'shop_needs_pin' => 'The shop must pin its location before shop-owned logistics can be used.',
            default => 'Shop-owned logistics is unavailable for this address.',
        };

        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => [$field => [$message]],
        ], 422);
    }
}
