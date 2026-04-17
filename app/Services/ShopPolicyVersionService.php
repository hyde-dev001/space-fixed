<?php

namespace App\Services;

use App\Models\ShopOwner;
use App\Models\ShopPolicyVersion;

class ShopPolicyVersionService
{
    public function saveDraft(int $shopOwnerId, array $sections): ShopPolicyVersion
    {
        $latest = ShopPolicyVersion::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->latest('version_number')
            ->first();

        $nextVersion = (int) ($latest?->version_number ?? 0) + 1;
        $rawBusinessType = (string) (ShopOwner::query()->whereKey($shopOwnerId)->value('business_type') ?? '');
        $businessTypeScope = $this->normalizeBusinessTypeScope($rawBusinessType);

        return ShopPolicyVersion::query()->create([
            'shop_owner_id' => $shopOwnerId,
            'version_number' => $nextVersion,
            'status' => 'draft',
            'business_type_scope' => $businessTypeScope,
            'registration_clause_mode' => 'individual_business_clause',
            'policy_sections_json' => $sections,
            'content_hash' => hash('sha256', (string) json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
    }

    public function publishLatestDraft(int $shopOwnerId, ?int $publishedBy = null): ShopPolicyVersion
    {
        $draft = ShopPolicyVersion::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'draft')
            ->latest('version_number')
            ->firstOrFail();

        $draft->update([
            'status' => 'published',
            'published_at' => now(),
            'published_by' => $publishedBy,
        ]);

        return $draft->fresh();
    }

    private function normalizeBusinessTypeScope(string $businessType): string
    {
        $normalized = strtolower(trim($businessType));
        if ($normalized === '') {
            return '';
        }

        if (str_contains($normalized, 'both')) {
            return 'both';
        }

        $hasRetailSignal = str_contains($normalized, 'retail')
            || str_contains($normalized, 'shoe')
            || str_contains($normalized, 'product');
        $hasRepairSignal = str_contains($normalized, 'repair')
            || str_contains($normalized, 'service');

        if ($hasRetailSignal && $hasRepairSignal) {
            return 'both';
        }

        if ($hasRetailSignal) {
            return 'retail';
        }

        if ($hasRepairSignal) {
            return 'repair';
        }

        return substr($normalized, 0, 20);
    }
}
