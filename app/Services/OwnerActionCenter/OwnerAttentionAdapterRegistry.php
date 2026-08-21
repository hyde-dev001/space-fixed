<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
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

            if (! array_key_exists($family, $configuredCoverage) || ! is_bool($configuredCoverage[$family])) {
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
