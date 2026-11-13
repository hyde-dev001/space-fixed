<?php

declare(strict_types=1);

namespace App\Support\OwnerActionCenter;

use InvalidArgumentException;

final readonly class OwnerAttentionAdapterResult
{
    /**
     * @param array<int, OwnerAttentionItem> $items
     */
    public function __construct(
        public array $items,
        public int $qualifyingCount,
    ) {
        if ($qualifyingCount < 0 || $qualifyingCount < count($items)) {
            throw new InvalidArgumentException('Owner attention adapter counts are invalid.');
        }

        foreach ($items as $item) {
            if (! $item instanceof OwnerAttentionItem) {
                throw new InvalidArgumentException('Owner attention adapter results must contain attention items.');
            }
        }
    }

    /**
     * @return array{items: array<int, array<string, bool|float|int|string|null>>, qualifying_count: int}
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(
                static fn (OwnerAttentionItem $item): array => $item->toArray(),
                array_values($this->items),
            ),
            'qualifying_count' => $this->qualifyingCount,
        ];
    }
}
