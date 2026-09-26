<?php

namespace App\Services;

use App\Models\Logistics\RiderProfile;
use App\Models\ShopOwner;
use App\Models\User;

class CodEligibilityService
{
    public function __construct(
        private readonly ShopModuleAccessService $shopModuleAccess,
    ) {}

    /**
     * Return the shop-level COD configuration before an order amount is known.
     *
     * @return array{eligible: bool, reason: string|null, threshold: float, eligible_amount: float|null, message: string|null}
     */
    public function baseAvailability(ShopOwner $shopOwner): array
    {
        $thresholdCents = $this->thresholdCents($shopOwner);
        $threshold = $thresholdCents / 100;
        $businessType = strtolower(trim((string) $shopOwner->business_type));
        $isRetailCapable = $businessType === 'retail'
            || $businessType === 'both'
            || str_contains($businessType, 'retail');

        if (! $isRetailCapable) {
            return $this->result(false, 'retail_not_supported', $threshold, null, 'Cash on Delivery is available for retail orders only.');
        }

        if (! (bool) ($shopOwner->cod_enabled ?? false)) {
            return $this->result(false, 'cod_disabled', $threshold, null, 'This shop has disabled Cash on Delivery.');
        }

        if ($thresholdCents <= 0) {
            return $this->result(false, 'cod_not_configured', $threshold, null, 'Cash on Delivery is not configured for this shop.');
        }

        $enablement = $this->enablementStatus($shopOwner);
        if (! $enablement['ready']) {
            return $this->result(
                false,
                $enablement['reason'],
                $threshold,
                null,
                $enablement['message'],
            );
        }

        return $this->result(true, null, $threshold, null, null);
    }

    /**
     * COD is the only customer-facing checkout path that requires both email
     * and identity verification.
     *
     * @return array{eligible: bool, reason: string|null, message: string|null}
     */
    public function customerEligibility(User $customer): array
    {
        if (! $customer->isCustomerAccount()) {
            return [
                'eligible' => true,
                'reason' => null,
                'message' => null,
            ];
        }

        if (! $customer->hasVerifiedEmail()) {
            return [
                'eligible' => false,
                'reason' => 'customer_email_verification_required',
                'message' => 'Verify your email address before using Cash on Delivery.',
            ];
        }

        if (! $customer->hasApprovedIdentity()) {
            return [
                'eligible' => false,
                'reason' => 'customer_identity_verification_required',
                'message' => 'Complete identity verification before using Cash on Delivery.',
            ];
        }

        return [
            'eligible' => true,
            'reason' => null,
            'message' => null,
        ];
    }

    /**
     * Return the prerequisites that must be met before a shop may enable COD.
     *
     * @return array{ready: bool, reason: string|null, message: string|null}
     */
    public function enablementStatus(ShopOwner $shopOwner): array
    {
        if (! $this->shopModuleAccess->canAccess($shopOwner, 'logistics')) {
            return [
                'ready' => false,
                'reason' => 'logistics_module_unavailable',
                'message' => 'Enable the Shop-owned Logistics module before enabling Cash on Delivery.',
            ];
        }

        if (! $this->hasActiveDispatcher($shopOwner) || ! $this->hasActiveRider($shopOwner)) {
            return [
                'ready' => false,
                'reason' => 'logistics_staff_unavailable',
                'message' => 'Add at least one active Logistics Dispatcher and one active rider before enabling Cash on Delivery.',
            ];
        }

        return [
            'ready' => true,
            'reason' => null,
            'message' => null,
        ];
    }

    /**
     * Validate COD for a retail checkout using the server-calculated merchandise amount.
     * Shipping is intentionally not part of this comparison.
     *
     * @return array{eligible: bool, reason: string|null, threshold: float, eligible_amount: float, message: string|null}
     */
    public function evaluate(
        ShopOwner $shopOwner,
        float $merchandiseAmount,
        ?string $deliveryMethod = 'shop_owned',
        string $orderType = 'retail',
        ?User $customer = null,
    ): array {
        $amountCents = $this->toCents($merchandiseAmount);
        $amount = $amountCents / 100;
        $thresholdCents = $this->thresholdCents($shopOwner);
        $threshold = $thresholdCents / 100;

        if ($orderType !== 'retail') {
            return $this->result(false, 'order_type_not_supported', $threshold, $amount, 'Cash on Delivery is available for retail orders only.');
        }

        $base = $this->baseAvailability($shopOwner);
        if (! $base['eligible']) {
            return $this->result(false, $base['reason'], $threshold, $amount, $base['message']);
        }

        if ($customer instanceof User) {
            $customerEligibility = $this->customerEligibility($customer);
            if (! $customerEligibility['eligible']) {
                return $this->result(
                    false,
                    $customerEligibility['reason'],
                    $threshold,
                    $amount,
                    $customerEligibility['message'],
                );
            }
        }

        if (strtolower(trim((string) $deliveryMethod)) !== 'shop_owned') {
            return $this->result(false, 'cod_delivery_method_not_supported', $threshold, $amount, 'Cash on Delivery is available only with Shop-owned Logistics.');
        }

        if ($amountCents > $thresholdCents) {
            return $this->result(
                false,
                'cod_exceeds_threshold',
                $threshold,
                $amount,
                sprintf('Cash on Delivery is available for merchandise totals up to PHP %s.', number_format($threshold, 2)),
            );
        }

        return $this->result(true, null, $threshold, $amount, null);
    }

    private function thresholdCents(ShopOwner $shopOwner): int
    {
        return $this->toCents((float) ($shopOwner->cod_order_threshold ?? 5000));
    }

    private function toCents(float $amount): int
    {
        return max(0, (int) round($amount * 100));
    }

    private function hasActiveDispatcher(ShopOwner $shopOwner): bool
    {
        return User::query()
            ->where('shop_owner_id', $shopOwner->getKey())
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->where('role', 'LOGISTICS_DISPATCHER')
                    ->orWhereHas('roles', fn ($roles) => $roles->where('name', 'Logistics Dispatcher'))
                    ->orWhereHas('permissions', fn ($permissions) => $permissions->where('name', 'assign-logistics-deliveries'));
            })
            ->exists();
    }

    private function hasActiveRider(ShopOwner $shopOwner): bool
    {
        return RiderProfile::query()
            ->where('shop_owner_id', $shopOwner->getKey())
            ->where('active', true)
            ->exists();
    }

    /**
     * @return array{eligible: bool, reason: string|null, threshold: float, eligible_amount: float|null, message: string|null}
     */
    private function result(bool $eligible, ?string $reason, float $threshold, ?float $amount, ?string $message): array
    {
        return [
            'eligible' => $eligible,
            'reason' => $reason,
            'threshold' => $threshold,
            'eligible_amount' => $amount,
            'message' => $message,
        ];
    }
}
