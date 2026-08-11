<?php

namespace App\Services;

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
        $documentsByType = $documents
            ->sortByDesc('created_at')
            ->groupBy(fn ($document): string => $this->normalizeType((string) $document->document_type));

        $payload = [];
        foreach ($this->definitions() as $type => $meta) {
            $document = $documentsByType->get($type)?->first();
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
}
