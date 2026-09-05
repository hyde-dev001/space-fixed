<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PrivilegedDeliveryType;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ShopOwnerRegistrationDecisionService
{
    public function __construct(
        private readonly ShopOwnerDocumentRequirementService $documentRequirements,
        private readonly ShopDocumentLifecycleService $documentLifecycle,
        private readonly ShopModuleProvisioningService $shopModuleProvisioning,
        private readonly PrivilegedAudit $privilegedAudit,
        private readonly PrivilegedMailDispatcher $privilegedMailDispatcher,
    ) {}

    /**
     * @return array{applied: bool, shop_owner: ShopOwner}
     */
    public function approve(Request $request, SuperAdmin $actor, int $shopOwnerId): array
    {
        $outcome = DB::transaction(function () use ($request, $actor, $shopOwnerId): array {
            $shopOwner = ShopOwner::query()->lockForUpdate()->findOrFail($shopOwnerId);
            $status = $this->statusValue($shopOwner);

            if ($status === 'approved') {
                return [
                    'applied' => false,
                    'shop_owner' => $shopOwner->fresh(),
                    'setup_token' => null,
                ];
            }

            if ($status !== 'pending') {
                throw new ConflictHttpException('Only pending registrations can be approved.');
            }

            $documents = $shopOwner->documents()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $pendingDocuments = $documents
                ->filter(fn ($document): bool => (string) $document->status === 'pending' && ! (bool) $document->is_current)
                ->values();
            $submittedDocuments = $request->input('documents');

            if (! is_array($submittedDocuments) || $submittedDocuments === []) {
                throw ValidationException::withMessages([
                    'documents' => ['Reviewer-verified document metadata is required before approval.'],
                ]);
            }

            $submittedById = collect($submittedDocuments)->filter(
                static fn ($document): bool => is_array($document),
            )->keyBy(
                static fn (array $document): int => (int) ($document['id'] ?? 0),
            );
            $errors = [];
            $reviewMetadata = [];

            if ($submittedById->count() !== $pendingDocuments->count()) {
                $errors['documents'][] = 'Submit reviewer metadata for every pending document candidate.';
            }

            foreach ($pendingDocuments as $document) {
                $slot = $this->logicalSlotForDocument($document);
                $payload = $submittedById->get((int) $document->getKey());

                if ($slot === null) {
                    $errors['documents'][] = 'Legacy or unclassified documents cannot be approved as new lifecycle records.';
                    continue;
                }

                if (! is_array($payload)) {
                    $errors[$slot][] = 'Reviewer metadata is required for this document.';
                    continue;
                }

                if ((string) $payload['logical_slot'] !== $slot) {
                    $errors[$slot][] = 'The reviewer logical slot does not match the submitted document.';
                }
                if ((int) $payload['version_number'] !== (int) $document->version_number) {
                    $errors[$slot][] = 'The reviewer version does not match the submitted document.';
                }
                $submittedType = $this->documentRequirements->normalizeType((string) $payload['document_type']);
                $storedType = $this->documentRequirements->normalizeType((string) $document->document_type);
                $businessRegistrationCorrection = $slot === 'business_registration'
                    && in_array($submittedType, ['dti_registration', 'sec_registration'], true)
                    && in_array($storedType, ['dti_registration', 'sec_registration'], true);
                if ($submittedType !== $storedType && ! $businessRegistrationCorrection) {
                    $errors[$slot][] = 'The reviewer document type does not match the submitted document.';
                }
                if (! filter_var($payload['viewed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $errors[$slot][] = 'Every private document must be viewed before approval.';
                }
                if (! $this->documentRequirements->hasPrivateStoredFile($document)) {
                    $errors[$slot][] = 'The submitted document must be present on the private disk before approval.';
                }

                $reviewMetadata[(int) $document->getKey()] = [
                    'document_type' => $submittedType,
                    'logical_slot' => $slot,
                    'issued_on' => $payload['issued_on'] ?? null,
                    'expiration_mode' => (string) $payload['expiration_mode'],
                    'expires_on' => $payload['expires_on'] ?? null,
                ];
            }

            foreach ($submittedById as $documentId => $payload) {
                if (! $pendingDocuments->contains(fn ($document): bool => (int) $document->getKey() === (int) $documentId)) {
                    $errors['documents'][] = 'Only pending document candidates for this registration may be approved.';
                }
            }

            foreach ($this->documentRequirements->validateSubmission(array_values($reviewMetadata)) as $slot => $messages) {
                $errors[$slot] = array_merge($errors[$slot] ?? [], $messages);
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $setupToken = Str::random(64);
            DB::table('password_reset_tokens')
                ->where('email', $shopOwner->email)
                ->lockForUpdate()
                ->first();

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $shopOwner->email],
                [
                    'email' => $shopOwner->email,
                    'token' => hash('sha256', $setupToken),
                    'created_at' => now(),
                ],
            );

            $shopOwner->forceFill([
                'status' => 'approved',
                'rejection_reason' => null,
            ])->save();

            $approvedDocuments = [];
            foreach ($pendingDocuments as $document) {
                $approvedDocuments[] = $this->documentLifecycle->approvePendingVersion(
                    $document,
                    (int) $actor->getKey(),
                    $reviewMetadata[(int) $document->getKey()],
                );
            }

            $eligibleModuleKeys = $this->shopModuleProvisioning->eligibleKeysFor($shopOwner);
            $this->shopModuleProvisioning->initializeMissing($shopOwner, $eligibleModuleKeys);

            $this->privilegedAudit->shopRegistrationApproved(
                $request,
                $actor,
                $shopOwner,
                array_map(static fn ($document): int => (int) $document->getKey(), $approvedDocuments),
                $eligibleModuleKeys,
            );

            $this->privilegedMailDispatcher->dispatch(
                type: PrivilegedDeliveryType::SHOP_REGISTRATION_APPROVED,
                businessEventId: 'shop-registration-approved:'.$shopOwner->getKey(),
                recipientType: 'shop_owner',
                recipientId: (int) $shopOwner->getKey(),
                payload: [
                    'setup_token' => $setupToken,
                ],
                correlationId: $this->privilegedAudit->correlationId($request),
            );

            return [
                'applied' => true,
                'shop_owner' => $shopOwner->fresh(),
                'setup_token' => $setupToken,
            ];
        });

        unset($outcome['setup_token']);

        return $outcome;
    }

    /**
     * @return array{applied: bool, shop_owner: ShopOwner}
     */
    public function reject(Request $request, SuperAdmin $actor, int $shopOwnerId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => ['A rejection reason is required.'],
            ]);
        }

        $outcome = DB::transaction(function () use ($request, $actor, $shopOwnerId, $reason): array {
            $shopOwner = ShopOwner::query()->lockForUpdate()->findOrFail($shopOwnerId);
            $status = $this->statusValue($shopOwner);

            if ($status === 'rejected') {
                if ((string) $shopOwner->rejection_reason !== $reason) {
                    throw new ConflictHttpException('This registration was already rejected with a different reason.');
                }

                return [
                    'applied' => false,
                    'shop_owner' => $shopOwner->fresh(),
                ];
            }

            if ($status !== 'pending') {
                throw new ConflictHttpException('Only pending registrations can be rejected.');
            }

            $documents = $shopOwner->documents()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $pendingDocuments = $documents
                ->filter(fn ($document): bool => (string) $document->status === 'pending' && ! (bool) $document->is_current)
                ->values();

            $shopOwner->forceFill([
                'status' => 'rejected',
                'rejection_reason' => $reason,
            ])->save();

            foreach ($pendingDocuments as $document) {
                $this->documentLifecycle->rejectPendingVersion(
                    $document,
                    (int) $actor->getKey(),
                    $reason,
                );
            }

            $this->privilegedAudit->shopRegistrationRejected(
                $request,
                $actor,
                $shopOwner,
                $reason,
                array_map(static fn ($document): int => (int) $document->getKey(), $pendingDocuments->all()),
            );

            $this->privilegedMailDispatcher->dispatch(
                type: PrivilegedDeliveryType::SHOP_REGISTRATION_REJECTED,
                businessEventId: 'shop-registration-rejected:'.$shopOwner->getKey(),
                recipientType: 'shop_owner',
                recipientId: (int) $shopOwner->getKey(),
                payload: [
                    'rejection_reason' => $reason,
                ],
                correlationId: $this->privilegedAudit->correlationId($request),
            );

            return [
                'applied' => true,
                'shop_owner' => $shopOwner->fresh(),
            ];
        });

        return $outcome;
    }

    private function statusValue(ShopOwner $shopOwner): string
    {
        $status = $shopOwner->status;

        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }

    private function logicalSlotForDocument(object $document): ?string
    {
        $logicalSlot = trim((string) ($document->logical_slot ?? ''));

        if ($logicalSlot !== '' && $this->documentRequirements->slotForType($logicalSlot) === $logicalSlot) {
            return $logicalSlot;
        }

        return match ($this->documentRequirements->normalizeType((string) ($document->document_type ?? ''))) {
            'dti_registration', 'sec_registration' => 'business_registration',
            'mayors_permit', 'bir_certificate', 'valid_id' => $this->documentRequirements->normalizeType((string) $document->document_type),
            default => null,
        };
    }

}
