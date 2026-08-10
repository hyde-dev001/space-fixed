<?php

namespace App\Services;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class ShopModuleProvisioningService
{
    public function __construct(
        private readonly BusinessAccessControlService $businessAccessControl,
    ) {}

    /**
     * Insert enabled rows for eligible modules that do not have persisted state.
     * Existing rows, including disabled choices, are never updated.
     *
     * @param  array<int, string>  $eligibleKeys
     * @return Collection<int, ShopOwnerModule>
     */
    public function initializeMissing(ShopOwner $owner, array $eligibleKeys): Collection
    {
        $eligibleKeys = array_values(array_unique(array_map('strval', $eligibleKeys)));
        $knownKeys = array_keys(config('shop_modules.modules', []));
        $unknownKeys = array_values(array_diff($eligibleKeys, $knownKeys));

        if ($unknownKeys !== []) {
            throw new InvalidArgumentException('Cannot initialize unknown shop module keys.');
        }

        if ($eligibleKeys === []) {
            return collect();
        }

        $existingKeys = $owner->modules()
            ->whereIn('module_key', $eligibleKeys)
            ->pluck('module_key')
            ->map(static fn ($key): string => (string) $key)
            ->all();

        $created = collect();
        foreach (array_values(array_diff($eligibleKeys, $existingKeys)) as $moduleKey) {
            $module = $owner->modules()->firstOrCreate(
                ['module_key' => $moduleKey],
                ['enabled' => true],
            );

            if ($module->wasRecentlyCreated) {
                $created->push($module);
            }
        }

        return $created;
    }

    /**
     * Resolve the catalog keys currently eligible for a shop owner.
     * Pending, rejected, suspended, or malformed legacy owners receive none.
     *
     * @return array<int, string>
     */
    public function eligibleKeysFor(ShopOwner $owner): array
    {
        $status = $this->ownerValue($owner, 'status');
        if ($status !== 'approved') {
            return [];
        }

        $registrationType = strtolower(trim($this->ownerValue($owner, 'registration_type')));
        $businessType = $this->businessAccessControl->normalizeBusinessType(
            $this->ownerValue($owner, 'business_type'),
        );

        if (! in_array($registrationType, ['individual', 'company'], true)
            || ! in_array($businessType, ['retail', 'repair', 'both'], true)) {
            return [];
        }

        $eligible = [];
        foreach (config('shop_modules.modules', []) as $moduleKey => $module) {
            if (($module['backfill_enabled'] ?? false)
                && in_array($registrationType, $module['registration_types'] ?? [], true)
                && in_array($businessType, $module['business_types'] ?? [], true)) {
                $eligible[] = (string) $moduleKey;
            }
        }

        return $eligible;
    }

    private function ownerValue(ShopOwner $owner, string $attribute): string
    {
        $value = $owner->getRawOriginal($attribute);
        if ($value === null) {
            $value = $owner->getAttribute($attribute);
        }

        return $value instanceof \BackedEnum ? $value->value : (string) $value;
    }
}
