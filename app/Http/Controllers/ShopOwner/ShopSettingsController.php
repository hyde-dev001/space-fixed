<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\HR\BranchPayrollSetting;
use App\Models\ProcurementSettings;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerUpgradeRequest;
use App\Models\ShopPolicyVersion;
use App\Services\CaviteLocationPolicyService;
use App\Services\ShopModuleAccessService;
use App\Services\ShopOwnerDocumentRequirementService;
use App\Services\ShopDocumentValidityService;
use App\Services\ShopPolicyTemplateService;
use App\Services\ShopPolicyVersionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
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
    private const APPROVAL_PAGE_KEYS = [
        'refund_approval',
        'price_approval',
        'payslip_approval',
        'salary_adjustment_approval',
        'purchase_request_approval',
        'expense_approval',
        'repair_reject_approval',
    ];

    private const INITIAL_SECTION_KEYS = [
        'profile',
        'modules-team',
        'payments-approvals',
        'operations',
        'policies-compliance',
        'subscription',
    ];

    public function __construct(
        private readonly ShopOwnerDocumentRequirementService $documentRequirements,
        private readonly ShopDocumentValidityService $documentValidity,
        private readonly ShopModuleAccessService $shopModuleAccess,
    ) {}

    /**
     * Display the shop settings page for the authenticated shop owner.
     */
    public function index(Request $request): Response
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        $normalizedRepairPaymentPolicy = 'full_upfront';
        $shopOwner->load('documents');
        $entitledPremiumSubscription = ShopOwnerSubscription::with('premiumPlan')
            ->where('shop_owner_id', $shopOwner->id)
            ->showroomEntitled()
            ->latest('updated_at')
            ->first();

        $latestPremiumSubscription = $entitledPremiumSubscription ?: ShopOwnerSubscription::with('premiumPlan')
            ->where('shop_owner_id', $shopOwner->id)
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'pending' THEN 1 WHEN 'expired' THEN 2 WHEN 'cancelled' THEN 3 WHEN 'failed' THEN 4 ELSE 5 END")
            ->latest('updated_at')
            ->first();

        $hasPremiumAccess = (bool) ($latestPremiumSubscription
            && in_array((string) $latestPremiumSubscription->status, ['active', 'cancelled'], true)
            && (!$latestPremiumSubscription->starts_at || $latestPremiumSubscription->starts_at->lte(now()))
            && (!$latestPremiumSubscription->ends_at || $latestPremiumSubscription->ends_at->gte(now())));
        $procurementSettings = ProcurementSettings::getForShopOwner($shopOwner->id);
        $approvalPages = $this->normalizeApprovalPagesForRead($procurementSettings->settings_json['approval_pages'] ?? []);
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

        $requiredDocuments = $this->documentRequirements->settingsPayload($shopOwner->documents);
        $documentCompliance = $this->documentCompliancePayload($shopOwner);
        $businessScaling = $this->businessScalingPayload($shopOwner, $businessType);

        return Inertia::render('ShopOwner/Settings/shopSetting', [
            'shop_settings' => [
                'registration_type'      => $shopOwner->registration_type,
                'business_type'          => $shopOwner->business_type,
                'can_manage_staff'       => $shopOwner->canManageStaff(),
                'max_locations'          => $shopOwner->getMaxLocations(),
                'business_name'          => $shopOwner->business_name,
                'approval_pages'         => $approvalPages,
                'required_documents'     => $requiredDocuments,
                'document_compliance'    => $documentCompliance,
                'business_scaling'       => $businessScaling,
                'repair_payment_policy'  => $normalizedRepairPaymentPolicy,
                'repair_workload_limit'  => (int) ($shopOwner->repair_workload_limit ?? 20),
                'order_refund_deadline_days' => (int) ($shopOwner->order_refund_deadline_days ?? 7),
                'two_factor_email_enabled' => (bool) ($shopOwner->two_factor_email_enabled ?? false),
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
                    'has_active' => $hasPremiumAccess,
                    'auto_renew' => $latestPremiumSubscription?->auto_renew,
                    'auto_renew_status' => $latestPremiumSubscription?->auto_renew_status,
                    'plan_name' => $latestPremiumSubscription?->premiumPlan?->name,
                    'plan_code' => $latestPremiumSubscription?->plan_code,
                    'showroom_slot_limit' => $latestPremiumSubscription?->showroom_slot_limit,
                    'starts_at' => $latestPremiumSubscription?->starts_at?->toIso8601String(),
                    'ends_at' => $latestPremiumSubscription?->ends_at?->toIso8601String(),
                ],
            ],
            'initialSection' => $this->initialSection($request),
        ]);
    }

    private function initialSection(Request $request): string
    {
        $section = $request->route('initial_section');

        return is_string($section) && in_array($section, self::INITIAL_SECTION_KEYS, true)
            ? $section
            : 'profile';
    }

    /**
     * Return the owner-facing lifecycle view without exposing storage details.
     * The legacy business-registration fallback remains visibly ambiguous.
     *
     * @return array<int, array<string, mixed>>
     */
    private function documentCompliancePayload(ShopOwner $shopOwner): array
    {
        $rows = $shopOwner->documents->sortByDesc('id')->values();
        $slots = [
            'business_registration' => 'Business Registration (DTI or SEC)',
            'mayors_permit' => "Mayor's Permit / Business Permit",
            'bir_certificate' => 'BIR Certificate of Registration',
            'valid_id' => 'Valid ID of Owner',
        ];

        foreach ($rows as $document) {
            $slot = trim((string) $document->logical_slot);
            $type = $this->documentRequirements->normalizeType((string) $document->document_type);

            if ($slot === '' && $this->documentRequirements->isLegacyBusinessDocument($document)) {
                $slot = 'business_registration';
            }

            if ($slot !== '' && str_starts_with($slot, 'supporting_document:')) {
                $slots[$slot] ??= 'Supporting Document';
            }
        }

        $payload = [];
        foreach ($slots as $slot => $title) {
            $slotRows = $rows->filter(function ($document) use ($slot): bool {
                $documentSlot = trim((string) $document->logical_slot);
                if ($documentSlot === $slot) {
                    return true;
                }

                return $slot === 'business_registration'
                    && $documentSlot === ''
                    && in_array($this->documentRequirements->normalizeType((string) $document->document_type), ['dti_registration', 'sec_registration'], true);
            })->sortByDesc('id')->values();
            $current = $slotRows->first(function ($document): bool {
                return (string) $document->status === 'approved' && (bool) $document->is_current;
            });
            $legacyCurrent = $current === null && $slot === 'business_registration'
                ? $slotRows->first(fn ($document): bool => (string) $document->status === 'approved'
                    && $this->documentRequirements->hasPrivateStoredFile($document)
                    && $this->isUnreconciledLegacyDocument($document))
                : null;
            $current ??= $legacyCurrent;
            $pending = $slotRows->first(function ($document): bool {
                return (string) $document->status === 'pending' && ! (bool) $document->is_current;
            });

            $payload[] = [
                'logical_slot' => $slot,
                'title' => $title,
                'current' => $current ? $this->serializeComplianceDocument(
                    $current,
                    $shopOwner,
                    $legacyCurrent !== null || $this->documentRequirements->isLegacyBusinessDocument($current),
                ) : null,
                'pending' => $pending ? $this->serializeComplianceDocument($pending, $shopOwner, false) : null,
                'history' => $slotRows->map(fn ($document): array => $this->serializeComplianceDocument(
                    $document,
                    $shopOwner,
                    ($legacyCurrent !== null && $document->getKey() === $legacyCurrent->getKey())
                        || $this->documentRequirements->isLegacyBusinessDocument($document),
                ))->values()->all(),
            ];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function serializeComplianceDocument(ShopDocument $document, ShopOwner $shopOwner, bool $legacy): array
    {
        $logicalSlot = trim((string) $document->logical_slot);
        $documentType = str_starts_with($logicalSlot, 'supporting_document:')
            ? 'supporting_document'
            : (string) $document->document_type;

        return [
            'id' => (int) $document->getKey(),
            'document_type' => $documentType,
            'logical_slot' => $logicalSlot !== ''
                ? $logicalSlot
                : 'business_registration',
            'version_number' => $document->version_number !== null ? (int) $document->version_number : null,
            'status' => (string) $document->status,
            'issued_on' => $document->issued_on?->toDateString(),
            'expiration_mode' => $document->expiration_mode,
            'expires_on' => $document->expires_on?->toDateString(),
            'validity' => $this->documentValidity->classify($document),
            'legacy_label' => $legacy ? 'Legacy DTI/SEC — classification pending' : null,
            'url' => route('shop-owner.documents.show', [
                'shopOwner' => $shopOwner->getKey(),
                'document' => $document->getKey(),
            ]),
        ];
    }

    /**
     * Build the owner-facing business scaling payload without exposing private
     * evidence storage details or full upgrade request records.
     *
     * @return array<string, mixed>
     */
    private function businessScalingPayload(ShopOwner $shopOwner, string $businessType): array
    {
        $registrationType = strtolower(trim((string) $shopOwner->registration_type));
        $businessType = $this->normalizeBusinessType($businessType);
        $accountTransitions = [];
        $capabilityTransitions = [];
        $combinedTransitions = [];

        if ($registrationType === 'individual') {
            $accountTransitions[] = [
                'key' => 'individual_to_company',
                'label' => 'Business account',
                'requested_registration_type' => 'company',
                'requested_business_type' => $businessType,
            ];
        }

        if (in_array($businessType, ['retail', 'repair'], true)) {
            $capabilityTransitions[] = [
                'key' => $businessType.'_to_both',
                'label' => $businessType === 'retail' ? 'Retail + Repair' : 'Repair + Retail',
                'requested_registration_type' => $registrationType,
                'requested_business_type' => 'both',
            ];

            if ($registrationType === 'individual') {
                $combinedTransitions[] = [
                    'key' => 'individual_'.$businessType.'_to_company_both',
                    'label' => 'Business account + both capabilities',
                    'requested_registration_type' => 'company',
                    'requested_business_type' => 'both',
                ];
            }
        }

        $pendingRequest = null;
        $latestTerminalRequest = null;
        if (Schema::hasTable('shop_owner_upgrade_requests')) {
            $pendingRequest = $shopOwner->upgradeRequests()
                ->with('documents')
                ->where('status', ShopOwnerUpgradeRequest::STATUS_PENDING)
                ->latest('id')
                ->first();
            $latestTerminalRequest = $shopOwner->upgradeRequests()
                ->with('documents')
                ->whereIn('status', [
                    ShopOwnerUpgradeRequest::STATUS_APPROVED,
                    ShopOwnerUpgradeRequest::STATUS_REJECTED,
                    ShopOwnerUpgradeRequest::STATUS_SUPERSEDED,
                ])
                ->latest('id')
                ->first();
        }

        $requiredEvidence = [];
        foreach ($this->documentRequirements->requirementSnapshot() as $key => $definition) {
            $document = $this->selectUpgradeEvidenceDocument($shopOwner, $key);
            $requiredEvidence[] = [
                'key' => $key,
                'title' => $definition['title'],
                'description' => $definition['description'],
                'required' => true,
                'existing_document_id' => $document?->status === 'approved' ? (int) $document->id : null,
                'existing_status' => $document?->status,
                'legacy_label' => $document && $this->documentRequirements->isLegacyBusinessDocument($document)
                    ? 'Legacy DTI/SEC — classification pending'
                    : null,
            ];
        }

        $moduleCatalog = [];
        foreach (config('shop_modules.modules', []) as $moduleKey => $module) {
            $moduleCatalog[] = [
                'key' => (string) $moduleKey,
                'label' => (string) ($module['label'] ?? $moduleKey),
                'registration_types' => array_values($module['registration_types'] ?? []),
                'business_types' => array_values($module['business_types'] ?? []),
            ];
        }

        $modules = Schema::hasTable('shop_owner_modules')
            ? $this->shopModuleAccess->statesFor($shopOwner)
            : $this->fallbackModuleStates($shopOwner, $registrationType, $businessType);

        return [
            'current' => [
                'registration_type' => $registrationType,
                'business_type' => $businessType,
            ],
            'available_account_transitions' => $accountTransitions,
            'available_capability_transitions' => $capabilityTransitions,
            'available_combined_transitions' => $combinedTransitions,
            'pending_request' => $this->serializeUpgradeRequest($pendingRequest),
            'latest_terminal_request' => $this->serializeUpgradeRequest($latestTerminalRequest),
            'required_evidence' => $requiredEvidence,
            'module_catalog' => $moduleCatalog,
            'modules' => $modules,
        ];
    }

    private function selectUpgradeEvidenceDocument(ShopOwner $shopOwner, string $documentType): ?ShopDocument
    {
        $candidates = $shopOwner->documents
            ->filter(function (ShopDocument $document) use ($documentType): bool {
                if ((string) $document->status !== 'approved'
                    || ! $this->documentRequirements->hasPrivateStoredFile($document)) {
                    return false;
                }

                if ($documentType === 'dti_registration' && $this->documentRequirements->isLegacyBusinessDocument($document)) {
                    return true;
                }

                return (bool) $document->is_current
                    && (string) $document->logical_slot === $documentType
                    && $this->documentRequirements->normalizeType((string) $document->document_type) === $documentType;
            })
            ->sortByDesc(fn (ShopDocument $document): array => [
                $document->created_at?->getTimestamp() ?? 0,
                (int) $document->getKey(),
            ]);

        if ($documentType !== 'dti_registration') {
            return $candidates->first();
        }

        $current = $candidates->first(fn (ShopDocument $document): bool => (bool) $document->is_current);
        if ($current) {
            return $current;
        }

        return $shopOwner->status?->value === 'approved' ? $candidates->first() : null;
    }

    private function isUnreconciledLegacyDocument(ShopDocument $document): bool
    {
        return $this->documentRequirements->isLegacyBusinessDocument($document)
            && $document->logical_slot === null
            && $document->version_number === null
            && $document->expiration_mode === null
            && $document->is_current === null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeUpgradeRequest(?ShopOwnerUpgradeRequest $request): ?array
    {
        if (! $request) {
            return null;
        }

        return [
            'id' => (int) $request->id,
            'status' => (string) $request->status,
            'current_registration_type' => (string) $request->current_registration_type,
            'current_business_type' => (string) $request->current_business_type,
            'requested_registration_type' => (string) $request->requested_registration_type,
            'requested_business_type' => (string) $request->requested_business_type,
            'decision_reason' => $request->decision_reason,
            'submitted_at' => $request->created_at?->toIso8601String(),
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'documents' => $request->documents->map(static fn ($document): array => [
                'document_type' => (string) $document->document_type,
                'status' => (string) ($document->source_status ?? ''),
                'source_status' => (string) ($document->source_status ?? ''),
            ])->values()->all(),
        ];
    }

    /**
     * Keep the settings page renderable during a deployment where the module
     * tables have not yet been migrated. Access remains fail-closed.
     *
     * @return array<string, array<string, bool|string|null>>
     */
    private function fallbackModuleStates(ShopOwner $shopOwner, string $registrationType, string $businessType): array
    {
        $status = strtolower(trim((string) $shopOwner->status));
        $states = [];
        foreach (config('shop_modules.modules', []) as $moduleKey => $module) {
            $eligible = $status === 'approved'
                && in_array($registrationType, $module['registration_types'] ?? [], true)
                && in_array($businessType, $module['business_types'] ?? [], true);
            $states[$moduleKey] = [
                'eligible' => $eligible,
                'enabled' => false,
                'accessible' => false,
                'code' => 'MODULE_STATE_MISSING',
                'reason' => 'This module has not been initialized for the shop.',
            ];
        }

        return $states;
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

    private function hasPolicyVersionStorageSchema(): bool
    {
        if (!Schema::hasTable('shop_policy_versions')) {
            return false;
        }

        $requiredColumns = [
            'shop_owner_id',
            'version_number',
            'status',
            'business_type_scope',
            'registration_clause_mode',
            'policy_sections_json',
            'content_hash',
        ];

        foreach ($requiredColumns as $column) {
            if (!Schema::hasColumn('shop_policy_versions', $column)) {
                return false;
            }
        }

        return true;
    }

    private function normalizePolicySectionValue(mixed $value): string
    {
        if (is_null($value)) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '' : $encoded;
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
            'approval_pages.payslip_approval.enabled' => ['required_with:approval_pages', 'boolean'],
            'approval_pages.salary_adjustment_approval.enabled' => ['required_with:approval_pages', 'boolean'],
            'approval_pages.purchase_request_approval.enabled' => ['required_with:approval_pages', 'boolean'],
            'approval_pages.purchase_request_approval.limit' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'approval_pages.expense_approval.enabled' => ['required_with:approval_pages', 'boolean'],
            'approval_pages.repair_reject_approval.enabled' => ['required_with:approval_pages', 'boolean'],
            'approval_pages.repair_reject_approval.limit' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'repair_payment_policy' => ['sometimes', 'string', 'in:full_upfront'],
            'repair_workload_limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'order_refund_deadline_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'two_factor_email_enabled' => ['sometimes', 'boolean'],
            'pay_cycle' => ['sometimes', 'string', 'in:monthly,semi_monthly'],
            'pay_day_first' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'pay_day_second' => ['sometimes', 'integer', 'min:1', 'max:31', 'gt:pay_day_first'],
        ]);

        if (array_key_exists('approval_pages', $validated)) {
            $settingsJson = $procurementSettings->settings_json ?? [];
            $settingsJson['approval_pages'] = $this->mergeApprovalPagesForStorage(
                $validated['approval_pages'],
                is_array($settingsJson['approval_pages'] ?? null) ? $settingsJson['approval_pages'] : [],
            );

            $procurementSettings->update([
                'settings_json' => $settingsJson,
            ]);
        }

        // Save payment policy and workload limit directly on the shop owner record
        $shopOwnerUpdates = ['repair_payment_policy' => 'full_upfront'];
        if (isset($validated['repair_workload_limit'])) {
            $shopOwnerUpdates['repair_workload_limit'] = $validated['repair_workload_limit'];
        }
        if (isset($validated['order_refund_deadline_days'])) {
            $shopOwnerUpdates['order_refund_deadline_days'] = $validated['order_refund_deadline_days'];
        }
        if (array_key_exists('two_factor_email_enabled', $validated)) {
            $shopOwnerUpdates['two_factor_email_enabled'] = (bool) $validated['two_factor_email_enabled'];
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
     * Return the current policy editor state (latest draft, active published, and defaults).
     */
    public function getPolicyEditorState(ShopPolicyTemplateService $templateService): JsonResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        $defaultSections = $templateService->buildSections(
            (string) ($shopOwner->business_type ?? ''),
            (string) ($shopOwner->registration_type ?? '')
        );

        if (!$this->hasPolicyVersionStorageSchema()) {
            return response()->json([
                'success' => true,
                'message' => 'Policy storage is not ready yet. Please run the latest migrations.',
                'data' => [
                    'active' => null,
                    'draft' => null,
                    'default_sections' => $defaultSections,
                    'storage_ready' => false,
                ],
            ]);
        }

        try {
            $active = ShopPolicyVersion::query()
                ->where('shop_owner_id', (int) $shopOwner->id)
                ->where('status', 'published')
                ->latest('version_number')
                ->first();

            $draftQuery = ShopPolicyVersion::query()
                ->where('shop_owner_id', (int) $shopOwner->id)
                ->where('status', 'draft');

            if ($active) {
                $draftQuery->where('version_number', '>', (int) $active->version_number);
            }

            $draft = $draftQuery
                ->latest('version_number')
                ->first();
        } catch (QueryException $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Policy storage is not ready yet. Please run the latest migrations.',
                'data' => [
                    'active' => null,
                    'draft' => null,
                    'default_sections' => $defaultSections,
                    'storage_ready' => false,
                ],
            ], 503);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'active' => $active,
                'draft' => $draft,
                'default_sections' => $defaultSections,
                'storage_ready' => true,
            ],
        ]);
    }

    /**
     * Save a draft policy version for the authenticated shop owner.
     */
    public function savePolicyDraft(Request $request, ShopPolicyVersionService $policyVersionService): JsonResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$this->hasPolicyVersionStorageSchema()) {
            return response()->json([
                'success' => false,
                'message' => 'Policy storage is not ready yet. Please run the latest migrations.',
            ], 503);
        }

        $validated = $request->validate([
            'policy_sections_json' => ['required', 'array'],
        ]);

        $incomingSections = collect((array) $validated['policy_sections_json'])
            ->map(fn ($value) => $this->normalizePolicySectionValue($value))
            ->all();

        try {
            $draft = $policyVersionService->saveDraft((int) $shopOwner->id, $incomingSections);
        } catch (QueryException $exception) {
            report($exception);

            $message = strtolower($exception->getMessage());
            if (
                str_contains($message, 'shop_policy_versions')
                && (str_contains($message, "doesn't exist") || str_contains($message, 'unknown column') || str_contains($message, 'no such table'))
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Policy storage is not ready yet. Please run the latest migrations.',
                ], 503);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to save policy draft. Please try again.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => $draft,
        ]);
    }

    /**
     * Publish the latest draft policy version for this shop owner.
     */
    public function publishPolicy(ShopPolicyVersionService $policyVersionService): JsonResponse
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$this->hasPolicyVersionStorageSchema()) {
            return response()->json([
                'success' => false,
                'message' => 'Policy storage is not ready yet. Please run the latest migrations.',
            ], 503);
        }

        try {
            $published = $policyVersionService->publishLatestDraft((int) $shopOwner->id, (int) $shopOwner->id);
        } catch (QueryException $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Policy storage is not ready yet. Please run the latest migrations.',
            ], 503);
        } catch (ModelNotFoundException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'No draft policy found to publish. Save a draft first, then publish.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $published,
        ]);
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
     * Expose only the active binary controls. Legacy limits stay in storage.
     *
     * @return array<string, array{enabled: bool}>
     */
    private function normalizeApprovalPagesForRead(array $input): array
    {
        $normalized = [];
        foreach (self::APPROVAL_PAGE_KEYS as $key) {
            $record = is_array($input[$key] ?? null) ? $input[$key] : [];
            $normalized[$key] = [
                'enabled' => is_bool($record['enabled'] ?? null) ? $record['enabled'] : true,
            ];
        }

        return $normalized;
    }

    /**
     * Merge only the seven validated booleans into existing settings.
     * Unknown JSON and legacy limits remain intact for compatibility.
     *
     * @return array<string, mixed>
     */
    private function mergeApprovalPagesForStorage(array $input, array $existing): array
    {
        $merged = $existing;

        foreach (self::APPROVAL_PAGE_KEYS as $key) {
            $record = is_array($merged[$key] ?? null) ? $merged[$key] : [];
            $incoming = is_array($input[$key] ?? null) ? $input[$key] : [];
            $record['enabled'] = (bool) ($incoming['enabled'] ?? true);
            $merged[$key] = $record;
        }

        return $merged;
    }
}
