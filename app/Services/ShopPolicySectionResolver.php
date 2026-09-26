<?php

namespace App\Services;

class ShopPolicySectionResolver
{
    /**
     * Return only the policy sections that apply to a customer flow.
     *
     * Shared sections remain available in both flows. Channel-specific
     * content and its metadata are kept together so custom titles and clauses
     * cannot leak across retail and repair acceptance screens.
     */
    public function forFlow(array $sections, string $flow, string $businessTypeScope = ''): array
    {
        $normalizedFlow = strtolower(trim($flow));
        $normalizedScope = strtolower(trim($businessTypeScope));

        if (! in_array($normalizedFlow, ['retail', 'repair'], true)) {
            return $sections;
        }

        $resolved = [];

        foreach ($sections as $key => $value) {
            $key = (string) $key;
            $contentKey = str_starts_with($key, '__')
                ? $this->metadataSectionKey($key)
                : $key;

            if (str_starts_with($key, '__') && $contentKey === null) {
                continue;
            }

            if (! $this->isIncludedInFlow(
                (string) ($contentKey ?? $key),
                $normalizedFlow,
                $normalizedScope
            )) {
                continue;
            }

            $resolved[$key] = $value;
        }

        return $resolved;
    }

    private function metadataSectionKey(string $key): ?string
    {
        foreach ([
            '__section_title__',
            '__section_key__',
            '__section_custom_clauses__',
            '__section_deleted__',
        ] as $prefix) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            $sectionKey = substr($key, strlen($prefix));
            return $sectionKey !== '' ? $sectionKey : null;
        }

        return null;
    }

    private function isIncludedInFlow(string $key, string $flow, string $businessTypeScope): bool
    {
        if ($this->isRetailKey($key)) {
            return $flow === 'retail';
        }

        if ($this->isRepairKey($key)) {
            return $flow === 'repair';
        }

        if ($this->isLegacyCustomKey($key)) {
            if (str_contains($businessTypeScope, 'repair') && ! str_contains($businessTypeScope, 'both')) {
                return $flow === 'repair';
            }

            return $flow === 'retail';
        }

        return true;
    }

    private function isRetailKey(string $key): bool
    {
        return $key === 'retail_terms'
            || $key === 'refund_payment_terms_retail'
            || str_starts_with($key, 'custom_terms_retail_');
    }

    private function isRepairKey(string $key): bool
    {
        return $key === 'repair_service_terms'
            || $key === 'refund_payment_terms_repair'
            || str_starts_with($key, 'custom_terms_repair_');
    }

    private function isLegacyCustomKey(string $key): bool
    {
        return str_starts_with($key, 'custom_terms_')
            && ! str_starts_with($key, 'custom_terms_retail_')
            && ! str_starts_with($key, 'custom_terms_repair_');
    }
}
