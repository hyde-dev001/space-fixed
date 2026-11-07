<?php

namespace App\Services;

use App\Models\ShopDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ShopOwnerDocumentRequirementService
{
    /**
     * @return array<string, array{title: string, description: string}>
     */
    public function definitions(): array
    {
        return [
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
    }

    /**
     * @return array<int, string>
     */
    public function requiredTypes(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * The Phase 6 logical requirements are additive to the legacy concrete
     * type API still used by the upgrade workflow.
     *
     * @return array<string, array{title: string, description: string}>
     */
    public function logicalDefinitions(): array
    {
        return [
            'business_registration' => [
                'title' => 'Business Registration (DTI or SEC)',
                'description' => 'One current DTI or SEC registration certificate for the business.',
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
    }

    /**
     * Resolve a concrete current-input type to its stable logical slot.
     * Legacy aliases intentionally return null so they cannot be submitted as
     * new lifecycle records.
     */
    public function slotForType(string $type): ?string
    {
        $normalized = Str::of($type)->trim()->lower()->toString();

        if (in_array($normalized, ['dti_registration', 'sec_registration'], true)) {
            return 'business_registration';
        }

        if (array_key_exists($normalized, $this->logicalDefinitions())) {
            return $normalized;
        }

        if (Str::startsWith($normalized, 'supporting_document:')) {
            $identity = Str::after($normalized, 'supporting_document:');

            return Str::isUuid($identity)
                || preg_match('/^legacy:[1-9][0-9]*$/', $identity) === 1
                ? $normalized
                : null;
        }

        return null;
    }

    /**
     * Validate the fixed registration/renewal metadata contract without
     * touching persistence or changing ShopOwner state.
     *
     * @param array<int, array<string, mixed>> $documents
     * @return array<string, array<int, string>>
     */
    public function validateSubmission(array $documents): array
    {
        $errors = [];
        $seenSlots = [];
        $businessRegistrationCount = 0;

        foreach ($documents as $document) {
            if (! is_array($document)) {
                $errors['documents'][] = 'Each document entry must be an object.';
                continue;
            }

            $type = Str::of((string) ($document['document_type'] ?? ''))->trim()->lower()->toString();
            $declaredSlot = Str::of((string) ($document['logical_slot'] ?? ''))->trim()->lower()->toString();
            $slot = $this->slotForType($type);

            if ($type === 'supporting_document' && Str::startsWith($declaredSlot, 'supporting_document:')) {
                $slot = $this->slotForType($declaredSlot);
            }

            if ($slot === null) {
                $errors[$declaredSlot !== '' ? $declaredSlot : 'documents'][] =
                    'This document type is not accepted for new submissions.';
                continue;
            }

            if ($declaredSlot !== $slot) {
                $errors[$slot][] = 'The document logical slot does not match its concrete type.';
            }

            if (isset($seenSlots[$slot])) {
                $errors[$slot][] = 'Only one document may occupy this logical slot in a submission.';
            }
            $seenSlots[$slot] = true;

            if ($slot === 'business_registration') {
                $businessRegistrationCount++;
            }

            $expirationMode = Str::of((string) ($document['expiration_mode'] ?? ''))->trim()->lower()->toString();
            $expiresOn = $document['expires_on'] ?? null;

            if (! in_array($expirationMode, ['dated', 'none'], true)) {
                $errors[$slot][] = 'Expiration must be explicitly dated or marked as no expiration.';
            } elseif ($expirationMode === 'dated') {
                if (! is_string($expiresOn) || ! $this->isDate($expiresOn)) {
                    $errors[$slot][] = 'A valid expiration date is required for dated documents.';
                }
            } elseif ($expiresOn !== null && $expiresOn !== '') {
                $errors[$slot][] = 'A no-expiration document cannot include an expiration date.';
            }

            if ($slot === 'mayors_permit' && $expirationMode !== 'dated') {
                $errors[$slot][] = "Mayor's Permit must have a dated expiration.";
            }

            $issuedOn = $document['issued_on'] ?? null;
            if ($issuedOn !== null
                && $issuedOn !== ''
                && (! is_string($issuedOn) || ! $this->isDate($issuedOn))) {
                $errors[$slot][] = 'Issue date must be a valid calendar date.';
            }
            if ($expirationMode === 'dated'
                && is_string($issuedOn)
                && $issuedOn !== ''
                && is_string($expiresOn)
                && $this->isDate($issuedOn)
                && $this->isDate($expiresOn)
                && $issuedOn > $expiresOn) {
                $errors[$slot][] = 'Issue date cannot be after the expiration date.';
            }
        }

        foreach (array_keys($this->logicalDefinitions()) as $slot) {
            if (! isset($seenSlots[$slot])) {
                $errors[$slot][] = 'This document is required.';
            }
        }

        if ($businessRegistrationCount !== 1) {
            $errors['business_registration'][] = 'Submit exactly one DTI or SEC registration document.';
        }

        return $errors;
    }

    /**
     * Validate metadata for one existing logical slot without requiring the
     * other registration slots to be present in the same request.
     *
     * @param array<string, mixed> $document
     * @return array<string, array<int, string>>
     */
    public function validateDocumentMetadata(array $document): array
    {
        $slot = Str::of((string) ($document['logical_slot'] ?? ''))->trim()->lower()->toString();
        $errors = [];

        if ($this->slotForType($slot) !== $slot) {
            $errors[$slot !== '' ? $slot : 'logical_slot'][] = 'This logical slot is not accepted.';

            return $errors;
        }

        $expirationMode = Str::of((string) ($document['expiration_mode'] ?? ''))->trim()->lower()->toString();
        $expiresOn = $document['expires_on'] ?? null;

        if (! in_array($expirationMode, ['dated', 'none'], true)) {
            $errors[$slot][] = 'Expiration must be explicitly dated or marked as no expiration.';
        } elseif ($expirationMode === 'dated') {
            if (! is_string($expiresOn) || ! $this->isDate($expiresOn)) {
                $errors[$slot][] = 'A valid expiration date is required for dated documents.';
            }
        } elseif ($expiresOn !== null && $expiresOn !== '') {
            $errors[$slot][] = 'A no-expiration document cannot include an expiration date.';
        }

        if ($slot === 'mayors_permit' && $expirationMode !== 'dated') {
            $errors[$slot][] = "Mayor's Permit must have a dated expiration.";
        }

        $issuedOn = $document['issued_on'] ?? null;
        if ($issuedOn !== null
            && $issuedOn !== ''
            && (! is_string($issuedOn) || ! $this->isDate($issuedOn))) {
            $errors[$slot][] = 'Issue date must be a valid calendar date.';
        }
        if ($expirationMode === 'dated'
            && is_string($issuedOn)
            && $issuedOn !== ''
            && is_string($expiresOn)
            && $this->isDate($issuedOn)
            && $this->isDate($expiresOn)
            && $issuedOn > $expiresOn) {
            $errors[$slot][] = 'Issue date cannot be after the expiration date.';
        }

        return $errors;
    }

    /**
     * @return array<string, array{key: string, title: string, description: string}>
     */
    public function requirementSnapshot(): array
    {
        $snapshot = [];
        foreach ($this->definitions() as $key => $definition) {
            $snapshot[$key] = array_merge(['key' => $key], $definition);
        }

        return $snapshot;
    }

    /**
     * Preserve the existing settings payload shape while centralizing aliases.
     *
     * @param  Collection<int, object>  $documents
     * @return array<int, array<string, mixed>>
     */
    public function settingsPayload(Collection $documents): array
    {
        $documentsByType = collect($this->latestRequiredDocuments($documents));

        $payload = [];
        foreach ($this->definitions() as $type => $meta) {
            $document = $documentsByType->get($type);
            $filePath = $document?->file_path;
            $extension = $filePath ? strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) : '';

            $payload[] = [
                'key' => $type,
                'title' => $meta['title'],
                'description' => $meta['description'],
                'status' => $document?->status ?? 'missing',
                'is_uploaded' => (bool) $document,
                'is_image' => in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true),
                'file_url' => $document
                    ? route('shop-owner.documents.show', [
                        'shopOwner' => $document->shop_owner_id,
                        'document' => $document->id,
                    ])
                    : null,
            ];
        }

        return $payload;
    }

    /**
     * Select the newest legacy-compatible document for each required type.
     * Lifecycle-aware callers should select by logical slot and current marker.
     *
     * @param  Collection<int, object>  $documents
     * @return array<string, ShopDocument|object>
     */
    public function latestRequiredDocuments(Collection $documents): array
    {
        $requiredTypes = array_fill_keys($this->requiredTypes(), true);
        $latest = [];

        foreach ($documents as $document) {
            $type = $this->normalizeType((string) $document->document_type);
            if ($type === 'legacy_dti_sec_registration') {
                $type = 'dti_registration';
            }
            if (! isset($requiredTypes[$type])) {
                continue;
            }

            if (! isset($latest[$type]) || $this->isNewerDocument($document, $latest[$type])) {
                $latest[$type] = $document;
            }
        }

        return $latest;
    }

    public function isLegacyBusinessDocument(object $document): bool
    {
        $type = $this->normalizeType((string) ($document->document_type ?? ''));
        $slot = trim((string) ($document->logical_slot ?? ''));

        return $type === 'legacy_dti_sec_registration'
            || ($slot === '' && in_array($type, ['dti_registration', 'sec_registration'], true));
    }

    /**
     * @param  Collection<int, object>  $documents
     * @return array{latest: array<string, object>, missing: array<int, string>, invalid: array<string, string>}
     */
    public function evaluate(Collection $documents): array
    {
        $latest = $this->latestRequiredDocuments($documents);
        $missing = [];
        $invalid = [];

        foreach ($this->requiredTypes() as $type) {
            $document = $latest[$type] ?? null;
            if ($document === null) {
                $missing[] = $type;
                continue;
            }

            $reason = $this->invalidReason($document);
            if ($reason !== null) {
                $invalid[$type] = $reason;
            }
        }

        return [
            'latest' => $latest,
            'missing' => $missing,
            'invalid' => $invalid,
        ];
    }

    /**
     * @param  Collection<int, object>  $documents
     * @return array<int, string>
     */
    public function missingOrInvalidRequiredTypes(Collection $documents): array
    {
        $state = $this->evaluate($documents);

        return array_values(array_unique(array_merge(
            $state['missing'],
            array_keys($state['invalid']),
        )));
    }

    /**
     * Resubmission may carry forward a rejected current row, but never a row
     * whose stored file is absent or whose disk metadata is unsupported.
     */
    private function hasStoredFile(object $document): bool
    {
        $path = trim((string) ($document->file_path ?? ''));
        $disk = trim((string) ($document->disk ?? ''));

        if ($path === '' || $disk === '' || ! array_key_exists($disk, config('filesystems.disks', []))) {
            return false;
        }

        try {
            return Storage::disk($disk)->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    public function hasPrivateStoredFile(object $document): bool
    {
        return trim((string) ($document->disk ?? '')) === 'local'
            && $this->hasStoredFile($document);
    }

    public function normalizeType(string $type): string
    {
        $normalized = strtolower(trim($type));

        return [
            'dti_registration' => 'dti_registration',
            'dti registration' => 'dti_registration',
            'business registration (dti/sec)' => 'dti_registration',
            'sec_registration' => 'sec_registration',
            'sec registration' => 'sec_registration',
            'mayors_permit' => 'mayors_permit',
            "mayor's permit" => 'mayors_permit',
            "mayor's permit / business permit" => 'mayors_permit',
            'bir_certificate' => 'bir_certificate',
            'bir certificate' => 'bir_certificate',
            'bir certificate of registration (cor)' => 'bir_certificate',
            'valid_id' => 'valid_id',
            'valid id' => 'valid_id',
            'valid id of owner' => 'valid_id',
        ][$normalized] ?? str_replace('-', '_', $normalized);
    }

    private function isDate(string $value): bool
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return false;
        }

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function isNewerDocument(object $candidate, object $current): bool
    {
        $candidateCreatedAt = $candidate->created_at?->getTimestamp() ?? 0;
        $currentCreatedAt = $current->created_at?->getTimestamp() ?? 0;

        if ($candidateCreatedAt !== $currentCreatedAt) {
            return $candidateCreatedAt > $currentCreatedAt;
        }

        return (int) ($candidate->id ?? 0) > (int) ($current->id ?? 0);
    }

    private function invalidReason(object $document): ?string
    {
        $status = strtolower(trim((string) ($document->status ?? '')));
        if (! in_array($status, ['pending', 'approved'], true)) {
            return 'The latest document is not in an approvable state.';
        }

        if (trim((string) ($document->file_path ?? '')) === '') {
            return 'The latest document has no stored file path.';
        }

        $disk = trim((string) ($document->disk ?? ''));
        if ($disk === '' || ! array_key_exists($disk, config('filesystems.disks', []))) {
            return 'The latest document has no supported storage disk.';
        }

        if ($disk !== 'local') {
            return 'The latest document must be stored on the private disk before approval.';
        }

        if (! $this->hasPrivateStoredFile($document)) {
            return 'The latest document storage could not be verified.';
        }

        return null;
    }
}
