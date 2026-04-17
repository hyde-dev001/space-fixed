<?php

namespace Tests\Feature\Policies;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShopPolicySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_tables_and_foreign_keys_exist(): void
    {
        $this->assertTrue(Schema::hasTable('shop_policy_versions'));
        $this->assertTrue(Schema::hasTable('policy_acceptances'));
        $this->assertTrue(Schema::hasColumn('orders', 'accepted_shop_policy_version_id'));
        $this->assertTrue(Schema::hasColumn('repair_requests', 'accepted_shop_policy_version_id'));
    }

    public function test_shop_owner_can_save_policy_draft_and_publish_new_version(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
            'registration_type' => 'individual',
        ]);

        $this->actingAs($shopOwner, 'shop_owner')
            ->putJson('/shop-owner/settings/policies/draft', [
                'policy_sections_json' => [
                    'refund_payment_terms' => 'Draft refund terms',
                    'repair_service_terms' => 'Draft repair terms',
                    'retail_terms' => 'Draft retail terms',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($shopOwner, 'shop_owner')
            ->postJson('/shop-owner/settings/policies/publish')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('shop_policy_versions', [
            'shop_owner_id' => $shopOwner->id,
            'status' => 'published',
            'version_number' => 1,
        ]);
    }
}
