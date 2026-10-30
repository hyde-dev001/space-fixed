<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PremiumPlan;
use App\Models\ShopOwnerSubscription;
use App\Models\SuperAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PremiumPlanManagementService
{
    public function __construct(private readonly PrivilegedAudit $audit)
    {
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, SuperAdmin $actor, Request $request): PremiumPlan
    {
        $attributes = $this->normalizeAttributes($attributes);

        return DB::transaction(function () use ($attributes, $actor, $request): PremiumPlan {
            $plan = PremiumPlan::query()->create(array_merge($attributes, ['status' => 'active']));
            $this->audit->premiumPlanCreated($request, $actor, $plan, $this->changeSet($attributes));

            return $plan->fresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(PremiumPlan $plan, array $attributes, SuperAdmin $actor, Request $request): PremiumPlan
    {
        unset($attributes['plan_code']);
        $attributes = $this->normalizeAttributes($attributes);

        return DB::transaction(function () use ($plan, $attributes, $actor, $request): PremiumPlan {
            $lockedPlan = PremiumPlan::query()->lockForUpdate()->findOrFail($plan->getKey());
            $changes = $this->changedFields($lockedPlan, $attributes);

            if ($changes === []) {
                return $lockedPlan->fresh();
            }

            $oldLimit = (int) $lockedPlan->showroom_slot_limit;
            $lockedPlan->forceFill($attributes)->save();

            if (isset($attributes['showroom_slot_limit'])
                && (int) $attributes['showroom_slot_limit'] > $oldLimit) {
                ShopOwnerSubscription::query()
                    ->where('premium_plan_id', $lockedPlan->getKey())
                    ->showroomEntitled()
                    ->where('showroom_slot_limit', '<', (int) $attributes['showroom_slot_limit'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->each(function (ShopOwnerSubscription $subscription) use ($attributes): void {
                        $subscription->forceFill([
                            'showroom_slot_limit' => (int) $attributes['showroom_slot_limit'],
                        ])->save();
                    });
            }

            $this->audit->premiumPlanUpdated($request, $actor, $lockedPlan, $changes);

            return $lockedPlan->fresh();
        });
    }

    public function archive(PremiumPlan $plan, SuperAdmin $actor, Request $request): PremiumPlan
    {
        return $this->transition($plan, 'inactive', 'premiumPlanArchived', $actor, $request);
    }

    public function reactivate(PremiumPlan $plan, SuperAdmin $actor, Request $request): PremiumPlan
    {
        return $this->transition($plan, 'active', 'premiumPlanReactivated', $actor, $request);
    }

    /** @param 'active'|'inactive' $nextStatus */
    private function transition(
        PremiumPlan $plan,
        string $nextStatus,
        string $auditMethod,
        SuperAdmin $actor,
        Request $request,
    ): PremiumPlan {
        return DB::transaction(function () use ($plan, $nextStatus, $auditMethod, $actor, $request): PremiumPlan {
            $lockedPlan = PremiumPlan::query()->lockForUpdate()->findOrFail($plan->getKey());
            $currentStatus = (string) $lockedPlan->status;

            if ($currentStatus === $nextStatus) {
                return $lockedPlan->fresh();
            }

            $lockedPlan->forceFill(['status' => $nextStatus])->save();
            $this->audit->{$auditMethod}(
                $request,
                $actor,
                $lockedPlan,
                ['status' => ['from' => $currentStatus, 'to' => $nextStatus]],
            );

            return $lockedPlan->fresh();
        });
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function normalizeAttributes(array $attributes): array
    {
        foreach (['name', 'description'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = $attributes[$key] === null
                    ? null
                    : trim((string) $attributes[$key]);
            }
        }

        if (array_key_exists('benefits', $attributes)) {
            $attributes['benefits'] = array_values(array_filter(
                array_map(static fn (mixed $benefit): string => trim((string) $benefit), (array) $attributes['benefits']),
                static fn (string $benefit): bool => $benefit !== '',
            ));
        }

        if (array_key_exists('duration_days', $attributes)) {
            $attributes['duration_days'] = (int) $attributes['duration_days'];
        }

        if (array_key_exists('showroom_slot_limit', $attributes)) {
            $attributes['showroom_slot_limit'] = (int) $attributes['showroom_slot_limit'];
        }

        if (array_key_exists('price', $attributes)) {
            $attributes['price'] = (float) $attributes['price'];
        }

        return $attributes;
    }

    /** @param array<string, mixed> $attributes @return array<string, array{from: mixed, to: mixed}> */
    private function changedFields(PremiumPlan $plan, array $attributes): array
    {
        $changes = [];
        foreach (array_keys($attributes) as $field) {
            $old = $this->comparable($plan->getAttribute($field), $field);
            $new = $this->comparable($attributes[$field], $field);
            if ($old !== $new) {
                $changes[$field] = ['from' => $old, 'to' => $new];
            }
        }

        return $changes;
    }

    /** @param array<string, mixed> $attributes @return array<string, array{from: null, to: mixed}> */
    private function changeSet(array $attributes): array
    {
        $changes = [];
        foreach ($attributes as $field => $value) {
            $changes[$field] = ['from' => null, 'to' => $value];
        }

        return $changes;
    }

    private function comparable(mixed $value, string $field): mixed
    {
        return match ($field) {
            'price' => (float) $value,
            'duration_days', 'showroom_slot_limit' => (int) $value,
            'benefits' => array_values((array) $value),
            default => $value,
        };
    }
}
