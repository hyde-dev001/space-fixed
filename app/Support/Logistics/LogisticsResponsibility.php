<?php

declare(strict_types=1);

namespace App\Support\Logistics;

final readonly class LogisticsResponsibility
{
    public function __construct(
        public bool $ownerActionRequired,
        public ?string $deterministicResponsibleParty,
        public bool $recoveryPathActive,
        public bool $recoveryPathExhausted,
        public bool $materialExceptionActive,
        public ?string $healthReason,
    ) {}

    public function isHealthy(): bool
    {
        return $this->healthReason === null;
    }

    public function isUnownedMaterialException(): bool
    {
        return $this->isHealthy()
            && ! $this->ownerActionRequired
            && $this->deterministicResponsibleParty === null
            && $this->recoveryPathExhausted
            && $this->materialExceptionActive;
    }
}
