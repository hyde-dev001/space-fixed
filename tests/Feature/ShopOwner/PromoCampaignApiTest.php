<?php

namespace Tests\Feature\ShopOwner;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PromoCampaignApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_promo_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('promo_campaigns'));
        $this->assertTrue(Schema::hasTable('promo_campaign_products'));
        $this->assertTrue(Schema::hasTable('voucher_claims'));
    }

    public function test_shop_owner_can_create_and_list_promos(): void
    {
        $owner = ShopOwner::factory()->create([
            'business_type' => 'retail',
            'status' => 'approved',
        ]);

        $this->actingAs($owner, 'shop_owner');

        $payload = [
            'kind' => 'voucher',
            'scope' => 'shop_wide',
            'name' => 'Weekend Drop',
            'code' => 'WEEKEND10',
            'discount_mode' => 'percentage',
            'value' => 10,
            'min_spend' => 2000,
            'usage_limit' => 100,
            'start_at' => now()->subHour()->toISOString(),
            'end_at' => now()->addDays(7)->toISOString(),
        ];

        $this->postJson('/api/shop-owner/promos', $payload)
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->getJson('/api/shop-owner/promos')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }
}
