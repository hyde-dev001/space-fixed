<?php

declare(strict_types=1);

namespace App\Support\Erp;

use App\Data\ShopModuleAccessDecision;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

final class ErpActorContext
{
    /**
     * @param  array<int, string>  $moduleKeys
     */
    public function __construct(
        private readonly Authenticatable $actor,
        private readonly string $guard,
        private readonly ShopOwner $tenantOwner,
        private readonly bool $ownerMode,
        private readonly string $routeName,
        private readonly string $method,
        private readonly string $action,
        private readonly array $moduleKeys,
        private readonly ?string $gateMode,
        private ?ShopModuleAccessDecision $decision = null,
    ) {}

    public function actor(): Authenticatable
    {
        return $this->actor;
    }

    public function employeeActor(): ?User
    {
        return $this->actor instanceof User ? $this->actor : null;
    }

    public function ownerActor(): ?ShopOwner
    {
        return $this->actor instanceof ShopOwner ? $this->actor : null;
    }

    public function tenantOwner(): ShopOwner
    {
        return $this->tenantOwner;
    }

    public function isOwnerMode(): bool
    {
        return $this->ownerMode;
    }

    public function guard(): string
    {
        return $this->guard;
    }

    public function routeName(): string
    {
        return $this->routeName;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function action(): string
    {
        return $this->action;
    }

    /**
     * @return array<int, string>
     */
    public function moduleKeys(): array
    {
        return $this->moduleKeys;
    }

    public function gateMode(): ?string
    {
        return $this->gateMode;
    }

    public function decision(): ?ShopModuleAccessDecision
    {
        return $this->decision;
    }

    public function withDecision(?ShopModuleAccessDecision $decision): self
    {
        $context = clone $this;
        $context->decision = $decision;

        return $context;
    }
}
