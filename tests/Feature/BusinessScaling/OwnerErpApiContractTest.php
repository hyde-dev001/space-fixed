<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OwnerErpApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_read_api_wave_exposes_owner_crm_and_logistics_get_contracts(): void
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

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/crm/dashboard-stats')
            ->assertOk()
            ->assertJsonStructure([
                'active_customers',
                'open_conversations',
                'pending_reviews',
                'average_rating',
            ]);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/crm/customers')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/crm/reviews')
            ->assertOk()
            ->assertJsonStructure(['reviews', 'stats']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/logistics/dashboard-stats')
            ->assertOk()
            ->assertJsonStructure(['stats']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/logistics/shipments')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page']);

        $this->actingAs($owner, 'shop_owner')
            ->getJson('/api/shop-owner/erp/logistics/riders')
            ->assertOk()
            ->assertJsonStructure(['riders']);
    }
}
