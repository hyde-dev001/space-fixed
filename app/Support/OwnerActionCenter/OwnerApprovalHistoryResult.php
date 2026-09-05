<?php

declare(strict_types=1);

namespace App\Support\OwnerActionCenter;

use InvalidArgumentException;

final readonly class OwnerApprovalHistoryResult
{
    /**
     * @param array<int, OwnerApprovalHistoryItem> $items
     * @param array<string, int> $coverageCounts
     */
    public function __construct(
        public array $items,
        public array $coverageCounts,
        public string $coverage,
        public int $page,
        public int $perPage,
        public int $total,
        public int $lastPage,
    ) {
        if (! in_array($coverage, OwnerAttentionQuery::COVERAGES_BY_BUCKET['needs_my_decision'], true)
            || $page < 1
            || $perPage < 1
            || $perPage > OwnerAttentionQuery::MAX_PER_PAGE
            || $total < 0
            || $lastPage < 1
            || $page > $lastPage
            || $lastPage !== max(1, (int) ceil($total / $perPage))
            || count($items) > $perPage) {
            throw new InvalidArgumentException('Owner approval history pagination is invalid.');
        }

        foreach ($items as $item) {
            if (! $item instanceof OwnerApprovalHistoryItem) {
                throw new InvalidArgumentException('Owner approval history items are invalid.');
            }
        }

        foreach ($coverageCounts as $source => $count) {
            if (! in_array($source, array_diff(OwnerAttentionQuery::COVERAGES_BY_BUCKET['needs_my_decision'], ['all']), true)
                || ! is_int($count)
                || $count < 0) {
                throw new InvalidArgumentException('Owner approval history coverage counts are invalid.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(
                static fn (OwnerApprovalHistoryItem $item): array => $item->toArray(),
                array_values($this->items),
            ),
            'coverage_counts' => $this->coverageCounts,
            'coverage' => $this->coverage,
            'pagination' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'last_page' => $this->lastPage,
            ],
        ];
    }
}
