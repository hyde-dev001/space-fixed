<?php

declare(strict_types=1);

namespace App\Support\OwnerShell;

use App\Enums\OwnerShellPresentation;
use App\Enums\OwnerShellSelectionReason;
use InvalidArgumentException;

final readonly class OwnerShellSelection
{
    public function __construct(
        public OwnerShellPresentation $presentation,
        public OwnerShellSelectionReason $reason,
        public ?string $context,
    ) {
        if ($context !== null && ! in_array($context, ['individual', 'company'], true)) {
            throw new InvalidArgumentException('Owner shell context must be individual, company, or null.');
        }

        if ($presentation === OwnerShellPresentation::Canonical && $context === null) {
            throw new InvalidArgumentException('Canonical owner shell selection requires a registration context.');
        }
    }

    /**
     * @return array{presentation: string, selection_reason: string, context: string|null}
     */
    public function toArray(): array
    {
        return [
            'presentation' => $this->presentation->value,
            'selection_reason' => $this->reason->value,
            'context' => $this->context,
        ];
    }
}
