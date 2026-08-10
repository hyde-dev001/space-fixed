<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class OwnerErpPageContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_contract_contains_owner_safe_modules_navigation_and_urls(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/workspace')
            ->assertInertia(fn (Assert $page) => $page
                ->component('ERP/Workspace', false)
                ->has('enabledModules')
                ->has('unavailableModules', 8)
                ->has('navigationGroups', 1)
                ->where('navigationGroups.0.pages.0.routeName', 'shop-owner.erp.workspace')
                ->where('urls.portal', route('shop-owner.dashboard'))
                ->where('urls.settings', route('shop-owner.settings'))
            );
    }

    public function test_workspace_ignores_a_client_supplied_shop_identifier(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);
        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'repair',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/erp/workspace?shop_owner_id='.$otherOwner->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenantOwnerId', fn (int $tenantOwnerId): bool => $tenantOwnerId === $owner->id
                    && $tenantOwnerId !== $otherOwner->id)
            );
    }
}
