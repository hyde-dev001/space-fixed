<?php

declare(strict_types=1);

namespace App\Services\Authentication;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class UnifiedLoginContextResolver
{
    private const DUMMY_PASSWORD_HASH = '$2y$10$5n3DruMVEXy/QDrfseoa.uJ3ed2F8YjGuWk8rbM.tE0uNTd85ew.C';

    /**
     * Resolve the account context only after verifying the supplied password.
     *
     * The user account wins when the same credentials exist in both contexts.
     * A missing record still performs a dummy hash check to keep failures generic.
     */
    public function resolve(string $email, string $password): ?string
    {
        $user = User::query()->where('email', $email)->first();
        $shopOwner = ShopOwner::query()->where('email', $email)->first();

        $userPasswordMatches = Hash::check(
            $password,
            (string) ($user?->getAuthPassword() ?: self::DUMMY_PASSWORD_HASH),
        );

        $shopOwnerPasswordMatches = Hash::check(
            $password,
            (string) ($shopOwner?->getAuthPassword() ?: self::DUMMY_PASSWORD_HASH),
        );

        if ($userPasswordMatches) {
            return 'user';
        }

        return $shopOwnerPasswordMatches ? 'shop_owner' : null;
    }
}
