<?php

namespace Tests\Feature\UserSide;

use App\Models\Product;
use App\Models\ShopOwner;
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
}
