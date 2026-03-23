<?php

namespace App\Services;

use App\Models\ProcurementSettings;

class ShopOwnerApprovalPolicyService
{
    public function requiresOwnerApprovalForPriceChange(int $shopOwnerId, float $amount): bool
    {
        return $this->requiresOwnerApproval($shopOwnerId, 'price_approval', $amount);
    }

    public function requiresOwnerApprovalForPurchaseRequest(int $shopOwnerId, float $amount): bool
    {
        return $this->requiresOwnerApproval($shopOwnerId, 'purchase_request_approval', $amount);
    }

    public function requiresOwnerApprovalForRepairReject(int $shopOwnerId, float $amount): bool
    {
        return $this->requiresOwnerApproval($shopOwnerId, 'repair_reject_approval', $amount);
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