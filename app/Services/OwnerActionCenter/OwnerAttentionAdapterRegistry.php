<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use App\Models\ShopOwner;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use InvalidArgumentException;

final class OwnerAttentionAdapterRegistry
{
    /**
     * @var array<string, array<string, array<int, array{class: string, key: string, coverage: string, bucket: string}>>>
     */
    private const ADAPTERS = [
        'needs_my_decision' => [
            'refunds' => [
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\OrderRefundAttentionAdapter',
                    'key' => 'order_refunds',
                    'coverage' => 'refunds',
                    'bucket' => 'needs_my_decision',
                ],
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\RepairRefundAttentionAdapter',
                    'key' => 'repair_refunds',
                    'coverage' => 'refunds',
                    'bucket' => 'needs_my_decision',
                ],
            ],
            'prices' => [
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\PriceApprovalAttentionAdapter',
                    'key' => 'price_approvals',
                    'coverage' => 'prices',
                    'bucket' => 'needs_my_decision',
                ],
            ],
            'payslips' => [
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\PayslipAttentionAdapter',
                    'key' => 'payslips',
                    'coverage' => 'payslips',
                    'bucket' => 'needs_my_decision',
                ],
            ],
            'salary_changes' => [
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\SalaryChangeAttentionAdapter',
                    'key' => 'salary_changes',
                    'coverage' => 'salary_changes',
                    'bucket' => 'needs_my_decision',
                ],
            ],
            'expenses' => [
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\ExpenseAttentionAdapter',
                    'key' => 'expenses',
                    'coverage' => 'expenses',
                    'bucket' => 'needs_my_decision',
                ],
            ],
            'purchase_requests' => [
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\PurchaseRequestAttentionAdapter',
                    'key' => 'purchase_requests',
                    'coverage' => 'purchase_requests',
                    'bucket' => 'needs_my_decision',
                ],
            ],
            'repair_rejections' => [
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\RepairRejectAttentionAdapter',
                    'key' => 'repair_rejections',
                    'coverage' => 'repair_rejections',
                    'bucket' => 'needs_my_decision',
                ],
            ],
            'suspensions' => [
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\SuspensionAttentionAdapter',
                    'key' => 'suspension_requests',
                    'coverage' => 'suspensions',
                    'bucket' => 'needs_my_decision',
                ],
            ],
        ],
        'urgent_exceptions' => [
            'compliance' => [
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\ComplianceDocumentAttentionAdapter',
                    'key' => 'compliance_documents',
                    'coverage' => 'compliance',
                    'bucket' => 'urgent_exceptions',
                ],
            ],
            'refunds' => [
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\FailedOrderRefundAttentionAdapter',
                    'key' => 'failed_order_refunds',
                    'coverage' => 'refunds',
                    'bucket' => 'urgent_exceptions',
                ],
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\FailedRepairRefundAttentionAdapter',
                    'key' => 'failed_repair_refunds',
                    'coverage' => 'refunds',
                    'bucket' => 'urgent_exceptions',
                ],
            ],
            'logistics' => [
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\UnownedLogisticsFailureAttentionAdapter',
                    'key' => 'unowned_logistics_failures',
                    'coverage' => 'logistics',
                    'bucket' => 'urgent_exceptions',
                ],
            ],
        ],
        'waiting_on_others' => [
            'compliance' => [
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\PendingComplianceRenewalAttentionAdapter',
                    'key' => 'pending_compliance_renewals',
                    'coverage' => 'compliance',
                    'bucket' => 'waiting_on_others',
                ],
            ],
            'refunds' => [
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\WaitingOrderRefundRecoveryAttentionAdapter',
                    'key' => 'waiting_order_refund_recovery',
                    'coverage' => 'refunds',
                    'bucket' => 'waiting_on_others',
                ],
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\WaitingRepairRefundRecoveryAttentionAdapter',
                    'key' => 'waiting_repair_refund_recovery',
                    'coverage' => 'refunds',
                    'bucket' => 'waiting_on_others',
                ],
            ],
            'logistics' => [
                [
                    'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\ActiveLogisticsRecoveryAttentionAdapter',
                    'key' => 'active_logistics_recovery',
                    'coverage' => 'logistics',
                    'bucket' => 'waiting_on_others',
                ],
            ],
        ],
    ];

    /**
     * @return array<int, OwnerAttentionAdapter>
     */
    public function adaptersFor(string $bucket, string $coverage = 'all'): array
    {
        if (! in_array($bucket, OwnerAttentionQuery::BUCKETS, true)
            || ! in_array($coverage, OwnerAttentionQuery::COVERAGES_BY_BUCKET[$bucket], true)) {
            throw new InvalidArgumentException('Owner Action Center coverage is not supported.');
        }

        if (! $this->bucketEnabled($bucket)) {
            return [];
        }

        $configuredCoverage = $this->configuredCoverage($bucket);
        if (! is_array($configuredCoverage)) {
            throw new InvalidArgumentException('Owner Action Center coverage configuration must be an array.');
        }

        $adapters = [];
        foreach (self::ADAPTERS[$bucket] ?? [] as $family => $definitions) {
            if ($coverage !== 'all' && $coverage !== $family) {
                continue;
            }

            if (! array_key_exists($family, $configuredCoverage)) {
                // Older test/runtime overrides may intentionally provide a partial
                // coverage map. New optional sources stay disabled unless enabled.
                if ($family === 'suspensions') {
                    continue;
                }

                throw new InvalidArgumentException("Owner Action Center coverage [{$family}] must be boolean.");
            }

            if (! is_bool($configuredCoverage[$family])) {
                throw new InvalidArgumentException("Owner Action Center coverage [{$family}] must be boolean.");
            }

            if ($configuredCoverage[$family] === false) {
                continue;
            }

            foreach ($definitions as $definition) {
                $adapter = app($definition['class']);
                if (! $adapter instanceof OwnerAttentionAdapter) {
                    throw new InvalidArgumentException('Configured owner attention adapter has an invalid contract.');
                }

                if ($adapter->adapterKey() !== $definition['key']
                    || $adapter->coverageSource() !== $definition['coverage']
                    || $adapter->primaryBucket() !== $definition['bucket']) {
                    throw new InvalidArgumentException('Configured owner attention adapter identity does not match its registry entry.');
                }

                $adapters[] = $adapter;
            }
        }

        return $adapters;
    }

    /**
     * Resolve the approval adapters that belong in the canonical owner inbox.
     * Individual owners only approve refunds because they do not have the
     * company HR, Finance, Procurement, or repairer-review workflow.
     *
     * @return array<int, OwnerAttentionAdapter>
     */
    public function adaptersForOwner(ShopOwner $owner, string $bucket, string $coverage = 'all'): array
    {
        if ($owner->registration_type === 'individual' && $bucket === 'needs_my_decision') {
            if ($coverage !== 'all' && $coverage !== 'refunds') {
                return [];
            }

            return $this->adaptersFor($bucket, $coverage === 'all' ? 'refunds' : $coverage);
        }

        return $this->adaptersFor($bucket, $coverage);
    }

    /**
     * @return array<int, string>
     */
    public function approvalCoverageSourcesFor(ShopOwner $owner): array
    {
        if ($owner->registration_type === 'individual') {
            return config('owner_action_center.coverage.refunds', false) === true
                ? ['refunds']
                : [];
        }

        return array_values(array_filter(
            array_diff(OwnerAttentionQuery::COVERAGES_BY_BUCKET['needs_my_decision'], ['all']),
            static fn (string $coverage): bool => config("owner_action_center.coverage.{$coverage}", false) === true,
        ));
    }

    private function bucketEnabled(string $bucket): bool
    {
        if ($bucket === 'needs_my_decision') {
            return true;
        }

        return config("owner_action_center.buckets.{$bucket}.enabled", false) === true;
    }

    private function configuredCoverage(string $bucket): mixed
    {
        return $bucket === 'needs_my_decision'
            ? config('owner_action_center.coverage', [])
            : config("owner_action_center.buckets.{$bucket}.coverage", []);
    }
}
