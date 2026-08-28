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
     */
    public function __construct(
        public OwnerShellPresentation $presentation,
        public OwnerShellSelectionReason $selectionReason,
        public ?string $context,
        public array $groups,
    ) {
        if ($context !== null && ! in_array($context, ['individual', 'company'], true)) {
            throw new InvalidArgumentException('Owner shell metadata context is invalid.');
        }

        foreach ($groups as $group) {
            if (! $group instanceof OwnerShellGroup) {
                throw new InvalidArgumentException('Owner shell metadata groups must be OwnerShellGroup values.');
            }
        }

        if ($presentation === OwnerShellPresentation::Canonical && $context === null) {
            throw new InvalidArgumentException('Canonical owner shell metadata requires a registration context.');
        }

        if ($presentation === OwnerShellPresentation::Existing) {
            if ($context !== null || $groups !== []) {
                throw new InvalidArgumentException('Existing owner shell metadata cannot carry canonical presentation data.');
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
        );
    }

    /**
     * @return array{presentation: string, selection_reason: string, context: string|null, groups: array<int, array<string, mixed>>}
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
        ];
    }
}
