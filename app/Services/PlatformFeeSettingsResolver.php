<?php

namespace App\Services;

use App\Models\PlatformFeeSetting;
use App\Models\ShopOwner;
use App\Models\ShopPlatformFeeSetting;

final class PlatformFeeSettingsResolver
{
    /** @return array<string, mixed> */
    public function forShop(ShopOwner|int $shop): array
    {
        $shopOwner = $shop instanceof ShopOwner
            ? $shop
            : ShopOwner::query()->findOrFail($shop);
        $shopType = strtolower(trim((string) ($shopOwner->registration_type ?? 'individual')));
        $shopType = $shopType === 'company' ? 'business' : 'individual';

        $settings = array_merge(
            (array) config('platform_fee.defaults', []),
            (array) config("platform_fee.shop_types.{$shopType}", []),
        );

        $platform = PlatformFeeSetting::query()
            ->where('scope', 'platform')
            ->whereNull('shop_type')
            ->first();
        $typeDefaults = PlatformFeeSetting::query()
            ->where('scope', 'shop_type')
            ->where('shop_type', $shopType)
            ->first();
        $shopOverride = ShopPlatformFeeSetting::query()
            ->where('shop_owner_id', $shopOwner->getKey())
            ->where('status', 'approved')
            ->first();

        foreach ([$platform, $typeDefaults, $shopOverride] as $record) {
            if (! $record) {
                continue;
            }

            foreach ([
                'platform_fee_rate',
                'platform_fee_vat_enabled',
                'platform_fee_vat_rate',
                'balance_limit',
                'warning_threshold_percentage',
                'critical_threshold_percentage',
                'enforcement_enabled',
                'effective_from',
                'terms_version',
                'terms_text',
            ] as $key) {
                if ($record->{$key} !== null) {
                    $settings[$key] = $record->{$key};
                }
            }
        }

        return [
            'platform_fee_rate' => (string) ($settings['platform_fee_rate'] ?? '0.000000'),
            'platform_fee_vat_enabled' => (bool) ($settings['platform_fee_vat_enabled'] ?? false),
            'platform_fee_vat_rate' => (string) ($settings['platform_fee_vat_rate'] ?? '0.000000'),
            'balance_limit' => (string) ($settings['balance_limit'] ?? '0.00'),
            'warning_threshold_percentage' => (string) ($settings['warning_threshold_percentage'] ?? '80.0000'),
            'critical_threshold_percentage' => (string) ($settings['critical_threshold_percentage'] ?? '90.0000'),
            'enforcement_enabled' => (bool) ($settings['enforcement_enabled'] ?? false),
            'shop_type' => $shopType,
            'effective_from' => $settings['effective_from'] ?? config('platform_fee.effective_from'),
            'terms_version' => (string) ($settings['terms_version'] ?? config('platform_fee.terms_version', '')),
            'terms_text' => (string) ($settings['terms_text'] ?? config('platform_fee.terms_text', '')),
        ];
    }

    /** @return array{window_days: int, version: string, weights: array<string, int>, tiers: array<int, array<string, mixed>>} */
    public function reliabilityFor(ShopOwner|string $shop): array
    {
        $shopType = $shop instanceof ShopOwner
            ? strtolower(trim((string) ($shop->registration_type ?? 'individual')))
            : strtolower(trim($shop));
        $shopType = $shopType === 'company' ? 'business' : ($shopType === 'business' ? 'business' : 'individual');
        $config = (array) config('platform_fee.reliability', []);

        $platform = PlatformFeeSetting::query()
            ->where('scope', 'platform')
            ->whereNull('shop_type')
            ->first();
        $typeDefaults = PlatformFeeSetting::query()
            ->where('scope', 'shop_type')
            ->where('shop_type', $shopType)
            ->first();

        foreach ([$platform, $typeDefaults] as $record) {
            if (! $record) {
                continue;
            }

            if ($record->reliability_window_days !== null) {
                $config['window_days'] = (int) $record->reliability_window_days;
            }
            if ($record->reliability_version !== null) {
                $config['version'] = (string) $record->reliability_version;
            }
            if ($record->reliability_weights !== null) {
                $config['weights'] = $record->reliability_weights;
            }
            if ($record->reliability_tiers !== null) {
                $config['tiers'] = $record->reliability_tiers;
            }
        }

        return [
            'window_days' => max(1, (int) ($config['window_days'] ?? 180)),
            'version' => (string) ($config['version'] ?? 'v1'),
            'weights' => (array) ($config['weights'] ?? []),
            'tiers' => array_values((array) ($config['tiers'] ?? [])),
        ];
    }
}
