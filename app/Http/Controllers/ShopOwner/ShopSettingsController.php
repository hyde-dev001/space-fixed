<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\HR\BranchPayrollSetting;
use App\Models\ProcurementSettings;
use App\Models\ShopOwnerSubscription;
use App\Services\CaviteLocationPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ShopSettingsController extends Controller
{
    /**
     * Display the shop settings page for the authenticated shop owner.
     */
    public function index(): Response
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        $shopOwner->load('documents');
        $latestPremiumSubscription = ShopOwnerSubscription::with('premiumPlan')
            ->where('shop_owner_id', $shopOwner->id)
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'pending' THEN 1 WHEN 'expired' THEN 2 WHEN 'cancelled' THEN 3 WHEN 'failed' THEN 4 ELSE 5 END")
            ->latest('updated_at')
            ->first();
        $procurementSettings = ProcurementSettings::getForShopOwner($shopOwner->id);
        $approvalPages = $this->normalizeApprovalPages($procurementSettings->settings_json['approval_pages'] ?? []);
        $branchPayrollSetting = null;
        if (Schema::hasTable('hr_branch_payroll_settings')) {
            $branchPayrollSetting = BranchPayrollSetting::query()
                ->forShopOwner((int) $shopOwner->id)
                ->active()
                ->orderBy('id')
                ->first();
        }
        $businessType = $this->normalizeBusinessType((string) $shopOwner->business_type);
        $isRetailCapable = in_array($businessType, ['retail', 'both'], true);

        $requiredDocumentTypes = [
            'dti_registration' => [
                'title' => 'Business Registration (DTI)',
                'description' => 'Official DTI or SEC registration certificate for your business.',
            ],
            'mayors_permit' => [
                'title' => "Mayor's Permit / Business Permit",
                'description' => 'Current local business permit issued by your city or municipality.',
            ],
            'bir_certificate' => [
                'title' => 'BIR Certificate of Registration (COR)',
                'description' => 'BIR-issued certificate proving your business is tax-registered.',
            ],
            'valid_id' => [
                'title' => 'Valid ID of Owner',
                'description' => 'Government-issued valid ID of the registered owner.',
            ],
        ];

        $requiredDocuments = [];

        foreach ($requiredDocumentTypes as $type => $meta) {
            $document = $shopOwner->documents->where('document_type', $type)->sortByDesc('created_at')->first();
            $filePath = $document?->file_path;
            $extension = $filePath ? strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) : '';
            $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);

            $requiredDocuments[] = [
                'key' => $type,
                'title' => $meta['title'],
                'description' => $meta['description'],
                'status' => $document?->status ?? 'missing',
                'is_uploaded' => (bool) $document,
                'is_image' => $isImage,
                'file_url' => $filePath ? asset('storage/' . ltrim($filePath, '/')) : null,
            ];
        }

        return Inertia::render('ShopOwner/Settings/shopSetting', [
            'shop_settings' => [
                'registration_type'      => $shopOwner->registration_type,
                'business_type'          => $shopOwner->business_type,
                'can_manage_staff'       => $shopOwner->canManageStaff(),
                'max_locations'          => $shopOwner->getMaxLocations(),
                'business_name'          => $shopOwner->business_name,
                'approval_pages'         => $approvalPages,
                'required_documents'     => $requiredDocuments,
                'repair_payment_policy'  => $shopOwner->repair_payment_policy ?? 'deposit_50',
                'repair_workload_limit'  => (int) ($shopOwner->repair_workload_limit ?? 20),
                'has_paymongo_key'       => !empty($shopOwner->paymongo_secret_key),
                'pay_cycle'              => $branchPayrollSetting?->pay_cycle ?? 'monthly',
                'pay_day_first'          => (int) ($branchPayrollSetting?->pay_day_first ?? 15),
                'pay_day_second'         => (int) ($branchPayrollSetting?->pay_day_second ?? 30),
                // Geofence
                'attendance_geofence_enabled' => (bool) $shopOwner->attendance_geofence_enabled,
                'shop_latitude'          => $shopOwner->shop_latitude,
                'shop_longitude'         => $shopOwner->shop_longitude,
                'shop_address'           => $shopOwner->shop_address ?? $shopOwner->business_address,
                'shop_geofence_radius'   => $shopOwner->shop_geofence_radius ?? 100,
                'premium' => [
                    'eligible' => $isRetailCapable,
                    'status' => $latestPremiumSubscription?->status,
                    'has_active' => $latestPremiumSubscription?->status === 'active'
                        && (!$latestPremiumSubscription?->starts_at || $latestPremiumSubscription->starts_at->lte(now()))
                        && (!$latestPremiumSubscription?->ends_at || $latestPremiumSubscription->ends_at->gte(now())),
                    'plan_name' => $latestPremiumSubscription?->premiumPlan?->name,
                    'plan_code' => $latestPremiumSubscription?->plan_code,
                    'showroom_slot_limit' => $latestPremiumSubscription?->showroom_slot_limit,
                    'starts_at' => $latestPremiumSubscription?->starts_at?->toIso8601String(),
                    'ends_at' => $latestPremiumSubscription?->ends_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    private function normalizeBusinessType(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));

        if (str_contains($normalized, 'both')) {
            return 'both';
        }

        if ($normalized === 'retail') {
            return 'retail';
        }

        if ($normalized === 'repair') {
            return 'repair';
        }

        return '';
    }

    /**
     * Update shop settings for the authenticated shop owner account.
     */
    public function update(Request $request): RedirectResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        $procurementSettings = ProcurementSettings::getForShopOwner($shopOwner->id);

        $validated = $request->validate([
            'approval_pages' => ['sometimes', 'array'],
            'approval_pages.refund_approval.enabled' => ['required_with:approval_pages', 'boolean'],
            'approval_pages.refund_approval.limit' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'approval_pages.price_approval.enabled' => ['required_with:approval_pages', 'boolean'],
            'approval_pages.price_approval.limit' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'approval_pages.purchase_request_approval.enabled' => ['required_with:approval_pages', 'boolean'],
            'approval_pages.purchase_request_approval.limit' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'approval_pages.repair_reject_approval.enabled' => ['required_with:approval_pages', 'boolean'],
            'approval_pages.repair_reject_approval.limit' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'repair_payment_policy' => ['sometimes', 'string', 'in:deposit_50,full_upfront,pay_after'],
            'repair_workload_limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'pay_cycle' => ['sometimes', 'string', 'in:monthly,semi_monthly'],
            'pay_day_first' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'pay_day_second' => ['sometimes', 'integer', 'min:1', 'max:31', 'gt:pay_day_first'],
        ]);

        if (array_key_exists('approval_pages', $validated)) {
            $normalizedApprovalPages = $this->normalizeApprovalPages($validated['approval_pages']);

            $settingsJson = $procurementSettings->settings_json ?? [];
            $settingsJson['approval_pages'] = $normalizedApprovalPages;

            $procurementSettings->update([
                'settings_json' => $settingsJson,
            ]);
        }

        // Save payment policy and workload limit directly on the shop owner record
        $shopOwnerUpdates = [];
        if (isset($validated['repair_payment_policy'])) {
            $shopOwnerUpdates['repair_payment_policy'] = $validated['repair_payment_policy'];
        }
        if (isset($validated['repair_workload_limit'])) {
            $shopOwnerUpdates['repair_workload_limit'] = $validated['repair_workload_limit'];
        }
        if (!empty($shopOwnerUpdates)) {
            $shopOwner->update($shopOwnerUpdates);
        }

        $shouldUpdatePayrollSetting =
            array_key_exists('pay_cycle', $validated)
            || array_key_exists('pay_day_first', $validated)
            || array_key_exists('pay_day_second', $validated);

        if ($shouldUpdatePayrollSetting && Schema::hasTable('hr_branch_payroll_settings')) {
            $branchPayrollSetting = BranchPayrollSetting::query()
                ->forShopOwner((int) $shopOwner->id)
                ->active()
                ->orderBy('id')
                ->first();

            $payCycle = (string) ($validated['pay_cycle'] ?? ($branchPayrollSetting?->pay_cycle ?? 'monthly'));
            $payDayFirst = (int) ($validated['pay_day_first'] ?? ($branchPayrollSetting?->pay_day_first ?? 15));
            $payDaySecond = (int) ($validated['pay_day_second'] ?? ($branchPayrollSetting?->pay_day_second ?? 30));

            if ($branchPayrollSetting) {
                $branchPayrollSetting->update([
                    'pay_cycle' => $payCycle,
                    'pay_day_first' => $payDayFirst,
                    'pay_day_second' => $payDaySecond,
                ]);
            } else {
                BranchPayrollSetting::create([
                    'shop_owner_id' => (int) $shopOwner->id,
                    'branch_name' => $shopOwner->business_name ?: 'Main Branch',
                    'pay_cycle' => $payCycle,
                    'pay_day_first' => $payDayFirst,
                    'pay_day_second' => $payDaySecond,
                    'is_active' => true,
                ]);
            }
        }

        return back()->with('success', 'Shop settings updated successfully.');
    }

    /**
     * Save the shop's PayMongo secret key (encrypted at rest).
     */
    public function updatePaymongoKey(Request $request): JsonResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        $validated = $request->validate([
            'paymongo_secret_key' => ['required', 'string', 'min:20', 'max:255', 'regex:/^sk_(test|live)_[A-Za-z0-9_-]+$/'],
        ], [
            'paymongo_secret_key.regex' => 'Please enter a valid PayMongo secret key (sk_test_... or sk_live_...).',
        ]);

        $shopOwner->update(['paymongo_secret_key' => $validated['paymongo_secret_key']]);

        return response()->json([
            'success' => true,
            'message' => 'PayMongo key saved successfully.',
        ]);
    }

    /**
     * Remove (revoke) the shop's PayMongo secret key.
     */
    public function removePaymongoKey(): JsonResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        $shopOwner->update(['paymongo_secret_key' => null]);

        return response()->json([
            'success' => true,
            'message' => 'PayMongo key removed. Online payments are now disabled for your shop.',
        ]);
    }

    /**
     * Save attendance geofence settings for this shop.
     */
    public function updateGeofence(Request $request, CaviteLocationPolicyService $caviteLocationPolicy): JsonResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        $validated = $request->validate([
            'attendance_geofence_enabled' => ['required', 'boolean'],
            'shop_latitude'  => ['nullable', 'numeric', 'between:-90,90'],
            'shop_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'shop_address'   => ['nullable', 'string', 'max:300'],
            'shop_geofence_radius' => ['nullable', 'integer', 'min:10', 'max:5000'],
        ]);

        // Cannot enable without coordinates
        if ($validated['attendance_geofence_enabled'] && (empty($validated['shop_latitude']) || empty($validated['shop_longitude']))) {
            return response()->json([
                'message' => 'Please set the shop coordinates before enabling the geofence.',
            ], 422);
        }

        $resolvedAddress = trim((string) ($validated['shop_address'] ?? ''));
        if ($resolvedAddress === '') {
            $resolvedAddress = $shopOwner->shop_address ?: $shopOwner->business_address;
        }

        $nextLatitude = $request->has('shop_latitude') ? ($validated['shop_latitude'] ?? null) : $shopOwner->shop_latitude;
        $nextLongitude = $request->has('shop_longitude') ? ($validated['shop_longitude'] ?? null) : $shopOwner->shop_longitude;

        $locationPolicy = $caviteLocationPolicy->validateUpdateLocation(
            $nextLatitude,
            $nextLongitude,
            $resolvedAddress,
            $request,
            $shopOwner,
            $shopOwner->id,
            [
                'email' => $shopOwner->email,
                'business_name' => $shopOwner->business_name,
                'target_type' => 'shop_owner',
                'target_id' => $shopOwner->id,
            ]
        );

        if (!$locationPolicy['allowed']) {
            return response()->json([
                'message' => $caviteLocationPolicy->denialMessage(),
                'errors' => $locationPolicy['errors'],
            ], 422);
        }

        $shopOwner->update([
            'attendance_geofence_enabled' => $validated['attendance_geofence_enabled'],
            'shop_latitude'  => $nextLatitude,
            'shop_longitude' => $nextLongitude,
            'shop_address'   => $resolvedAddress,
            'business_address' => $resolvedAddress,
            'shop_geofence_radius' => $validated['shop_geofence_radius'] ?? 100,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Geofence settings saved successfully.',
        ]);
    }

    /**
     * Build a complete approval-page settings payload with defaults.
     */
    private function normalizeApprovalPages(array $input): array
    {
        $defaults = [
            'refund_approval' => ['enabled' => false, 'limit' => null],
            'price_approval' => ['enabled' => false, 'limit' => null],
            'purchase_request_approval' => ['enabled' => false, 'limit' => null],
            'repair_reject_approval' => ['enabled' => false, 'limit' => null],
        ];

        $normalized = [];

        foreach ($defaults as $key => $defaultValues) {
            $record = is_array($input[$key] ?? null) ? $input[$key] : [];
            $enabled = (bool) ($record['enabled'] ?? $defaultValues['enabled']);
            $limitValue = $record['limit'] ?? $defaultValues['limit'];

            $normalized[$key] = [
                'enabled' => $enabled,
                'limit' => $enabled && $limitValue !== null && $limitValue !== '' ? (float) $limitValue : null,
            ];
        }

        return $normalized;
    }
}
