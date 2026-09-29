<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use Illuminate\Validation\ValidationException;

final readonly class AuthoritativeMaker
{
    private function __construct(
        private ?int $staffMakerId,
        private ?int $shopOwnerMakerId,
    ) {}

    public static function from(?int $staffMakerId, ?int $shopOwnerMakerId): self
    {
        if (($staffMakerId === null) === ($shopOwnerMakerId === null)) {
            throw ValidationException::withMessages([
                'maker' => 'Exactly one staff or shop owner maker is required.',
            ]);
        }

        return new self($staffMakerId, $shopOwnerMakerId);
    }

    public function isStaff(): bool
    {
        return $this->staffMakerId !== null;
    }

    public function isOwner(): bool
    {
        return $this->shopOwnerMakerId !== null;
    }

    public function staffId(): ?int
    {
        return $this->staffMakerId;
    }

    public function shopOwnerId(): ?int
    {
        return $this->shopOwnerMakerId;
    }
}
