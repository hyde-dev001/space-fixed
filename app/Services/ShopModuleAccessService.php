<?php

namespace App\Services;

use App\Data\ShopModuleAccessDecision;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

final class ShopModuleAccessService
{
    public function __construct(
        private readonly BusinessAccessControlService $businessAccessControl,
    ) {}

    public function decide(ShopOwner $owner, string $moduleKey, bool $enforceState = true): ShopModuleAccessDecision
    {
        $modules = config('shop_modules.modules', []);
        $module = $modules[$moduleKey] ?? null;

        if (! is_array($module)) {
            return ShopModuleAccessDecision::deny(
                code: 'UNKNOWN_MODULE',
                moduleKeys: [$moduleKey],
                message: 'That shop module is not available.',
            );
        }

        $eligibility = $this->eligibilityDecision($owner, $moduleKey, $module);
        if ($eligibility !== null) {
            return $eligibility;
        }

        if (! $enforceState) {
            return ShopModuleAccessDecision::allow([$moduleKey]);
        }

        $moduleState = $this->findModuleState($owner, $moduleKey);
        if (! $moduleState) {
            return ShopModuleAccessDecision::deny(
                code: 'MODULE_STATE_MISSING',
                moduleKeys: [$moduleKey],
                message: 'This module has not been initialized for the shop.',
            );
        }

        if (! (bool) $moduleState->enabled) {
            return ShopModuleAccessDecision::deny(
                code: 'MODULE_DISABLED',
                moduleKeys: [$moduleKey],
                message: 'This module is disabled for the shop.',
            );
        }

        return ShopModuleAccessDecision::allow([$moduleKey]);
    }

    /**
     * @param  array<int, string>  $moduleKeys
     */
    public function decideGate(
        ShopOwner $owner,
        string $mode,
        array $moduleKeys,
        bool $enforceState = true,
    ): ShopModuleAccessDecision
    {
        $moduleKeys = array_values(array_unique(array_map('strval', $moduleKeys)));

        if ($moduleKeys === [] || ! in_array($mode, config('shop_modules.supported_gate_modes', []), true)) {
            return ShopModuleAccessDecision::deny(
                code: 'UNKNOWN_MODULE',
                moduleKeys: $moduleKeys,
                message: 'The requested shop module gate is not available.',
            );
        }

        $unknown = array_values(array_diff($moduleKeys, array_keys(config('shop_modules.modules', []))));
        if ($unknown !== []) {
            return ShopModuleAccessDecision::deny(
                code: 'UNKNOWN_MODULE',
                moduleKeys: $unknown,
                message: 'One or more requested shop modules are not available.',
            );
        }

        if ($mode === 'single' && count($moduleKeys) !== 1) {
            return ShopModuleAccessDecision::deny(
                code: 'UNKNOWN_MODULE',
                moduleKeys: $moduleKeys,
                message: 'A single-module gate must name exactly one module.',
            );
        }

        $decisions = array_map(
            fn (string $moduleKey): ShopModuleAccessDecision => $this->decide($owner, $moduleKey, $enforceState),
            $moduleKeys,
        );

        if ($mode === 'any_of') {
            foreach ($decisions as $decision) {
                if ($decision->allowed) {
                    return ShopModuleAccessDecision::allow($moduleKeys);
                }
            }

            return $this->firstDenied($decisions, $moduleKeys);
        }

        foreach ($decisions as $decision) {
            if (! $decision->allowed) {
                return $this->firstDenied($decisions, $moduleKeys);
            }
        }

        return ShopModuleAccessDecision::allow($moduleKeys);
    }

    public function canAccess(ShopOwner $owner, string $moduleKey): bool
    {
        return $this->decide($owner, $moduleKey)->allowed;
    }

    /**
     * @param  array<int, string>  $moduleKeys
     */
    public function canAccessAll(ShopOwner $owner, array $moduleKeys): bool
    {
        return $this->decideGate($owner, 'all_of', $moduleKeys)->allowed;
    }

    /**
     * @param  array<int, string>  $moduleKeys
     */
    public function canAccessAny(ShopOwner $owner, array $moduleKeys): bool
    {
        return $this->decideGate($owner, 'any_of', $moduleKeys)->allowed;
    }

