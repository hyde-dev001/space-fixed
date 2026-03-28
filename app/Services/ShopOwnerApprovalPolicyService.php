<?php

namespace App\Services;

use App\Models\ProcurementSettings;

class ShopOwnerApprovalPolicyService
{
    public function requiresOwnerApprovalForPriceChange(int $shopOwnerId, float $currentPrice, float $proposedPrice): bool
    {
        $settings = ProcurementSettings::getForShopOwner($shopOwnerId);
        $approvalPages = is_array($settings->settings_json['approval_pages'] ?? null)
            ? $settings->settings_json['approval_pages']
            : [];

        // Price approval is intentionally toggle-only (no amount threshold).
        if (!array_key_exists('price_approval', $approvalPages) || !is_array($approvalPages['price_approval'])) {
            return true;
        }

        return (bool) ($approvalPages['price_approval']['enabled'] ?? false);
    }

    public function requiresOwnerApprovalForPurchaseRequest(int $shopOwnerId, float $amount): bool
    {
        return $this->requiresOwnerApproval($shopOwnerId, 'purchase_request_approval', $amount);
    }

    public function requiresOwnerApprovalForRepairReject(int $shopOwnerId, float $amount): bool
    {
        return $this->requiresOwnerApproval($shopOwnerId, 'repair_reject_approval', $amount);
    }

    public function requiresOwnerApprovalForRefund(int $shopOwnerId, float $amount): bool
    {
        return $this->requiresOwnerApproval($shopOwnerId, 'refund_approval', $amount);
    }

    private function requiresOwnerApproval(int $shopOwnerId, string $key, float $amount): bool
    {
        $settings = ProcurementSettings::getForShopOwner($shopOwnerId);
        $approvalPages = is_array($settings->settings_json['approval_pages'] ?? null)
            ? $settings->settings_json['approval_pages']
            : [];

        if (!array_key_exists($key, $approvalPages) || !is_array($approvalPages[$key])) {
            return true;
        }

        $record = $approvalPages[$key];
        $enabled = (bool) ($record['enabled'] ?? false);

        if (!$enabled) {
            return false;
        }

        $limit = $record['limit'] ?? null;

        if ($limit === null || $limit === '') {
            return true;
        }

        return $amount >= (float) $limit;
    }
}