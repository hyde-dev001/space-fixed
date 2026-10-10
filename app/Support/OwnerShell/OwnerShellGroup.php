<?php

declare(strict_types=1);

namespace App\Support\OwnerShell;

use InvalidArgumentException;

final readonly class OwnerShellGroup
{
    /**
     * @param array<int, OwnerShellItem> $items
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $order,
        public bool $defaultExpanded,
        public array $items,
    ) {
        if ($key === '' || strlen($key) > 80 || preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/', $key) !== 1) {
            throw new InvalidArgumentException('Owner shell group key is invalid.');
        }

        if ($label === '' || strlen($label) > 120 || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            throw new InvalidArgumentException('Owner shell group label is invalid.');
        }

        if ($order < 0 || $order > 10000) {
            throw new InvalidArgumentException('Owner shell group order is out of bounds.');
        }

        if ($items === []) {
            throw new InvalidArgumentException('Owner shell groups cannot be empty.');
        }

        foreach ($items as $item) {
            if (! $item instanceof OwnerShellItem) {
                throw new InvalidArgumentException('Owner shell group items must be OwnerShellItem values.');
            }
        }
    }

    /**
     * @return array{key: string, label: string, order: int, default_expanded: bool, items: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'order' => $this->order,
            'default_expanded' => $this->defaultExpanded,
            'items' => array_map(
                static fn (OwnerShellItem $item): array => $item->toArray(),
                array_values($this->items),
            ),
        ];
    }
}
