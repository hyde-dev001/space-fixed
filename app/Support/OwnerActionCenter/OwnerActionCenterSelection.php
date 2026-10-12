<?php

declare(strict_types=1);

namespace App\Support\OwnerActionCenter;

use App\Enums\OwnerActionCenterDegradationStatus;
use App\Enums\OwnerActionCenterRolloutReason;
use InvalidArgumentException;

final readonly class OwnerActionCenterSelection
{
    public function __construct(
        public bool $selected,
        public OwnerActionCenterRolloutReason $reason,
        public OwnerActionCenterDegradationStatus $degradationStatus,
        public ?string $context,
    ) {
        if ($context !== null && ! in_array($context, ['individual', 'company'], true)) {
            throw new InvalidArgumentException('Owner Action Center context must be individual, company, or null.');
        }

        if ($selected && $context === null) {
            throw new InvalidArgumentException('A selected Owner Action Center cohort requires a registration context.');
        }
    }

    /**
     * @return array{selected: bool, rollout_reason: string, degradation_status: string, context: string|null}
     */
    public function toArray(): array
    {
        return [
            'selected' => $this->selected,
            'rollout_reason' => $this->reason->value,
            'degradation_status' => $this->degradationStatus->value,
            'context' => $this->context,
        ];
    }
}
