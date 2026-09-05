<?php

namespace App\Services;

use App\Models\ProcurementSettings;
use InvalidArgumentException;

class ShopOwnerApprovalPolicyService
{
    private const APPROVAL_KEYS = [
        'refund_approval',
        'price_approval',
        'payslip_approval',
        'salary_adjustment_approval',
        'purchase_request_approval',
        'expense_approval',
        'repair_reject_approval',
    ];

    public function requiresOwnerApprovalForPriceChange(int $shopOwnerId, float $currentPrice, float $proposedPrice): bool
    {
        return $this->readApprovalToggle($shopOwnerId, 'price_approval');
    }

    public function requiresOwnerApprovalForPurchaseRequest(int $shopOwnerId, float $amount): bool
    {
        return $this->readApprovalToggle($shopOwnerId, 'purchase_request_approval');
    }

    public function requiresOwnerApprovalForRepairReject(int $shopOwnerId, float $amount): bool
    {
        return $this->readApprovalToggle($shopOwnerId, 'repair_reject_approval');
    }

    public function requiresOwnerApprovalForRefund(int $shopOwnerId, float $amount): bool
    {
        return $this->readApprovalToggle($shopOwnerId, 'refund_approval');
    }

    public function requiresOwnerApprovalForPayslip(int $shopOwnerId): bool
    {
        return $this->readApprovalToggle($shopOwnerId, 'payslip_approval');
    }

    public function requiresOwnerApprovalForSalaryAdjustment(int $shopOwnerId): bool
    {
        return $this->readApprovalToggle($shopOwnerId, 'salary_adjustment_approval');
    }

    public function requiresOwnerApprovalForExpense(int $shopOwnerId, float $amount): bool
    {
        return $this->readApprovalToggle($shopOwnerId, 'expense_approval');
    }

    /**
     * Read the refund approval rule without creating default settings.
     *
     * The legacy Phase 3 refund adapters still consume the threshold-shaped
     * result. New policy methods above intentionally ignore the stored limit;
     * the adapters will be migrated to the binary reader with the Action Center
     * projections.
     *
     * @return array{enabled: bool, limit: float|null}
     */
    public function refundApprovalRuleForRead(int $shopOwnerId): array
    {
        $settings = ProcurementSettings::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->first();
        $approvalPages = $settings?->settings_json['approval_pages'] ?? null;
        $record = is_array($approvalPages) && is_array($approvalPages['refund_approval'] ?? null)
            ? $approvalPages['refund_approval']
            : [];
        $limit = $record['limit'] ?? null;

        return [
            'enabled' => $this->readApprovalToggle($shopOwnerId, 'refund_approval'),
            'limit' => is_numeric($limit) ? (float) $limit : null,
        ];
    }

    /**
     * @param array{enabled: bool, limit: float|null} $rule
     */
    public function requiresOwnerApprovalForRefundRule(array $rule, float $amount): bool
    {
        if (! isset($rule['enabled']) || ! is_bool($rule['enabled'])) {
            return true;
        }

        if (! $rule['enabled']) {
            return false;
        }

        $limit = $rule['limit'] ?? null;
        if ($limit === null || $limit === '') {
            return true;
        }

        return ! is_numeric($limit) || $amount >= (float) $limit;
    }

    private function readApprovalToggle(int $shopOwnerId, string $key): bool
    {
        if (! in_array($key, self::APPROVAL_KEYS, true)) {
            throw new InvalidArgumentException("Unsupported approval policy key [{$key}].");
        }

        $settings = ProcurementSettings::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->first();
        $approvalPages = $settings?->settings_json['approval_pages'] ?? null;

        if (! is_array($approvalPages) || ! is_array($approvalPages[$key] ?? null)) {
            return true;
        }

        $enabled = $approvalPages[$key]['enabled'] ?? null;

        return is_bool($enabled) ? $enabled : true;
    }
}
