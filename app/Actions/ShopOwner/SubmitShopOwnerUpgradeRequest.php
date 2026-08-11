<?php

namespace App\Actions\ShopOwner;

use App\Enums\NotificationType;
use App\Models\Notification as AdminNotification;
use App\Models\ShopOwner;
use App\Models\ShopOwnerUpgradeRequest;
use App\Models\SuperAdmin;
use App\Notifications\ShopOwnerUpgradeRequested;
use App\Services\ShopOwnerDocumentRequirementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SubmitShopOwnerUpgradeRequest
{
    public function __construct(
        private readonly ShopOwnerDocumentRequirementService $documentRequirements,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(ShopOwner $owner, array $data): ShopOwnerUpgradeRequest
    {
        $createdPaths = [];
        $requestKey = (string) Str::uuid();

        try {
            $upgradeRequest = DB::transaction(function () use ($owner, $data, $requestKey, &$createdPaths): ShopOwnerUpgradeRequest {
                $lockedOwner = ShopOwner::query()->lockForUpdate()->findOrFail($owner->getKey());
                $this->assertApprovedOwner($lockedOwner);

                $pendingExists = ShopOwnerUpgradeRequest::query()
                    ->where('shop_owner_id', $lockedOwner->id)
                    ->where('status', ShopOwnerUpgradeRequest::STATUS_PENDING)
                    ->lockForUpdate()
                    ->exists();

                if ($pendingExists) {
                    throw ValidationException::withMessages([
                        'upgrade' => 'A business upgrade request is already pending for this shop.',
                    ]);
                }

                $currentRegistrationType = $this->ownerValue($lockedOwner, 'registration_type');
                $currentBusinessType = $this->normalizeBusinessType($this->ownerValue($lockedOwner, 'business_type'));
                $requestedRegistrationType = strtolower(trim((string) ($data['requested_registration_type'] ?? '')));
                $requestedBusinessType = $this->normalizeBusinessType((string) ($data['requested_business_type'] ?? ''));

                $this->assertTransition(
                    currentRegistrationType: $currentRegistrationType,
                    currentBusinessType: $currentBusinessType,
                    requestedRegistrationType: $requestedRegistrationType,
                    requestedBusinessType: $requestedBusinessType,
                );

                $upgradeRequest = ShopOwnerUpgradeRequest::create([
                    'shop_owner_id' => $lockedOwner->id,
                    'current_registration_type' => $currentRegistrationType,
                    'current_business_type' => $currentBusinessType,
                    'requested_registration_type' => $requestedRegistrationType,
                    'requested_business_type' => $requestedBusinessType,
                    'status' => ShopOwnerUpgradeRequest::STATUS_PENDING,
                    'required_document_set' => $this->documentRequirements->requirementSnapshot(),
                ]);

                $this->snapshotDocuments(
                    owner: $lockedOwner,
                    upgradeRequest: $upgradeRequest,
                    data: $data,
                    requestKey: $requestKey,
                    createdPaths: $createdPaths,
                );

                activity()
                    ->causedBy($lockedOwner)
                    ->performedOn($upgradeRequest)
                    ->withProperties([
                        'actor_type' => 'shop_owner',
                        'actor_guard' => 'shop_owner',
                        'shop_owner_id' => (int) $lockedOwner->id,
                        'upgrade_request_id' => (int) $upgradeRequest->id,
                        'current_registration_type' => $currentRegistrationType,
                        'current_business_type' => $currentBusinessType,
                        'requested_registration_type' => $requestedRegistrationType,
                        'requested_business_type' => $requestedBusinessType,
                        'correlation_id' => $requestKey,
                    ])
                    ->log('shop_owner_upgrade_requested');

                return $upgradeRequest->load('documents');
            });

            DB::afterCommit(function () use ($upgradeRequest): void {
                $requestSnapshot = $upgradeRequest->fresh('shopOwner');
                $recipients = SuperAdmin::query()->active()->get();

                try {
                    if ($requestSnapshot) {
                        $businessName = (string) ($requestSnapshot->shopOwner?->business_name ?? 'Shop owner');

                        AdminNotification::notifyAllSuperAdmins(
                            NotificationType::BUSINESS_UPGRADE_REQUEST_PENDING,
                            'Business upgrade request',
                            "{$businessName} submitted a business upgrade request for review.",
                            '/admin/business-upgrade-requests?status=pending',
                            [
                                'upgrade_request_id' => $upgradeRequest->id,
                                'shop_owner_id' => $upgradeRequest->shop_owner_id,
                                'requested_registration_type' => $upgradeRequest->requested_registration_type,
                                'requested_business_type' => $upgradeRequest->requested_business_type,
                            ],
                        );
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }

                try {
                    if ($recipients->isNotEmpty() && $requestSnapshot) {
                        Notification::send($recipients, new ShopOwnerUpgradeRequested($requestSnapshot));
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

            return $upgradeRequest;
        } catch (Throwable $exception) {
            foreach ($createdPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
    }

    private function assertApprovedOwner(ShopOwner $owner): void
    {
        if ($this->ownerValue($owner, 'status') !== 'approved') {
            throw ValidationException::withMessages([
                'upgrade' => 'Only an approved shop owner can submit a business upgrade request.',
            ]);
        }
    }

    public function assertTransition(
        string $currentRegistrationType,
        string $currentBusinessType,
        string $requestedRegistrationType,
        string $requestedBusinessType,
    ): void {
        if (! in_array($currentRegistrationType, ['individual', 'company'], true)
            || ! in_array($currentBusinessType, ['retail', 'repair', 'both'], true)
            || ! in_array($requestedRegistrationType, ['individual', 'company'], true)
            || ! in_array($requestedBusinessType, ['retail', 'repair', 'both'], true)) {
            throw ValidationException::withMessages([
                'upgrade' => 'The requested account or business state is not supported.',
            ]);
        }

        $registrationAllowed = $requestedRegistrationType === $currentRegistrationType
            || ($currentRegistrationType === 'individual' && $requestedRegistrationType === 'company');
        $businessAllowed = match ($currentBusinessType) {
            'retail' => in_array($requestedBusinessType, ['retail', 'both'], true),
            'repair' => in_array($requestedBusinessType, ['repair', 'both'], true),
            'both' => $requestedBusinessType === 'both',
            default => false,
        };

        if (! $registrationAllowed || ! $businessAllowed) {
            throw ValidationException::withMessages([
                'upgrade' => 'The requested account or business transition is not allowed.',
            ]);
        }

        if ($currentRegistrationType === $requestedRegistrationType
            && $currentBusinessType === $requestedBusinessType) {
            throw ValidationException::withMessages([
                'upgrade' => 'Choose a real account or business capability upgrade.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $createdPaths
     */
    private function snapshotDocuments(
        ShopOwner $owner,
        ShopOwnerUpgradeRequest $upgradeRequest,
        array $data,
        string $requestKey,
        array &$createdPaths,
    ): void {
        $uploads = is_array($data['documents'] ?? null) ? $data['documents'] : [];
        $reuseIds = is_array($data['reuse_document_ids'] ?? null) ? $data['reuse_document_ids'] : [];
        $requiredTypes = $this->documentRequirements->requiredTypes();
        $unknownUploadTypes = array_diff(array_keys($uploads), $requiredTypes);

        if ($unknownUploadTypes !== []) {
            throw ValidationException::withMessages(['documents' => 'One or more document types are not supported.']);
        }

        foreach ($requiredTypes as $documentType) {
            $upload = $uploads[$documentType] ?? null;
            $reuseId = $reuseIds[$documentType] ?? null;

            if (! $upload && ! $reuseId) {
                throw ValidationException::withMessages([
                    "documents.{$documentType}" => 'This business document is required.',
                ]);
            }

            if ($upload && $reuseId) {
                throw ValidationException::withMessages([
                    "documents.{$documentType}" => 'Choose either a new upload or an approved existing document.',
                ]);
            }

            if ($upload instanceof UploadedFile) {
                $this->storeUploadedDocument(
                    $upgradeRequest,
                    $documentType,
                    $upload,
                    $requestKey,
                    $createdPaths,
                );

                continue;
            }

            if (! is_numeric($reuseId)) {
                throw ValidationException::withMessages([
                    "reuse_document_ids.{$documentType}" => 'The selected document is invalid.',
                ]);
            }

            $this->copyApprovedDocument(
                owner: $owner,
                upgradeRequest: $upgradeRequest,
                documentType: $documentType,
                documentId: (int) $reuseId,
                requestKey: $requestKey,
                createdPaths: $createdPaths,
            );
        }
    }

    /**
     * @param  array<int, string>  $createdPaths
     */
    private function storeUploadedDocument(
        ShopOwnerUpgradeRequest $upgradeRequest,
        string $documentType,
        UploadedFile $upload,
        string $requestKey,
        array &$createdPaths,
    ): void {
        if (! $upload->isValid()) {
            throw ValidationException::withMessages(["documents.{$documentType}" => 'The uploaded document is invalid.']);
        }

        $bytes = file_get_contents($upload->getRealPath());
        if ($bytes === false) {
            throw ValidationException::withMessages(["documents.{$documentType}" => 'The uploaded document could not be read.']);
        }

        $extension = strtolower((string) $upload->getClientOriginalExtension());
        $path = "shop-owner-upgrade-evidence/{$requestKey}/{$documentType}-".Str::uuid().".{$extension}";
        $this->putPrivateEvidence($path, $bytes, $createdPaths, $documentType);

        $upgradeRequest->documents()->create([
            'document_type' => $documentType,
            'disk' => 'local',
            'path' => $path,
            'checksum_sha256' => hash('sha256', $bytes),
            'mime_type' => (string) ($upload->getMimeType() ?: 'application/octet-stream'),
            'size' => strlen($bytes),
            'source_status' => 'uploaded',
        ]);
    }

    /**
     * @param  array<int, string>  $createdPaths
     */
    private function copyApprovedDocument(
        ShopOwner $owner,
        ShopOwnerUpgradeRequest $upgradeRequest,
        string $documentType,
        int $documentId,
        string $requestKey,
        array &$createdPaths,
    ): void {
        $source = $owner->documents()->whereKey($documentId)->where('status', 'approved')->first();
        if (! $source || $this->documentRequirements->normalizeType((string) $source->document_type) !== $documentType) {
            throw ValidationException::withMessages([
                "reuse_document_ids.{$documentType}" => 'The selected approved document is not valid for this requirement.',
            ]);
        }

        $sourcePath = (string) $source->file_path;
        $sourceDisk = trim((string) $source->disk);
        if (! in_array($sourceDisk, ['local', 'public'], true)) {
            throw ValidationException::withMessages([
                "reuse_document_ids.{$documentType}" => 'The selected approved document uses an unsupported storage disk.',
            ]);
        }

        $sourceStorage = Storage::disk($sourceDisk);
        if ($sourcePath === '' || ! $sourceStorage->exists($sourcePath)) {
            throw ValidationException::withMessages([
                "reuse_document_ids.{$documentType}" => 'The selected approved document is no longer available.',
            ]);
        }

        $bytes = $sourceStorage->get($sourcePath);
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) ?: 'bin';
        $path = "shop-owner-upgrade-evidence/{$requestKey}/{$documentType}-".Str::uuid().".{$extension}";
        $this->putPrivateEvidence($path, $bytes, $createdPaths, $documentType);

        $upgradeRequest->documents()->create([
            'source_shop_document_id' => $source->id,
            'document_type' => $documentType,
            'disk' => 'local',
            'path' => $path,
            'checksum_sha256' => hash('sha256', $bytes),
            'mime_type' => (string) ($sourceStorage->mimeType($sourcePath) ?: 'application/octet-stream'),
            'size' => strlen($bytes),
            'source_status' => (string) $source->status,
        ]);
    }

    /**
     * @param  array<int, string>  $createdPaths
     */
    private function putPrivateEvidence(string $path, string $bytes, array &$createdPaths, string $documentType): void
    {
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw ValidationException::withMessages([
                "documents.{$documentType}" => 'The document could not be stored securely. Please try again.',
            ]);
        }

        $createdPaths[] = $path;
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
