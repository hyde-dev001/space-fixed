<?php

namespace App\Services;

use App\Models\ShopDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;

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
     * Select exactly one current document for each required normalized type.
     *
     * The existing document replacement flow updates the newest row. Phase 2
     * intentionally keeps that behavior; immutable document versions belong to
     * the Phase 6 document boundary.
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
            if (! isset($requiredTypes[$type])) {
                continue;
            }

            if (! isset($latest[$type]) || $this->isNewerDocument($document, $latest[$type])) {
                $latest[$type] = $document;
            }
        }

        return $latest;
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
