<?php

declare(strict_types=1);

namespace App\Support\OwnerActionCenter;

use App\Enums\OwnerActionCenterDegradationStatus;
use InvalidArgumentException;

final readonly class OwnerActionCenterResult
{
    private const ADAPTER_KEYS = [
        'order_refunds',
        'repair_refunds',
        'expenses',
        'purchase_requests',
        'compliance_documents',
        'failed_order_refunds',
        'failed_repair_refunds',
        'unowned_logistics_failures',
        'pending_compliance_renewals',
        'waiting_order_refund_recovery',
        'waiting_repair_refund_recovery',
        'active_logistics_recovery',
    ];

    /**
     * @param array<int, OwnerAttentionItem> $items
     * @param array<string, int> $coverageCounts
     * @param array<int, string> $enabledAdapterKeys
     * @param array<int, string> $healthyAdapterKeys
     * @param array<int, string> $failedAdapterKeys
     */
    public function __construct(
        public array $items,
        public array $coverageCounts,
        public array $enabledAdapterKeys,
        public array $healthyAdapterKeys,
        public array $failedAdapterKeys,
        public OwnerActionCenterDegradationStatus $degradationStatus,
        public string $bucket,
        public string $coverage,
        public int $page,
        public int $perPage,
        public int $total,
        public int $lastPage,
    ) {
        if (! in_array($bucket, OwnerAttentionQuery::BUCKETS, true)
            || ! in_array($coverage, OwnerAttentionQuery::COVERAGES_BY_BUCKET[$bucket], true)) {
            throw new InvalidArgumentException('Owner Action Center result coverage is invalid.');
        }

        if ($page < 1 || $perPage < 1 || $perPage > OwnerAttentionQuery::MAX_PER_PAGE) {
            throw new InvalidArgumentException('Owner Action Center result pagination is invalid.');
        }

        if ($total < 0 || $lastPage < 1 || $page > $lastPage || $lastPage !== max(1, (int) ceil($total / $perPage))) {
            throw new InvalidArgumentException('Owner Action Center result page bounds are invalid.');
        }

        if (count($items) > $perPage) {
            throw new InvalidArgumentException('Owner Action Center result contains too many items.');
        }

        foreach ($items as $item) {
            if (! $item instanceof OwnerAttentionItem) {
                throw new InvalidArgumentException('Owner Action Center results must contain attention items.');
            }
        }

        foreach ($coverageCounts as $source => $count) {
            if (! in_array($source, array_diff(OwnerAttentionQuery::COVERAGES_BY_BUCKET[$bucket], ['all']), true)
                || ! is_int($count)
                || $count < 0) {
                throw new InvalidArgumentException('Owner Action Center coverage counts are invalid.');
            }
        }

        $this->validateAdapterKeys($enabledAdapterKeys, 'enabled');
        $this->validateAdapterKeys($healthyAdapterKeys, 'healthy');
        $this->validateAdapterKeys($failedAdapterKeys, 'failed');

        if (array_intersect($healthyAdapterKeys, $failedAdapterKeys) !== []) {
            throw new InvalidArgumentException('Owner Action Center adapter health cannot be both healthy and failed.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(
                static fn (OwnerAttentionItem $item): array => $item->toArray(),
                array_values($this->items),
            ),
            'coverage_counts' => $this->coverageCounts,
            'health' => [
                'enabled_adapter_keys' => array_values($this->enabledAdapterKeys),
                'healthy_adapter_keys' => array_values($this->healthyAdapterKeys),
                'failed_adapter_keys' => array_values($this->failedAdapterKeys),
            ],
            'degradation_status' => $this->degradationStatus->value,
            'bucket' => $this->bucket,
            'coverage' => $this->coverage,
            'pagination' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'last_page' => $this->lastPage,
            ],
        ];
    }

    /**
     * @param array<int, string> $keys
     */
    private function validateAdapterKeys(array $keys, string $label): void
    {
        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException("Owner Action Center {$label} adapter keys cannot repeat.");
        }

        foreach ($keys as $key) {
            if (! is_string($key) || ! in_array($key, self::ADAPTER_KEYS, true)) {
                throw new InvalidArgumentException("Owner Action Center {$label} adapter key is invalid.");
            }
        }
    }
}
