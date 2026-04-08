<?php

namespace App\Services;

use App\Models\PromoCampaign;
use Illuminate\Support\Collection;

class PromoPricingService
{
    /**
     * Filter campaigns applicable to a product.
     */
    public function campaignsForProduct(Collection $campaigns, int $productId): Collection
    {
        return $campaigns->filter(function (PromoCampaign $campaign) use ($productId) {
            if ($campaign->scope === 'shop_wide') {
                return true;
            }

            return $campaign->products->pluck('id')->contains($productId);
        })->values();
    }

    /**
     * Apply active sale campaigns first, then apply the best eligible claimed voucher.
     *
     * @param array<int, array{product_id:int, price:float|int, qty:int}> $lineItems
     * @return array{
     *   line_items: array<int, array<string, mixed>>,
     *   sale_adjusted_subtotal: float,
     *   voucher_discount: float,
     *   applied_voucher: ?PromoCampaign,
     *   final_subtotal: float
     * }
     */
    public function applySaleThenVoucher(array $lineItems, Collection $activeSales, Collection $claimedVouchers): array
    {
        $saleAdjustedSubtotal = 0.0;
        $normalizedLineItems = [];

        foreach ($lineItems as $lineItem) {
            $productId = (int) ($lineItem['product_id'] ?? 0);
            $qty = max(1, (int) ($lineItem['qty'] ?? 1));
            $price = max(0.0, (float) ($lineItem['price'] ?? 0));
            $base = round($price * $qty, 2);

            $applicableSale = $this->resolveBestSaleForProduct($activeSales, $productId, $base);
            $saleDiscount = $applicableSale ? $this->computeDiscountAmount($applicableSale, $base) : 0.0;
            $saleAdjusted = max(0.0, round($base - $saleDiscount, 2));

            $normalizedLineItems[] = [
                'product_id' => $productId,
                'qty' => $qty,
                'price' => $price,
                'base_total' => $base,
                'sale_discount' => $saleDiscount,
                'sale_adjusted_total' => $saleAdjusted,
                'sale_campaign_id' => $applicableSale?->id,
            ];

            $saleAdjustedSubtotal += $saleAdjusted;
        }

        $saleAdjustedSubtotal = round($saleAdjustedSubtotal, 2);

        $eligibleVouchers = $claimedVouchers
            ->filter(fn (PromoCampaign $campaign) => $this->isCampaignApplicableToLineItems($campaign, $normalizedLineItems))
            ->values();

        $appliedVoucher = $eligibleVouchers
            ->sortByDesc(fn (PromoCampaign $campaign) => $this->computeVoucherDiscountForLineItems($campaign, $normalizedLineItems, $saleAdjustedSubtotal))
            ->first();

        $voucherDiscount = $appliedVoucher
            ? $this->computeVoucherDiscountForLineItems($appliedVoucher, $normalizedLineItems, $saleAdjustedSubtotal)
            : 0.0;

        $finalSubtotal = max(0.0, round($saleAdjustedSubtotal - $voucherDiscount, 2));

        return [
            'line_items' => $normalizedLineItems,
            'sale_adjusted_subtotal' => $saleAdjustedSubtotal,
            'voucher_discount' => $voucherDiscount,
            'applied_voucher' => $appliedVoucher,
            'final_subtotal' => $finalSubtotal,
        ];
    }

    private function resolveBestSaleForProduct(Collection $activeSales, int $productId, float $base): ?PromoCampaign
    {
        return $activeSales
            ->filter(fn (PromoCampaign $campaign) => $this->isCampaignApplicableToProduct($campaign, $productId))
            ->sortByDesc(fn (PromoCampaign $campaign) => $this->computeDiscountAmount($campaign, $base))
            ->first();
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     */
    private function isCampaignApplicableToLineItems(PromoCampaign $campaign, array $lineItems): bool
    {
        if ($campaign->scope === 'shop_wide') {
            return true;
        }

        foreach ($lineItems as $lineItem) {
            if ($this->isCampaignApplicableToProduct($campaign, (int) ($lineItem['product_id'] ?? 0))) {
                return true;
            }
        }

        return false;
    }

    private function isCampaignApplicableToProduct(PromoCampaign $campaign, int $productId): bool
    {
        if ($campaign->scope === 'shop_wide') {
            return true;
        }

        return $campaign->products->pluck('id')->contains($productId);
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     */
    private function computeVoucherDiscountForLineItems(PromoCampaign $campaign, array $lineItems, float $saleAdjustedSubtotal): float
    {
        $eligibleSubtotal = $this->eligibleSubtotalForCampaign($campaign, $lineItems, $saleAdjustedSubtotal);

        if ($eligibleSubtotal <= 0) {
            return 0.0;
        }

        if ($eligibleSubtotal < max(0.0, (float) $campaign->min_spend)) {
            return 0.0;
        }

        return $this->computeDiscountAmount($campaign, $eligibleSubtotal);
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     */
    private function eligibleSubtotalForCampaign(PromoCampaign $campaign, array $lineItems, float $saleAdjustedSubtotal): float
    {
        if ($campaign->scope === 'shop_wide') {
            return round(max(0.0, $saleAdjustedSubtotal), 2);
        }

        $eligible = 0.0;
        foreach ($lineItems as $lineItem) {
            $productId = (int) ($lineItem['product_id'] ?? 0);
            if ($this->isCampaignApplicableToProduct($campaign, $productId)) {
                $eligible += max(0.0, (float) ($lineItem['sale_adjusted_total'] ?? 0));
            }
        }

        return round($eligible, 2);
    }

    private function computeDiscountAmount(PromoCampaign $campaign, float $amount): float
    {
        $amount = max(0.0, $amount);
        $rawValue = max(0.0, (float) $campaign->value);

        $discount = $campaign->discount_mode === 'percentage'
            ? ($amount * ($rawValue / 100))
            : $rawValue;

        return round(min($discount, $amount), 2);
    }
}
