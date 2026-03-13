<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\ProcurementSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $procurementSettings = ProcurementSettings::getForShopOwner($shopOwner->id);
        $approvalPages = $this->normalizeApprovalPages($procurementSettings->settings_json['approval_pages'] ?? []);

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
                'file_url' => $filePath ? Storage::disk('public')->url($filePath) : null,
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
                // Geofence
                'attendance_geofence_enabled' => (bool) $shopOwner->attendance_geofence_enabled,
                'shop_latitude'          => $shopOwner->shop_latitude,
                'shop_longitude'         => $shopOwner->shop_longitude,
                'shop_address'           => $shopOwner->shop_address ?? $shopOwner->business_address,
                'shop_geofence_radius'   => $shopOwner->shop_geofence_radius ?? 100,
            ],
        ]);
    }

    /**
     * Update shop settings for the authenticated shop owner account.
     */
    public function update(Request $request): RedirectResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        $procurementSettings = ProcurementSettings::getForShopOwner($shopOwner->id);

        $validated = $request->validate([
            'approval_pages' => ['required', 'array'],
            'approval_pages.refund_approval.enabled' => ['required', 'boolean'],
            'approval_pages.refund_approval.limit' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'approval_pages.price_approval.enabled' => ['required', 'boolean'],
            'approval_pages.price_approval.limit' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'approval_pages.purchase_request_approval.enabled' => ['required', 'boolean'],
            'approval_pages.purchase_request_approval.limit' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'approval_pages.repair_reject_approval.enabled' => ['required', 'boolean'],
            'approval_pages.repair_reject_approval.limit' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'repair_payment_policy' => ['sometimes', 'string', 'in:deposit_50,full_upfront,pay_after'],
            'repair_workload_limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $normalizedApprovalPages = $this->normalizeApprovalPages($validated['approval_pages']);

        $settingsJson = $procurementSettings->settings_json ?? [];
        $settingsJson['approval_pages'] = $normalizedApprovalPages;

        $procurementSettings->update([
            'settings_json' => $settingsJson,
        ]);

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

        return back()->with('success', 'Shop settings updated successfully.');
    }

    /**
     * Save the shop's PayMongo secret key (encrypted at rest).
     */
    public function updatePaymongoKey(Request $request): JsonResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        $validated = $request->validate([
            'paymongo_secret_key' => ['required', 'string', 'min:20'],
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
    public function updateGeofence(Request $request): JsonResponse
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

        $shopOwner->update([
            'attendance_geofence_enabled' => $validated['attendance_geofence_enabled'],
            'shop_latitude'  => $validated['shop_latitude'] ?? null,
            'shop_longitude' => $validated['shop_longitude'] ?? null,
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
