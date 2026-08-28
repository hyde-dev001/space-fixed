<?php

namespace App\Actions\ShopOwner;

use App\Models\ShopOwner;
use App\Services\OwnerOperationAudit;
use App\Services\ShopModuleAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ToggleShopOwnerModule
{
    public function __construct(
        private readonly ShopModuleAccessService $access,
        private readonly OwnerOperationAudit $audit,
    ) {}

    /**
     * @return array{module_key: string, enabled: bool, changed: bool, states: array<string, array<string, mixed>>}
     */
    public function handle(ShopOwner $owner, string $moduleKey, bool $enabled): array
    {
        if (! array_key_exists($moduleKey, config('shop_modules.modules', []))) {
            throw ValidationException::withMessages(['module_key' => 'That shop module is not available.']);
        }

        $correlationId = (string) Str::uuid();

        return DB::transaction(function () use ($owner, $moduleKey, $enabled, $correlationId): array {
            $lockedOwner = ShopOwner::query()->lockForUpdate()->findOrFail($owner->getKey());
            if ($this->ownerValue($lockedOwner, 'status') !== 'approved') {
                throw ValidationException::withMessages(['module_key' => 'Only an approved shop owner can manage modules.']);
            }

            if (! $this->access->isEligible($lockedOwner, $moduleKey)) {
                throw ValidationException::withMessages(['module_key' => 'This shop is not eligible for the requested module.']);
            }

            $module = $lockedOwner->modules()
                ->where('module_key', $moduleKey)
                ->lockForUpdate()
                ->first();
            if (! $module) {
                throw ValidationException::withMessages(['module_key' => 'This module has not been initialized for the shop.']);
            }

            $oldEnabled = (bool) $module->enabled;
            if ($oldEnabled === $enabled) {
                return [
                    'module_key' => $moduleKey,
                    'enabled' => $oldEnabled,
                    'changed' => false,
                    'states' => $this->access->statesFor($lockedOwner->fresh()),
                ];
            }

            $module->update(['enabled' => $enabled]);
            $this->audit->record(
                actor: $lockedOwner,
                tenantOwnerId: (int) $lockedOwner->getKey(),
                module: 'settings',
                action: 'shop_owner_module_toggled',
                result: 'succeeded',
                subject: $module,
                context: [
                    'correlation_id' => $correlationId,
                    'source' => 'settings.modules-team',
                    'before' => [
                        'enabled' => $oldEnabled,
                        'module_key' => $moduleKey,
                    ],
                    'after' => [
                        'enabled' => $enabled,
                        'module_key' => $moduleKey,
                    ],
                ],
            );

            return [
                'module_key' => $moduleKey,
                'enabled' => $enabled,
                'changed' => true,
                'states' => $this->access->statesFor($lockedOwner->fresh()),
            ];
        });
    }

    private function ownerValue(ShopOwner $owner, string $attribute): string
    {
        $value = $owner->getRawOriginal($attribute);
        if ($value === null) {
            $value = $owner->getAttribute($attribute);
        }

        return $value instanceof \BackedEnum ? $value->value : strtolower(trim((string) $value));
    }
}
