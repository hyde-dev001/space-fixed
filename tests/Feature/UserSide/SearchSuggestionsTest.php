<?php

namespace Tests\Feature\UserSide;

use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_query_returns_newest_active_products_from_approved_shops(): void
    {
        $approvedShop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'business_name' => 'Approved Kicks',
        ]);
        $pendingShop = ShopOwner::factory()->pending()->create([
            'business_type' => 'retail',
            'business_name' => 'Pending Kicks',
        ]);

        $older = Product::create([
            'shop_owner_id' => $approvedShop->id,
            'name' => 'Older Runner',
            'slug' => 'older-runner-search-suggestion',
            'price' => 1800,
            'compare_at_price' => 2200,
            'stock_quantity' => 4,
            'is_active' => true,
            'main_image' => 'products/older-runner.jpg',
        ]);
        $newer = Product::create([
            'shop_owner_id' => $approvedShop->id,
            'name' => 'Newest Runner',
            'slug' => 'newest-runner-search-suggestion',
            'price' => 2400,
            'compare_at_price' => 2800,
            'stock_quantity' => 6,
            'is_active' => true,
            'main_image' => 'products/newest-runner.jpg',
        ]);
        Product::create([
            'shop_owner_id' => $approvedShop->id,
            'name' => 'Inactive Runner',
            'slug' => 'inactive-runner-search-suggestion',
            'price' => 1600,
            'stock_quantity' => 6,
            'is_active' => false,
        ]);
        Product::create([
            'shop_owner_id' => $pendingShop->id,
            'name' => 'Pending Runner',
            'slug' => 'pending-runner-search-suggestion',
            'price' => 1700,
            'stock_quantity' => 6,
        ]);

        $older->forceFill(['created_at' => now()->subDay()])->saveQuietly();
        $newer->forceFill(['created_at' => now()])->saveQuietly();

        $response = $this->getJson('/api/search/suggestions?query=');

        $response->assertOk()
            ->assertJsonPath('query', '')
            ->assertJsonCount(2, 'products')
            ->assertJsonPath('products.0.name', 'Newest Runner')
            ->assertJsonPath('products.0.shop_name', 'Approved Kicks')
            ->assertJsonPath('products.0.slug', 'newest-runner-search-suggestion')
            ->assertJsonPath('shops', [])
            ->assertJsonPath('categories', []);

        $this->assertSame(2400.0, (float) $response->json('products.0.price'));
        $this->assertSame(2800.0, (float) $response->json('products.0.compare_at_price'));
    }

    public function test_shop_query_returns_all_approved_business_types_and_excludes_pending_shops(): void
    {
        foreach (['retail', 'repair', 'both'] as $businessType) {
            ShopOwner::factory()->approved()->create([
                'business_type' => $businessType,
                'business_name' => ucfirst($businessType) . ' Shop',
            ]);
        }

        ShopOwner::factory()->pending()->create([
            'business_type' => 'retail',
            'business_name' => 'Pending Shop',
        ]);

        $response = $this->getJson('/api/search/suggestions?query=shop');

        $response->assertOk()->assertJsonCount(3, 'shops');

        $shopNames = collect($response->json('shops'))->pluck('name')->all();

        $this->assertSame([
            'Both Shop',
            'Repair Shop',
            'Retail Shop',
        ], $shopNames);
        $this->assertNotContains('Pending Shop', $shopNames);
    }

    public function test_showroom_query_returns_approved_shops_with_active_showroom_entitlement(): void
    {
        $showroomShop = ShopOwner::factory()->approved()->create([
            'business_type' => 'both',
            'business_name' => 'Showroom Shop',
        ]);
        ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'business_name' => 'Standard Shop',
        ]);

        ShopOwnerSubscription::create([
            'shop_owner_id' => $showroomShop->id,
            'premium_plan_id' => null,
            'plan_code' => 'test-showroom',
            'showroom_slot_limit' => 12,
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);

        $response = $this->getJson('/api/search/suggestions?query=showroom');

        $response->assertOk()
            ->assertJsonCount(1, 'shops')
            ->assertJsonPath('shops.0.name', 'Showroom Shop')
            ->assertJsonPath(
                'shops.0.virtual_showroom_url',
                route('shop-profile.virtual-showroom', ['id' => $showroomShop->id]),
            );
    }
}
