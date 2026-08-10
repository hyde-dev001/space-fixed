<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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
                ->where('ownerMode', true)
                ->where('shopModuleEnforcementEnabled', true)
                ->has('moduleStates')
                ->where('erpCapabilities', fn (Collection $capabilities): bool => $capabilities->has(
                    'GET:shop-owner.erp.workspace',
                ))
                ->has('enabledModules')
                ->has('unavailableModules', 8)
                ->has('navigationGroups', 1)
                ->where('navigationGroups.0.pages.0.routeName', 'shop-owner.erp.workspace')
                ->where('urls.portal', route('shop-owner.dashboard'))
                ->where('urls.settings', route('shop-owner.settings'))
                ->where('erpUrls.workspace', route('shop-owner.erp.workspace'))
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

    public function test_first_read_wave_exposes_owner_crm_and_logistics_pages_with_shared_components(): void
    {
        config([
            'shop_modules.owner_erp_workspace_enabled' => true,
            'shop_modules.enforcement_enabled' => true,
        ]);
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        foreach (['crm', 'logistics'] as $moduleKey) {
            ShopOwnerModule::factory()->create([
                'shop_owner_id' => $owner->id,
                'module_key' => $moduleKey,
                'enabled' => true,
            ]);
        }

        $pages = [
            ['/shop-owner/erp/crm', 'ERP/CRM/CRMDashboard'],
            ['/shop-owner/erp/crm/customers', 'ERP/CRM/Customers'],
            ['/shop-owner/erp/crm/customer-reviews', 'ERP/CRM/CustomerReviews'],
            ['/shop-owner/erp/logistics', 'ERP/Logistics/Dashboard'],
            ['/shop-owner/erp/logistics/shipments', 'ERP/Logistics/Shipments'],
            ['/shop-owner/erp/logistics/riders', 'ERP/Logistics/Riders'],
        ];

        foreach ($pages as [$uri, $component]) {
            $this->actingAs($owner, 'shop_owner')
                ->get($uri)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component, false));
        }
    }
}
