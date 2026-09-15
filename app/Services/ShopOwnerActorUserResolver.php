<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class ShopOwnerActorUserResolver
{
    public function resolve(int $shopOwnerId): ?int
    {
        $shopOwner = ShopOwner::query()->select('id', 'email')->find($shopOwnerId);

        $mappedByRole = User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where(function ($query): void {
                $query
                    ->whereIn('role', ['Shop Owner', 'SHOP_OWNER', 'shop_owner', 'shop-owner'])
                    ->orWhereHas('roles', fn ($roles) => $roles->whereIn('name', [
                        'Shop Owner', 'SHOP_OWNER', 'shop_owner', 'shop-owner',
                    ]));
            })
            ->orderByDesc('id')
            ->value('id');

        if ($mappedByRole) {
            return (int) $mappedByRole;
        }

        if ($shopOwner && $shopOwner->email) {
            $mappedByEmail = User::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->whereRaw('LOWER(email) = ?', [strtolower((string) $shopOwner->email)])
                ->orderByDesc('id')
                ->value('id');

            if ($mappedByEmail) {
                return (int) $mappedByEmail;
            }
        }

        return null;
    }

    public function ensure(ShopOwner $shopOwner): ?int
    {
        $resolvedId = $this->resolve((int) $shopOwner->id);
        if ($resolvedId) {
            return $resolvedId;
        }

        $primaryEmail = strtolower(trim((string) ($shopOwner->email ?? '')));
        $fallbackEmail = 'shopowner+' . $shopOwner->id . '@solespace.local';
        $candidateEmails = array_values(array_unique(array_filter([$primaryEmail, $fallbackEmail])));

        foreach ($candidateEmails as $candidateEmail) {
            $existingUser = User::query()
                ->whereRaw('LOWER(email) = ?', [strtolower($candidateEmail)])
                ->first();

            if (! $existingUser || ($existingUser->shop_owner_id && (int) $existingUser->shop_owner_id !== (int) $shopOwner->id)) {
                continue;
            }

            $existingUser->shop_owner_id = (int) $shopOwner->id;
            $existingUser->role = $existingUser->role ?: 'Shop Owner';
            $existingUser->name = $existingUser->name ?: trim((string) $shopOwner->first_name . ' ' . (string) $shopOwner->last_name);
            $existingUser->email_verified_at ??= now();
            $existingUser->save();
            $this->assignShopOwnerRole($existingUser);

            return (int) $existingUser->id;
        }

        try {
            $user = User::query()->create([
                'first_name' => (string) ($shopOwner->first_name ?? 'Shop'),
                'last_name' => (string) ($shopOwner->last_name ?? 'Owner'),
                'name' => trim((string) ($shopOwner->first_name ?? 'Shop') . ' ' . (string) ($shopOwner->last_name ?? 'Owner')),
                'email' => $fallbackEmail,
                'password' => Hash::make(Str::random(40)),
                'shop_owner_id' => (int) $shopOwner->id,
                'role' => 'Shop Owner',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            $this->assignShopOwnerRole($user);

            return (int) $user->id;
        } catch (\Throwable) {
            return null;
        }
    }

    private function assignShopOwnerRole(User $user): void
    {
        try {
            if (! $user->hasRole('Shop Owner')) {
                $user->assignRole('Shop Owner');
            }
        } catch (\Throwable) {
            // The legacy role column remains the authorization fallback.
        }
    }
}
