<?php

declare(strict_types=1);

namespace App\Support\OwnerShell;

use App\Enums\OwnerShellPresentation;
use App\Enums\OwnerShellSelectionReason;
use InvalidArgumentException;

final readonly class OwnerShellMetadata
{
    /**
     * @param array<int, OwnerShellGroup> $groups
     * @param array{show_erp_fallback: bool, erp_workspace_url: string|null, fallback_url: string|null} $compatibility
     */
    public function __construct(
        public OwnerShellPresentation $presentation,
        public OwnerShellSelectionReason $selectionReason,
        public ?string $context,
        public array $groups,
        public array $compatibility,
    ) {
        if ($context !== null && ! in_array($context, ['individual', 'company'], true)) {
            throw new InvalidArgumentException('Owner shell metadata context is invalid.');
        }

        foreach ($groups as $group) {
            if (! $group instanceof OwnerShellGroup) {
                throw new InvalidArgumentException('Owner shell metadata groups must be OwnerShellGroup values.');
            }
        }

        $this->validateCompatibility($compatibility);

        if ($presentation === OwnerShellPresentation::Canonical && $context === null) {
            throw new InvalidArgumentException('Canonical owner shell metadata requires a registration context.');
        }

        if ($presentation === OwnerShellPresentation::Existing) {
            if ($context !== null || $groups !== [] || $compatibility['show_erp_fallback'] !== false) {
                throw new InvalidArgumentException('Existing owner shell metadata cannot carry canonical presentation data.');
            }

            if ($compatibility['erp_workspace_url'] !== null || $compatibility['fallback_url'] !== null) {
                throw new InvalidArgumentException('Existing owner shell metadata cannot carry ERP fallback URLs.');
            }
        }
    }

    public static function existing(OwnerShellSelectionReason $reason): self
    {
        return new self(
            OwnerShellPresentation::Existing,
            $reason,
            null,
            [],
            [
                'show_erp_fallback' => false,
                'erp_workspace_url' => null,
                'fallback_url' => null,
            ],
        );
    }

    /**
     * @return array{presentation: string, selection_reason: string, context: string|null, groups: array<int, array<string, mixed>>, compatibility: array{show_erp_fallback: bool, erp_workspace_url: string|null, fallback_url: string|null}}
     */
    public function toArray(): array
    {
        return [
            'presentation' => $this->presentation->value,
            'selection_reason' => $this->selectionReason->value,
            'context' => $this->context,
            'groups' => array_map(
                static fn (OwnerShellGroup $group): array => $group->toArray(),
                array_values($this->groups),
            ),
            'compatibility' => [
                'show_erp_fallback' => $this->compatibility['show_erp_fallback'],
                'erp_workspace_url' => $this->compatibility['erp_workspace_url'],
                'fallback_url' => $this->compatibility['fallback_url'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $compatibility
     */
    private function validateCompatibility(array $compatibility): void
    {
        if (array_keys($compatibility) !== ['show_erp_fallback', 'erp_workspace_url', 'fallback_url']) {
            throw new InvalidArgumentException('Owner shell compatibility metadata has an invalid shape.');
        }

        if (! is_bool($compatibility['show_erp_fallback'])) {
            throw new InvalidArgumentException('Owner shell fallback visibility must be boolean.');
        }

        foreach (['erp_workspace_url', 'fallback_url'] as $key) {
            $url = $compatibility[$key];
            if ($url !== null && (! is_string($url) || $url === '' || strlen($url) > 2048 || ! str_starts_with($url, '/shop-owner/') || preg_match('/[\x00-\x20\x7F*]/', $url) === 1)) {
                throw new InvalidArgumentException("Owner shell compatibility {$key} is invalid.");
            }
        }

        if ($compatibility['show_erp_fallback'] === true
            && ($compatibility['erp_workspace_url'] === null || $compatibility['fallback_url'] === null)) {
            throw new InvalidArgumentException('Visible ERP fallback requires both compatibility URLs.');
        }

        if ($compatibility['show_erp_fallback'] === false
            && ($compatibility['erp_workspace_url'] !== null || $compatibility['fallback_url'] !== null)) {
            throw new InvalidArgumentException('Hidden ERP fallback cannot carry compatibility URLs.');
        }
    }
}