    public function isEligible(ShopOwner $owner, string $moduleKey): bool
    {
        $module = config("shop_modules.modules.{$moduleKey}");
        if (! is_array($module)) {
            return false;
        }

        return $this->eligibilityDecision($owner, $moduleKey, $module) === null;
    }

    /**
     * @param bool $enforceState Whether persisted module state should gate eligible modules.
     * @return array<string, array{eligible: bool, enabled: bool, accessible: bool, code: ?string, reason: ?string}>
     */
    public function statesFor(ShopOwner $owner, bool $enforceState = true): array
    {
        $owner->loadMissing('modules');
        $states = [];

        foreach (array_keys(config('shop_modules.modules', [])) as $moduleKey) {
            $decision = $this->decide($owner, $moduleKey, $enforceState);
            $moduleState = $this->findModuleState($owner, $moduleKey);

            $states[$moduleKey] = [
                'eligible' => $this->isEligible($owner, $moduleKey),
                'enabled' => $moduleState !== null && (bool) $moduleState->enabled,
                'accessible' => $decision->allowed,
                'code' => $decision->code,
                'reason' => $decision->allowed ? null : $decision->message,
            ];
        }

        return $states;
    }

    public function resolveShopOwnerForActor(Authenticatable $actor): ?ShopOwner
    {
        if ($actor instanceof ShopOwner) {
            return $actor;
        }

        if (! $actor instanceof User || ! $actor->shop_owner_id) {
            return null;
        }

        if ($actor->relationLoaded('shopOwner')) {
            return $actor->shopOwner;
        }

        return $actor->shopOwner()->first();
    }

    /**
     * @param  array<string, mixed>  $module
     */
    private function eligibilityDecision(ShopOwner $owner, string $moduleKey, array $module): ?ShopModuleAccessDecision
    {
        $registrationType = strtolower(trim($this->ownerValue($owner, 'registration_type')));
        $businessType = $this->businessAccessControl->normalizeBusinessType(
            $this->ownerValue($owner, 'business_type'),
        );
        $status = strtolower(trim($this->ownerValue($owner, 'status')));

        if ($status !== 'approved'
            || ! in_array($registrationType, ['individual', 'company'], true)
            || ! in_array($businessType, ['retail', 'repair', 'both'], true)
            || ! in_array($registrationType, $module['registration_types'] ?? [], true)
            || ! in_array($businessType, $module['business_types'] ?? [], true)) {
            return ShopModuleAccessDecision::deny(
                code: 'MODULE_INELIGIBLE',
                moduleKeys: [$moduleKey],
                message: 'This shop is not eligible for the requested module.',
            );
        }

        return null;
    }

    private function ownerValue(ShopOwner $owner, string $attribute): string
    {
        $value = $owner->getRawOriginal($attribute);
        if ($value === null) {
            $value = $owner->getAttribute($attribute);
        }

        return $value instanceof \BackedEnum ? $value->value : (string) $value;
    }

    private function findModuleState(ShopOwner $owner, string $moduleKey): ?ShopOwnerModule
    {
        /** @var Collection<int, ShopOwnerModule> $modules */
        $modules = $owner->relationLoaded('modules')
            ? $owner->getRelation('modules')
            : $owner->modules()->where('module_key', $moduleKey)->get();

        return $modules->first(
            fn (ShopOwnerModule $module): bool => (string) $module->module_key === $moduleKey,
        );
    }

    /**
     * @param  array<int, ShopModuleAccessDecision>  $decisions
     * @param  array<int, string>  $moduleKeys
     */
    private function firstDenied(array $decisions, array $moduleKeys): ShopModuleAccessDecision
    {
        foreach ($decisions as $decision) {
            if (! $decision->allowed) {
                return ShopModuleAccessDecision::deny(
                    code: $decision->code ?? 'MODULE_INELIGIBLE',
                    moduleKeys: $moduleKeys,
                    message: $decision->message,
                );
            }
        }

        return ShopModuleAccessDecision::deny(
            code: 'MODULE_INELIGIBLE',
            moduleKeys: $moduleKeys,
            message: 'The requested shop module gate is not available.',
        );
    }
}
