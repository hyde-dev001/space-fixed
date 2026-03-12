<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * ShopScoped — automatic shop isolation for Eloquent models.
 *
 * Registers a global scope named 'shop' that constrains all SELECT queries
 * to the authenticated user's shop. This is a safety net: controllers should
 * still resolve $shopId explicitly and 403 on missing shop context, but this
 * prevents silent cross-shop data leaks if a developer forgets the WHERE clause.
 *
 * Usage:
 *   use App\Models\Traits\ShopScoped;
 *   class Invoice extends Model {
 *       use ShopScoped;
 *       // Defaults to column 'shop_id'. Override with:
 *       // const SHOP_SCOPE_COLUMN = 'shop_owner_id';
 *   }
 *
 * Bypass in trusted contexts (webhooks, admin reports, seeders):
 *   Invoice::withoutGlobalScope('shop')->where(...)->get();
 *
 * The scope is silently skipped when:
 *   - No authenticated user (seeders, artisan, webhook controllers)
 *   - The user cannot be resolved to a shop (controller-level 403s handle that)
 */
trait ShopScoped
{
    public static function bootShopScoped(): void
    {
        static::addGlobalScope('shop', function (Builder $builder) {
            $user = auth()->user();

            // Non-HTTP contexts (seeders, artisan, unauthenticated webhooks) — skip
            if (! $user) {
                return;
            }

            // ERP employees: shop_owner_id points to their shop.
            // Shop Owners themselves: shop_owner_id is null, their own id IS the shop id.
            $shopId = $user->shop_owner_id
                ?? ($user->hasRole('Shop Owner') ? $user->id : null);

            if (! $shopId) {
                // No shop context resolved — skip the scope; controller guards handle 403.
                return;
            }

            $table  = (new static)->getTable();
            $column = defined('static::SHOP_SCOPE_COLUMN')
                ? static::SHOP_SCOPE_COLUMN
                : 'shop_id';

            $builder->where("{$table}.{$column}", $shopId);
        });
    }
}
