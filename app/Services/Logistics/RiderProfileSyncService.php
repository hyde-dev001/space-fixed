<?php

namespace App\Services\Logistics;

use App\Models\Logistics\RiderProfile;
use App\Models\User;

class RiderProfileSyncService
{
    public function syncUser(User $user): void
    {
        if (!$user->shop_owner_id || !$this->canRide($user)) {
            return;
        }

        RiderProfile::updateOrCreate(
            [
                'shop_owner_id' => (int) $user->shop_owner_id,
                'linked_type' => User::class,
                'linked_id' => $user->id,
            ],
            [
                'shop_owner_id' => (int) $user->shop_owner_id,
                'rider_type' => 'employee',
                'name' => $user->name ?: trim((string) "{$user->first_name} {$user->last_name}"),
                'phone' => $user->phone,
                'availability_status' => 'available',
                'active' => true,
            ]
        );
    }

    public function syncShop(int $shopOwnerId): void
    {
        User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->with(['roles.permissions', 'permissions'])
            ->get()
            ->filter(fn (User $user) => $this->canRide($user))
            ->each(fn (User $user) => $this->syncUser($user));

        RiderProfile::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('rider_type', 'employee')
            ->where('linked_type', User::class)
            ->get()
            ->each(function (RiderProfile $profile) {
                $user = User::find($profile->linked_id);
                if (!$user
                    || (int) $user->shop_owner_id !== (int) $profile->shop_owner_id
                    || ! $this->canRide($user)) {
                    $profile->update(['active' => false]);
                }
            });
    }

    private function canRide(User $user): bool
    {
        return $user->can('operate-logistics-deliveries');
    }
}
