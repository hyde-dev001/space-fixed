<?php

namespace App\Services\Logistics;

use App\Models\Logistics\RiderProfile;
use App\Models\User;

class RiderProfileSyncService
{
    public function syncUser(User $user): void
    {
        if (!$user->shop_owner_id || !$user->hasRole('Logistics Rider')) {
            return;
        }

        RiderProfile::updateOrCreate(
            [
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
            ->whereHas('roles', function ($query) {
                $query->where('name', 'Logistics Rider')
                    ->where('guard_name', 'user');
            })
            ->get()
            ->each(fn (User $user) => $this->syncUser($user));

        RiderProfile::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('rider_type', 'employee')
            ->where('linked_type', User::class)
            ->whereDoesntHaveMorph('linked', [User::class], function ($query) {
                $query->whereHas('roles', function ($roles) {
                    $roles->where('name', 'Logistics Rider')
                        ->where('guard_name', 'user');
                });
            })
            ->update(['active' => false]);
    }
}
