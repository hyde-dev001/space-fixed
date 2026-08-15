<?php

declare(strict_types=1);

namespace App\Services\OwnerActionCenter;

use App\Contracts\OwnerActionCenter\OwnerAttentionAdapter;
use InvalidArgumentException;

final class OwnerAttentionAdapterRegistry
{
    /**
     * @var array<string, array<int, array{class: string, key: string, coverage: string}>>
     */
    private const ADAPTERS = [
        'refunds' => [
            [
                'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\OrderRefundAttentionAdapter',
                'key' => 'order_refunds',
                'coverage' => 'refunds',
            ],
            [
                'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\RepairRefundAttentionAdapter',
                'key' => 'repair_refunds',
                'coverage' => 'refunds',
            ],
        ],
        'expenses' => [
            [
                'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\ExpenseAttentionAdapter',
                'key' => 'expenses',
                'coverage' => 'expenses',
            ],
        ],
        'purchase_requests' => [
            [
                'class' => 'App\\Services\\OwnerActionCenter\\Adapters\\PurchaseRequestAttentionAdapter',
                'key' => 'purchase_requests',
                'coverage' => 'purchase_requests',
            ],
        ],
    ];

    /**
     * @return array<int, OwnerAttentionAdapter>
     */
    public function adaptersFor(string $coverage): array
    {
        if (! in_array($coverage, ['all', 'refunds', 'expenses', 'purchase_requests'], true)) {
            throw new InvalidArgumentException('Owner Action Center coverage is not supported.');
        }

        $configuredCoverage = config('owner_action_center.coverage', []);
        if (! is_array($configuredCoverage)) {
            throw new InvalidArgumentException('Owner Action Center coverage configuration must be an array.');
        }

        $adapters = [];
        foreach (self::ADAPTERS as $family => $definitions) {
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
                    || $adapter->coverageSource() !== $definition['coverage']) {
                    throw new InvalidArgumentException('Configured owner attention adapter identity does not match its registry entry.');
                }

                $adapters[] = $adapter;
            }
        }

        return $adapters;
    }
}
