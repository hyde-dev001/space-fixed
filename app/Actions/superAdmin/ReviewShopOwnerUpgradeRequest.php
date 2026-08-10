<?php

namespace App\Actions\superAdmin;

use App\Actions\ShopOwner\SubmitShopOwnerUpgradeRequest;
use App\Exceptions\ShopOwnerUpgradeReviewConflict;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\ShopOwnerUpgradeRequest;
use App\Models\SuperAdmin;
use App\Notifications\ShopOwnerUpgradeReviewed;
use App\Services\ShopModuleProvisioningService;
use App\Services\ShopOwnerDocumentRequirementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ReviewShopOwnerUpgradeRequest
{
    public function __construct(
        private readonly ShopModuleProvisioningService $shopModuleProvisioning,
        private readonly ShopOwnerDocumentRequirementService $documentRequirements,
        private readonly SubmitShopOwnerUpgradeRequest $submission,
    ) {}

    /**
     * @return array{request: ShopOwnerUpgradeRequest, owner: ShopOwner, decision: string, newly_enabled_module_keys: array<int, string>, dormant_employee_permission_warning: bool, conflict: bool}
     */
    public function handle(
        ShopOwnerUpgradeRequest $upgradeRequest,
        SuperAdmin $reviewer,
        string $decision,
        ?string $decisionReason = null,
    ): array {
        $decision = strtolower(trim($decision));
        if (! in_array($decision, [ShopOwnerUpgradeRequest::STATUS_APPROVED, ShopOwnerUpgradeRequest::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages(['decision' => 'That review decision is not supported.']);
        }

        if (! $reviewer->isActive()) {
            throw ValidationException::withMessages(['reviewer' => 'Only an active SuperAdmin can review upgrade requests.']);
        }

        if ($decision === ShopOwnerUpgradeRequest::STATUS_REJECTED && trim((string) $decisionReason) === '') {
            throw ValidationException::withMessages(['decision_reason' => 'A rejection reason is required.']);
        }

        $correlationId = (string) Str::uuid();
        $result = DB::transaction(function () use ($upgradeRequest, $reviewer, $decision, $decisionReason, $correlationId): array {
            $ownerId = (int) $upgradeRequest->shop_owner_id;
            $owner = ShopOwner::query()->lockForUpdate()->findOrFail($ownerId);
            $lockedRequest = ShopOwnerUpgradeRequest::query()
                ->with('documents')
                ->lockForUpdate()
                ->findOrFail($upgradeRequest->id);

            if ((int) $lockedRequest->shop_owner_id !== (int) $owner->id) {
                throw new ShopOwnerUpgradeReviewConflict('The upgrade request owner no longer matches the review target.');
            }

            if ($lockedRequest->status !== ShopOwnerUpgradeRequest::STATUS_PENDING) {
                throw new ShopOwnerUpgradeReviewConflict('This upgrade request has already been decided.');
            }

            if ($this->ownerSnapshotChanged($owner, $lockedRequest)) {
                $lockedRequest->update([
                    'status' => ShopOwnerUpgradeRequest::STATUS_SUPERSEDED,
                    'decision_reason' => 'The shop account changed before this request was reviewed.',
                    'reviewed_by_super_admin_id' => $reviewer->id,
                    'reviewed_at' => now(),
                ]);

                $this->recordDecisionActivity(
                    request: $lockedRequest,
                    reviewer: $reviewer,
                    correlationId: $correlationId,
                    decision: ShopOwnerUpgradeRequest::STATUS_SUPERSEDED,
                    oldRegistrationType: $this->ownerValue($owner, 'registration_type'),
                    oldBusinessType: $this->ownerValue($owner, 'business_type'),
                    newRegistrationType: $lockedRequest->requested_registration_type,
                    newBusinessType: $lockedRequest->requested_business_type,
                    decisionReason: $lockedRequest->decision_reason,
                );

                return [
                    'request' => $lockedRequest->fresh('documents'),
                    'owner' => $owner->fresh(),
                    'decision' => ShopOwnerUpgradeRequest::STATUS_SUPERSEDED,
                    'newly_enabled_module_keys' => [],
                    'dormant_employee_permission_warning' => false,
                    'conflict' => true,
                ];
            }

            if ($this->ownerValue($owner, 'status') !== 'approved') {
                throw ValidationException::withMessages([
                    'request' => 'The shop owner is not currently eligible for approval.',
                ]);
            }

            $currentRegistrationType = $this->ownerValue($owner, 'registration_type');
            $currentBusinessType = $this->normalizeBusinessType($this->ownerValue($owner, 'business_type'));

            if ($decision === ShopOwnerUpgradeRequest::STATUS_REJECTED) {
                $lockedRequest->update([
                    'status' => ShopOwnerUpgradeRequest::STATUS_REJECTED,
                    'decision_reason' => trim((string) $decisionReason),
                    'reviewed_by_super_admin_id' => $reviewer->id,
                    'reviewed_at' => now(),
                ]);

                $this->recordDecisionActivity(
                    request: $lockedRequest,
                    reviewer: $reviewer,
                    correlationId: $correlationId,
                    decision: ShopOwnerUpgradeRequest::STATUS_REJECTED,
                    oldRegistrationType: $currentRegistrationType,
                    oldBusinessType: $currentBusinessType,
                    newRegistrationType: $currentRegistrationType,
                    newBusinessType: $currentBusinessType,
                    decisionReason: trim((string) $decisionReason),
                );

                return [
                    'request' => $lockedRequest->fresh('documents'),
                    'owner' => $owner->fresh(),
                    'decision' => ShopOwnerUpgradeRequest::STATUS_REJECTED,
                    'newly_enabled_module_keys' => [],
                    'dormant_employee_permission_warning' => false,
                    'conflict' => false,
                ];
            }

            $this->submission->assertTransition(
                currentRegistrationType: $currentRegistrationType,
                currentBusinessType: $currentBusinessType,
                requestedRegistrationType: strtolower(trim((string) $lockedRequest->requested_registration_type)),
                requestedBusinessType: $this->normalizeBusinessType((string) $lockedRequest->requested_business_type),
            );
            $this->validateEvidence($lockedRequest);

            $preEligibleKeys = $this->shopModuleProvisioning->eligibleKeysFor($owner);
            $owner->update([
                'registration_type' => strtolower(trim((string) $lockedRequest->requested_registration_type)),
                'business_type' => $this->normalizeBusinessType((string) $lockedRequest->requested_business_type),
            ]);
            $owner = $owner->fresh();
            $postEligibleKeys = $this->shopModuleProvisioning->eligibleKeysFor($owner);
            $newlyEligibleKeys = array_values(array_diff($postEligibleKeys, $preEligibleKeys));
            $owner->modules()
                ->whereIn('module_key', $postEligibleKeys)
                ->lockForUpdate()
                ->get();
            $createdModules = $this->shopModuleProvisioning->initializeMissing($owner, $newlyEligibleKeys);

            $lockedRequest->update([
                'status' => ShopOwnerUpgradeRequest::STATUS_APPROVED,
                'decision_reason' => $decisionReason !== null ? trim($decisionReason) : null,
                'reviewed_by_super_admin_id' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            $this->recordDecisionActivity(
                request: $lockedRequest,
                reviewer: $reviewer,
                correlationId: $correlationId,
                decision: ShopOwnerUpgradeRequest::STATUS_APPROVED,
                oldRegistrationType: $currentRegistrationType,
                oldBusinessType: $currentBusinessType,
                newRegistrationType: $owner->registration_type,
                newBusinessType: $owner->business_type,
                decisionReason: $decisionReason !== null ? trim($decisionReason) : null,
            );

            foreach ($createdModules as $module) {
                $this->recordModuleActivity(
                    module: $module,
                    reviewer: $reviewer,
                    request: $lockedRequest,
                    owner: $owner,
                    correlationId: $correlationId,
                );
            }

            $dormantWarning = $this->hasDormantEmployeePermissionWarning($newlyEligibleKeys);

            return [
                'request' => $lockedRequest->fresh('documents'),
                'owner' => $owner,
                'decision' => ShopOwnerUpgradeRequest::STATUS_APPROVED,
                'newly_enabled_module_keys' => $newlyEligibleKeys,
                'dormant_employee_permission_warning' => $dormantWarning,
                'conflict' => false,
            ];
        });

        DB::afterCommit(function () use ($result): void {
            try {
                $result['owner']->notify(new ShopOwnerUpgradeReviewed(
                    upgradeRequest: $result['request'],
                    decision: $result['decision'],
                    decisionReason: $result['request']->decision_reason,
                    newlyEnabledModuleKeys: $result['newly_enabled_module_keys'],
                    dormantEmployeePermissionWarning: $result['dormant_employee_permission_warning'],
                ));
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        return $result;
    }

    private function ownerSnapshotChanged(ShopOwner $owner, ShopOwnerUpgradeRequest $request): bool
    {
        return $this->ownerValue($owner, 'registration_type') !== strtolower(trim((string) $request->current_registration_type))
            || $this->normalizeBusinessType($this->ownerValue($owner, 'business_type')) !== $this->normalizeBusinessType((string) $request->current_business_type);
    }

    private function validateEvidence(ShopOwnerUpgradeRequest $request): void
    {
        $expectedSnapshot = $this->documentRequirements->requirementSnapshot();
        if ($request->required_document_set !== $expectedSnapshot) {
            throw ValidationException::withMessages(['documents' => 'The required evidence set has changed. Please submit a new request.']);
        }

        $requiredTypes = $this->documentRequirements->requiredTypes();
        $documents = $request->documents;
        if ($documents->count() !== count($requiredTypes) || $documents->pluck('document_type')->unique()->count() !== count($requiredTypes)) {
            throw ValidationException::withMessages(['documents' => 'The request does not contain the complete evidence set.']);
        }

        foreach ($requiredTypes as $documentType) {
            $document = $documents->firstWhere('document_type', $documentType);
            if (! $document) {
                throw ValidationException::withMessages(["documents.{$documentType}" => 'The required evidence is missing.']);
            }

            $disk = (string) $document->disk;
            $path = (string) $document->getRawOriginal('path');
            $mime = strtolower((string) $document->mime_type);
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($disk !== 'local' || $path === '' || ! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)
                || ! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)
                || ! preg_match('/^[a-f0-9]{64}$/', (string) $document->checksum_sha256)) {
                throw ValidationException::withMessages(["documents.{$documentType}" => 'The stored evidence metadata is invalid.']);
            }

            try {
                $storage = Storage::disk($disk);
                if (! $storage->exists($path)) {
                    throw new \RuntimeException('missing');
                }
                $bytes = $storage->get($path);
            } catch (Throwable) {
                throw ValidationException::withMessages(["documents.{$documentType}" => 'The stored evidence is no longer available.']);
            }

            if ((int) $document->size !== strlen($bytes)
                || ! hash_equals((string) $document->checksum_sha256, hash('sha256', $bytes))) {
                throw ValidationException::withMessages(["documents.{$documentType}" => 'The stored evidence has changed.']);
            }
        }
    }

    private function hasDormantEmployeePermissionWarning(array $newlyEligibleKeys): bool
    {
        return array_intersect($newlyEligibleKeys, [
            'hr_employees',
            'finance',
            'crm',
            'inventory',
            'procurement',
            'logistics',
        ]) !== [];
    }

    private function recordDecisionActivity(
        ShopOwnerUpgradeRequest $request,
        SuperAdmin $reviewer,
        string $correlationId,
        string $decision,
        string $oldRegistrationType,
        string $oldBusinessType,
        string $newRegistrationType,
        string $newBusinessType,
        ?string $decisionReason,
    ): void {
        activity()
            ->causedBy($reviewer)
            ->performedOn($request)
            ->withProperties([
                'actor_type' => 'super_admin',
                'actor_guard' => 'super_admin',
                'actor_id' => (int) $reviewer->id,
                'shop_owner_id' => (int) $request->shop_owner_id,
                'upgrade_request_id' => (int) $request->id,
                'correlation_id' => $correlationId,
                'decision' => $decision,
                'old_registration_type' => $oldRegistrationType,
                'new_registration_type' => $newRegistrationType,
                'old_business_type' => $oldBusinessType,
                'new_business_type' => $newBusinessType,
                'decision_reason' => $decisionReason,
            ])
            ->log('shop_owner_upgrade_'.$decision);
    }

    private function recordModuleActivity(
        ShopOwnerModule $module,
        SuperAdmin $reviewer,
        ShopOwnerUpgradeRequest $request,
        ShopOwner $owner,
        string $correlationId,
    ): void {
        activity()
            ->causedBy($reviewer)
            ->performedOn($module)
            ->withProperties([
                'actor_type' => 'super_admin',
                'actor_guard' => 'super_admin',
                'actor_id' => (int) $reviewer->id,
                'shop_owner_id' => (int) $owner->id,
                'upgrade_request_id' => (int) $request->id,
                'correlation_id' => $correlationId,
                'module_key' => (string) $module->module_key,
                'old_enabled' => null,
                'new_enabled' => (bool) $module->enabled,
            ])
            ->log('shop_owner_module_initialized');
    }

    private function normalizeBusinessType(string $value): string
    {
        $normalized = strtolower(trim($value));
        if (str_contains($normalized, 'both')) {
            return 'both';
        }

        return in_array($normalized, ['retail', 'repair'], true) ? $normalized : '';
    }

    private function ownerValue(ShopOwner $owner, string $attribute): string
    {
        $value = $owner->getRawOriginal($attribute);
        if ($value === null) {
            $value = $owner->getAttribute($attribute);
        }

        return $value instanceof \BackedEnum ? $value->value : strtolower(trim((string) $value));
    }
}
