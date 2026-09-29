<?php

namespace App\Actions\superAdmin;

use App\Actions\ShopOwner\SubmitShopOwnerUpgradeRequest;
use App\Enums\PrivilegedDeliveryType;
use App\Enums\NotificationType;
use App\Exceptions\ShopOwnerUpgradeReviewConflict;
use App\Models\Notification;
use App\Models\ShopOwner;
use App\Models\ShopOwnerUpgradeRequest;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use App\Services\PrivilegedMailDispatcher;
use App\Services\ShopModuleProvisioningService;
use App\Services\ShopOwnerDocumentRequirementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ReviewShopOwnerUpgradeRequest
{
    public function __construct(
        private readonly ShopModuleProvisioningService $shopModuleProvisioning,
        private readonly ShopOwnerDocumentRequirementService $documentRequirements,
        private readonly SubmitShopOwnerUpgradeRequest $submission,
        private readonly PrivilegedAudit $audit,
        private readonly PrivilegedMailDispatcher $privilegedMailDispatcher,
    ) {}

    /**
     * @return array{request: ShopOwnerUpgradeRequest, owner: ShopOwner, decision: string, newly_enabled_module_keys: array<int, string>, dormant_employee_permission_warning: bool, conflict: bool}
     */
    public function handle(
        ShopOwnerUpgradeRequest $upgradeRequest,
        SuperAdmin $reviewer,
        string $decision,
        ?string $decisionReason = null,
        ?Request $request = null,
        array $reviewedDocuments = [],
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

        $httpRequest = $request ?? Request::create('/admin/business-upgrade-requests', 'PATCH');
        $correlationId = $this->audit->correlationId($httpRequest);
        $result = DB::transaction(function () use ($upgradeRequest, $reviewer, $decision, $decisionReason, $reviewedDocuments, $httpRequest, $correlationId): array {
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

                $this->audit->shopOwnerUpgradeSuperseded(
                    request: $httpRequest,
                    actor: $reviewer,
                    upgradeRequest: $lockedRequest,
                    oldRegistrationType: $this->ownerValue($owner, 'registration_type'),
                    oldBusinessType: $this->ownerValue($owner, 'business_type'),
                    newRegistrationType: $lockedRequest->requested_registration_type,
                    newBusinessType: $lockedRequest->requested_business_type,
                    decisionReason: $lockedRequest->decision_reason,
                );
                $this->queueReviewNotification($lockedRequest, $owner, $correlationId);

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

                $this->audit->shopOwnerUpgradeReviewed(
                    request: $httpRequest,
                    actor: $reviewer,
                    upgradeRequest: $lockedRequest,
                    decision: ShopOwnerUpgradeRequest::STATUS_REJECTED,
                    oldRegistrationType: $currentRegistrationType,
                    oldBusinessType: $currentBusinessType,
                    newRegistrationType: $currentRegistrationType,
                    newBusinessType: $currentBusinessType,
                    decisionReason: trim((string) $decisionReason),
                );
                $this->queueReviewNotification($lockedRequest, $owner, $correlationId);

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
            $this->validateEvidence($lockedRequest, $reviewedDocuments);

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
            $this->shopModuleProvisioning->initializeMissing($owner, $newlyEligibleKeys);

            $lockedRequest->update([
                'status' => ShopOwnerUpgradeRequest::STATUS_APPROVED,
                'decision_reason' => $decisionReason !== null ? trim($decisionReason) : null,
                'reviewed_by_super_admin_id' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            $dormantWarning = $this->hasDormantEmployeePermissionWarning($newlyEligibleKeys);
            $this->audit->shopOwnerUpgradeReviewed(
                request: $httpRequest,
                actor: $reviewer,
                upgradeRequest: $lockedRequest,
                decision: ShopOwnerUpgradeRequest::STATUS_APPROVED,
                oldRegistrationType: $currentRegistrationType,
                oldBusinessType: $currentBusinessType,
                newRegistrationType: $owner->registration_type,
                newBusinessType: $owner->business_type,
                decisionReason: $decisionReason !== null ? trim($decisionReason) : null,
                newlyEnabledModuleKeys: $newlyEligibleKeys,
                dormantEmployeePermissionWarning: $dormantWarning,
            );
            $this->queueReviewNotification(
                upgradeRequest: $lockedRequest,
                owner: $owner,
                correlationId: $correlationId,
                newlyEnabledModuleKeys: $newlyEligibleKeys,
                dormantEmployeePermissionWarning: $dormantWarning,
            );

            return [
                'request' => $lockedRequest->fresh('documents'),
                'owner' => $owner,
                'decision' => ShopOwnerUpgradeRequest::STATUS_APPROVED,
                'newly_enabled_module_keys' => $newlyEligibleKeys,
                'dormant_employee_permission_warning' => $dormantWarning,
                'conflict' => false,
            ];
        });

        return $result;
    }

    private function queueReviewNotification(
        ShopOwnerUpgradeRequest $upgradeRequest,
        ShopOwner $owner,
        string $correlationId,
        array $newlyEnabledModuleKeys = [],
        bool $dormantEmployeePermissionWarning = false,
    ): void {
        $decision = (string) $upgradeRequest->status;
        if (in_array($decision, [
            ShopOwnerUpgradeRequest::STATUS_APPROVED,
            ShopOwnerUpgradeRequest::STATUS_REJECTED,
        ], true)) {
            $approved = $decision === ShopOwnerUpgradeRequest::STATUS_APPROVED;
            $reason = trim((string) $upgradeRequest->decision_reason);

            Notification::create([
                'shop_owner_id' => (int) $owner->getKey(),
                'type' => $approved
                    ? NotificationType::BUSINESS_UPGRADE_REQUEST_APPROVED->value
                    : NotificationType::BUSINESS_UPGRADE_REQUEST_REJECTED->value,
                'title' => $approved
                    ? 'Business upgrade request approved'
                    : 'Business upgrade request rejected',
                'message' => $approved
                    ? 'Your business upgrade request has been approved.'
                    : 'Your business upgrade request was rejected.'.($reason !== '' ? " Reason: {$reason}" : ''),
                'action_url' => '/shop-owner/settings',
                'data' => [
                    'upgrade_request_id' => (int) $upgradeRequest->getKey(),
                    'decision' => $decision,
                    'decision_reason' => $upgradeRequest->decision_reason,
                    'newly_enabled_module_keys' => $newlyEnabledModuleKeys,
                ],
                'is_read' => false,
                'requires_action' => false,
                'priority' => $approved ? 'high' : 'normal',
            ]);
        }

        $this->privilegedMailDispatcher->dispatch(
            type: PrivilegedDeliveryType::SHOP_OWNER_UPGRADE_REVIEWED,
            businessEventId: 'shop-owner-upgrade-reviewed:'.$upgradeRequest->getKey(),
            recipientType: 'shop_owner',
            recipientId: (int) $owner->getKey(),
            payload: [
                'upgrade_request_id' => (int) $upgradeRequest->getKey(),
                'decision' => (string) $upgradeRequest->status,
                'decision_reason' => $upgradeRequest->decision_reason,
                'newly_enabled_module_keys' => $newlyEnabledModuleKeys,
                'dormant_employee_permission_warning' => $dormantEmployeePermissionWarning,
            ],
            correlationId: $correlationId,
        );
    }

    private function ownerSnapshotChanged(ShopOwner $owner, ShopOwnerUpgradeRequest $request): bool
    {
        return $this->ownerValue($owner, 'registration_type') !== strtolower(trim((string) $request->current_registration_type))
            || $this->normalizeBusinessType($this->ownerValue($owner, 'business_type')) !== $this->normalizeBusinessType((string) $request->current_business_type);
    }

    /**
     * @param  array<int, array{id?: mixed, viewed?: mixed}>  $reviewedDocuments
     */
    private function validateEvidence(ShopOwnerUpgradeRequest $request, array $reviewedDocuments): void
    {
        $expectedSnapshot = $this->documentRequirements->requirementSnapshot();
        if ($request->required_document_set !== $expectedSnapshot) {
            throw ValidationException::withMessages(['documents' => 'The required evidence set has changed. Please submit a new request.']);
        }

        $requiredTypes = $this->documentRequirements->requiredTypes();
        $documents = $request->documents;

        $storedDocumentIds = $documents
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        $reviewedDocumentIds = [];
        foreach ($reviewedDocuments as $reviewedDocument) {
            if (! is_array($reviewedDocument)) {
                throw ValidationException::withMessages([
                    'documents' => 'Every submitted document must be opened before approval.',
                ]);
            }

            $documentId = filter_var($reviewedDocument['id'] ?? null, FILTER_VALIDATE_INT);
            $viewed = filter_var(
                $reviewedDocument['viewed'] ?? null,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            );
            if ($documentId === false || $documentId < 1 || $viewed !== true) {
                throw ValidationException::withMessages([
                    'documents' => 'Every submitted document must be opened before approval.',
                ]);
            }

            $reviewedDocumentIds[] = (int) $documentId;
        }

        sort($reviewedDocumentIds);
        if ($storedDocumentIds !== $reviewedDocumentIds) {
            throw ValidationException::withMessages([
                'documents' => 'Every submitted document must be opened before approval.',
            ]);
        }

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
