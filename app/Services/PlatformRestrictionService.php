<?php

namespace App\Services;

use Illuminate\Auth\Access\AuthorizationException;

final class PlatformRestrictionService
{
    public function __construct(
        private readonly PlatformBalanceService $balance,
        private readonly PlatformFeeSettingsResolver $settings,
    ) {}

    public function isRestricted(int $shopId): bool
    {
        return (bool) $this->balance->summary($shopId)['is_restricted'];
    }

    public function assertMarketplaceAllowed(int $shopId): void
    {
        if ($this->isRestricted($shopId)) {
            throw new AuthorizationException('Marketplace selling is temporarily restricted until the Platform Balance is settled.');
        }

        $config = $this->settings->forShop($shopId);
        $requiredTermsVersion = trim((string) ($config['terms_version'] ?? ''));
        if ($requiredTermsVersion !== '') {
            $shop = \App\Models\ShopOwner::query()->findOrFail($shopId);
            if ((string) $shop->platform_fee_terms_version !== $requiredTermsVersion
                || $shop->platform_fee_terms_accepted_at === null) {
                throw new AuthorizationException('The current Platform Fee terms must be accepted before marketplace selling can continue.');
            }
        }
    }
}
