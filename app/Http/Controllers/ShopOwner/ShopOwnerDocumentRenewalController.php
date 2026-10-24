<?php

declare(strict_types=1);

namespace App\Http\Controllers\ShopOwner;

use App\Enums\NotificationType;
use App\Enums\PrivilegedDeliveryType;
use App\Enums\ShopOwnerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShopOwner\SubmitShopDocumentRenewalRequest;
use App\Models\Notification;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Services\PrivilegedMailDispatcher;
use App\Services\ShopDocumentLifecycleService;
use App\Services\ShopDocumentValidityService;
use App\Services\ShopOwnerDocumentRequirementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ShopOwnerDocumentRenewalController extends Controller
{
    public function __construct(
        private readonly ShopDocumentLifecycleService $lifecycle,
        private readonly ShopDocumentValidityService $validity,
        private readonly ShopOwnerDocumentRequirementService $requirements,
        private readonly PrivilegedMailDispatcher $mailDispatcher,
    ) {}

    public function store(SubmitShopDocumentRenewalRequest $request, ShopDocument $document): JsonResponse
    {
        $owner = $request->user('shop_owner');
        abort_unless($owner instanceof ShopOwner, 401);

        if ((int) $document->shop_owner_id !== (int) $owner->getKey()) {
            abort(404);
        }

        if ($this->statusValue($owner->status) !== ShopOwnerStatus::APPROVED->value) {
            throw ValidationException::withMessages([
                'status' => ['Only approved shop owners can submit document renewals.'],
            ]);
        }

        $validated = $request->validated();
        $documentType = $this->requirements->normalizeType((string) $validated['document_type']);
        $logicalSlot = Str::of((string) $validated['logical_slot'])->trim()->lower()->toString();
        $expectedSlot = $this->requirements->slotForType($documentType);

        if ($documentType === 'supporting_document') {
            $expectedSlot = $this->requirements->slotForType($logicalSlot);
        }

        $predecessorSlot = $this->predecessorSlot($document);
        if ($expectedSlot === null || $expectedSlot !== $logicalSlot) {
            throw ValidationException::withMessages([
                'logical_slot' => ['The document type and logical slot do not match.'],
            ]);
        }

        if ($predecessorSlot !== $logicalSlot) {
            throw new ConflictHttpException('The selected document is not the current predecessor for this logical slot.');
        }

        if ((string) $document->status !== 'approved' || ! (bool) $document->is_current) {
            throw new ConflictHttpException('Only the current approved document can be renewed.');
        }

        if (! $this->isAllowedRenewalType($documentType, $logicalSlot, $document)) {
            throw ValidationException::withMessages([
                'document_type' => ['The renewal type is not valid for this logical slot.'],
            ]);
        }

        $metadata = [
            'document_type' => $documentType,
            'logical_slot' => $logicalSlot,
            'issued_on' => $validated['issued_on'] ?? null,
            'expiration_mode' => (string) $validated['expiration_mode'],
            'expires_on' => $validated['expires_on'] ?? null,
        ];
        $metadataErrors = $this->requirements->validateDocumentMetadata($metadata);
        if ($metadataErrors !== []) {
            throw ValidationException::withMessages($metadataErrors);
        }

        $submissionKey = (string) $validated['submission_key'];
        $existing = ShopDocument::query()->where('submission_key', $submissionKey)->first();
        if ($existing && (int) $existing->shop_owner_id !== (int) $owner->getKey()) {
            throw new ConflictHttpException('The submission key is already used by another shop owner.');
        }

        $pending = $this->lifecycle->createPendingVersion(
            shopOwner: $owner,
            metadata: $metadata,
            file: $validated['file'],
            predecessor: $document,
            submissionKey: $submissionKey,
        );

        if (! $existing) {
            $this->notifyEligibleReviewers($pending, $owner);
        }

        return response()->json([
            'success' => true,
            'document' => $this->serializeDocument($pending, $owner),
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeDocument(ShopDocument $document, ShopOwner $owner): array
    {
        return [
            'id' => (int) $document->getKey(),
            'document_type' => (string) $document->document_type,
            'logical_slot' => (string) $document->logical_slot,
            'version_number' => (int) $document->version_number,
            'status' => (string) $document->status,
            'issued_on' => $document->issued_on?->toDateString(),
            'expiration_mode' => (string) $document->expiration_mode,
            'expires_on' => $document->expires_on?->toDateString(),
            'validity' => $this->validity->classify($document),
            'url' => route('shop-owner.documents.show', [
                'shopOwner' => $owner->getKey(),
                'document' => $document->getKey(),
            ]),
        ];
    }

    private function notifyEligibleReviewers(ShopDocument $document, ShopOwner $owner): void
    {
        $reviewers = SuperAdmin::query()->active()->get()->filter(
            fn (SuperAdmin $reviewer): bool => $reviewer->hasCompletedMfaSetup()
                && $reviewer->hasCapability(SuperAdmin::CAP_REVIEW_REGISTRATIONS),
        );

        foreach ($reviewers as $reviewer) {
            Notification::firstOrCreate(
                [
                    'super_admin_id' => $reviewer->getKey(),
                    'type' => NotificationType::SHOP_DOCUMENT_RENEWAL_PENDING->value,
                    'group_key' => 'shop-document-renewal:'.$document->getKey(),
                ],
                [
                    'title' => 'Document renewal pending',
                    'message' => $owner->business_name.' submitted a document renewal for review.',
                    'action_url' => '/admin/document-renewals?document_id='.$document->getKey(),
                    'data' => [
                        'document_id' => (int) $document->getKey(),
                        'shop_owner_id' => (int) $owner->getKey(),
                        'logical_slot' => (string) $document->logical_slot,
                    ],
                    'is_read' => false,
                    'requires_action' => true,
                    'is_archived' => false,
                    'priority' => 'high',
                ],
            );

            $this->mailDispatcher->dispatch(
                type: PrivilegedDeliveryType::SHOP_DOCUMENT_RENEWAL_SUBMITTED,
                businessEventId: 'shop-document-renewal-submitted:'.$document->getKey(),
                recipientType: 'super_admin',
                recipientId: (int) $reviewer->getKey(),
                payload: [
                    'document_id' => (int) $document->getKey(),
                    'shop_owner_id' => (int) $owner->getKey(),
                    'business_name' => (string) $owner->business_name,
                    'logical_slot' => (string) $document->logical_slot,
                ],
                requiredCapability: SuperAdmin::CAP_REVIEW_REGISTRATIONS,
            );
        }
    }

    private function isAllowedRenewalType(string $documentType, string $logicalSlot, ShopDocument $document): bool
    {
        if ($logicalSlot === 'business_registration') {
            $predecessorType = $this->requirements->normalizeType((string) $document->document_type);

            return in_array($documentType, ['dti_registration', 'sec_registration'], true)
                && in_array($predecessorType, ['dti_registration', 'sec_registration', 'legacy_dti_sec_registration'], true);
        }

        if (str_starts_with($logicalSlot, 'supporting_document:')) {
            return $documentType === 'supporting_document'
                && in_array($this->requirements->normalizeType((string) $document->document_type), ['supporting_document', 'other_supporting_document'], true);
        }

        return $documentType === $this->requirements->normalizeType((string) $document->document_type);
    }

    private function predecessorSlot(ShopDocument $document): string
    {
        $logicalSlot = trim((string) $document->logical_slot);
        if ($logicalSlot !== '') {
            return $logicalSlot;
        }

        return $this->requirements->slotForType((string) $document->document_type) ?? '';
    }

    private function statusValue(mixed $status): string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }
}
