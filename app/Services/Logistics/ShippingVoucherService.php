<?php

namespace App\Services\Logistics;

use App\Models\PromoCampaign;
use App\Models\ShopOwner;
use App\Models\UserAddress;
use App\Services\ShopModuleAccessService;

final class ShippingVoucherService
{
    public function __construct(
        private readonly ShopModuleAccessService $shopModuleAccess,
        private readonly DeliveryScheduleService $deliverySchedule,
    ) {}

    /**
     * @return array{discount: float, shipping_fee: float, error: ?string, coverage: ?array<string, mixed>}
     */
    public function apply(
        ?PromoCampaign $campaign,
        ShopOwner $shopOwner,
        float $rawShippingFee,
        float $eligibleSubtotal,
        ?UserAddress $address = null,
        ?float $latitude = null,
        ?float $longitude = null,
    ): array {
        $rawShippingFee = round(max(0.0, $rawShippingFee), 2);
        $baseResult = [
            'discount' => 0.0,
            'shipping_fee' => $rawShippingFee,
            'error' => null,
            'coverage' => null,
        ];

        if ($campaign === null) {
            return $baseResult;
        }

        if ((string) ($campaign->kind ?? '') !== 'voucher'
            || (string) ($campaign->discount_target ?? 'items') !== 'shipping'
            || (string) ($campaign->scope ?? '') !== 'shop_wide') {
            return $this->withError($baseResult, 'Selected voucher is not a valid shipping voucher.');
        }

        if (! $this->shopModuleAccess->canAccess($shopOwner, 'logistics')) {
            return $this->withError($baseResult, 'Shipping vouchers require accessible Shop-owned Logistics.');
        }

        $eligibleSubtotal = round(max(0.0, $eligibleSubtotal), 2);
        $minimumSpend = round(max(0.0, (float) $campaign->min_spend), 2);
        if ($minimumSpend > 0.0 && $eligibleSubtotal < $minimumSpend) {
            return $this->withError($baseResult, sprintf(
                'Minimum spend of PHP %s is required for this shipping voucher (current eligible subtotal: PHP %s).',
                number_format($minimumSpend, 2, '.', ','),
                number_format($eligibleSubtotal, 2, '.', ','),
            ));
        }

        if ($rawShippingFee <= 0.0) {
            return $this->withError($baseResult, 'A shipping fee is required before this voucher can be applied.');
        }

        $coverage = $this->deliverySchedule->coverage(
            $shopOwner,
            $latitude ?? $address?->latitude,
            $longitude ?? $address?->longitude,
        );
        $baseResult['coverage'] = $coverage;

        if (! ($coverage['available'] ?? false)) {
            return $this->withError($baseResult, match ($coverage['reason'] ?? null) {
                'address_needs_pin' => 'Pin your delivery address to use this shipping voucher.',
                'shop_needs_pin' => 'This shop has not configured its Shop-owned Logistics location yet.',
                'outside_coverage' => 'This shipping voucher is only available within the shop\'s delivery coverage.',
                default => 'Shipping vouchers are unavailable for this delivery address.',
            });
        }

        $value = max(0.0, (float) $campaign->value);
        $discount = (string) $campaign->discount_mode === 'percentage'
            ? $rawShippingFee * min(100.0, $value) / 100
            : $value;
        $discount = round(min($rawShippingFee, max(0.0, $discount)), 2);

        return [
            'discount' => $discount,
            'shipping_fee' => round(max(0.0, $rawShippingFee - $discount), 2),
            'error' => null,
            'coverage' => $coverage,
        ];
    }

    /**
     * @param array{discount: float, shipping_fee: float, error: ?string, coverage: ?array<string, mixed>} $result
     * @return array{discount: float, shipping_fee: float, error: ?string, coverage: ?array<string, mixed>}
     */
    private function withError(array $result, string $message): array
    {
        $result['error'] = $message;

        return $result;
    }
}
