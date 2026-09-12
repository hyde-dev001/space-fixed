<?php

declare(strict_types=1);

namespace App\Support\OwnerShell;

use InvalidArgumentException;

final readonly class OwnerShellItem
{
    public function __construct(
        public string $key,
        public string $label,
        public string $canonicalUrl,
        public bool $available,
        public ?string $unavailableReason,
        public ?string $managementUrl,
        /** @var array<int, string> */
        public array $activeMatching,
        /** @var array<int, OwnerShellItem> */
        public array $children = [],
    ) {
        self::validateKey($key, 'item key');
        self::validateLabel($label, 'item label');
        self::validatePath($canonicalUrl, 'canonical URL', false, false);

        if ($activeMatching === []) {
            throw new InvalidArgumentException('Owner shell items require at least one active-matching pattern.');
        }

        foreach ($activeMatching as $pattern) {
            if (! is_string($pattern)) {
                throw new InvalidArgumentException('Owner shell active-matching patterns must be strings.');
            }

            self::validatePath($pattern, 'active-matching pattern', false, true);
        }

        foreach ($children as $child) {
            if (! $child instanceof self) {
                throw new InvalidArgumentException('Owner shell item children must be OwnerShellItem values.');
            }
        }

        if ($available) {
            if ($unavailableReason !== null || $managementUrl !== null) {
                throw new InvalidArgumentException('Available owner shell items cannot carry unavailable metadata.');
            }

            return;
        }

        self::validateReason($unavailableReason);
        self::validatePath($managementUrl, 'management URL', false, false);
    }

    /**
     * @return array{key: string, label: string, canonical_url: string, available: bool, unavailable_reason: string|null, management_url: string|null, active_matching: array<int, string>, children: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'canonical_url' => $this->canonicalUrl,
            'available' => $this->available,
            'unavailable_reason' => $this->unavailableReason,
            'management_url' => $this->managementUrl,
            'active_matching' => array_values($this->activeMatching),
            'children' => array_map(
                static fn (self $child): array => $child->toArray(),
                array_values($this->children),
            ),
        ];
    }

    private static function validateKey(string $value, string $field): void
    {
        if ($value === '' || strlen($value) > 80 || preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/', $value) !== 1) {
            throw new InvalidArgumentException("Owner shell {$field} is invalid.");
        }
    }

    private static function validateLabel(string $value, string $field): void
    {
        if ($value === '' || strlen($value) > 120 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("Owner shell {$field} is invalid.");
        }
    }

    private static function validateReason(?string $value): void
    {
        if ($value === null || strlen($value) > 80 || preg_match('/\A[a-z0-9]+(?:[._:-][a-z0-9]+)*\z/', $value) !== 1) {
            throw new InvalidArgumentException('Unavailable owner shell items require a stable reason.');
        }
    }

    private static function validatePath(?string $value, string $field, bool $nullable, bool $allowWildcard): void
    {
        if ($value === null) {
            if ($nullable) {
                return;
            }

            throw new InvalidArgumentException("Owner shell {$field} is required.");
        }

        if ($value === '' || strlen($value) > 2048 || ! str_starts_with($value, '/shop-owner/') || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("Owner shell {$field} is invalid.");
        }

        if (! $allowWildcard && str_contains($value, '*')) {
            throw new InvalidArgumentException("Owner shell {$field} cannot contain a wildcard.");
        }
    }
}
