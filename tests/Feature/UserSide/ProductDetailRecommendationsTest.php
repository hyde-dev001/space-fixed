<?php

namespace Tests\Feature\UserSide;

use App\Models\Product;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductDetailRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_ranked_active_products_from_approved_shops(): void
    {
        $approvedShop = ShopOwner::factory()->approved()->create();
        $rejectedShop = ShopOwner::factory()->rejected()->create();
        $current = $this->createProduct($approvedShop, [
            'name' => 'Current Runner',
            'slug' => 'current-runner',
            'brand' => 'SoleSpace',
            'category' => 'running',
        ]);
        $sameCategory = $this->createProduct($approvedShop, [
            'name' => 'Category Match',
            'slug' => 'category-match',
            'brand' => 'Another Brand',
            'category' => 'running',
            'created_at' => now()->subDays(3),
        ]);
        $sameBrand = $this->createProduct($approvedShop, [
            'name' => 'Brand Match',
            'slug' => 'brand-match',
            'brand' => 'SoleSpace',
            'category' => 'casual',
            'created_at' => now()->subDays(2),
        ]);
        $newestFallback = $this->createProduct($approvedShop, [
            'name' => 'Newest Fallback',
            'slug' => 'newest-fallback',
            'brand' => 'Other',
            'category' => 'accessories',
            'created_at' => now(),
        ]);

        $this->createProduct($approvedShop, [
            'slug' => 'inactive-category-match',
            'category' => 'running',
            'is_active' => false,
        ]);
        $this->createProduct($rejectedShop, [
            'slug' => 'rejected-shop-category-match',
            'category' => 'running',
        ]);

        $this->get(route('products.show', ['slug' => $current->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('UserSide/Products/ProductShow')
                ->has('relatedProducts', 3)
                ->where('relatedProducts.0.id', $sameCategory->id)
                ->where('relatedProducts.1.id', $sameBrand->id)
                ->where('relatedProducts.2.id', $newestFallback->id)
                ->where('relatedProducts.0.name', 'Category Match')
                ->where('relatedProducts.0.url', route('products.show', ['slug' => $sameCategory->slug]))
                ->has('relatedProducts.0', fn (Assert $product) => $product
                    ->hasAll(['id', 'name', 'url', 'image', 'price', 'compare_at_price', 'brand', 'category'])
                    ->missingAll(['shop_owner_id', 'description', 'stock_quantity'])
                )
            );
    }

    #[Test]
    public function it_caps_recommendations_at_eight_unique_products(): void
    {
        $approvedShop = ShopOwner::factory()->approved()->create();
        $current = $this->createProduct($approvedShop, [
            'slug' => 'recommendation-cap-current',
        ]);

        foreach (range(1, 10) as $index) {
            $this->createProduct($approvedShop, [
                'name' => "Recommended {$index}",
                'slug' => "recommended-{$index}",
                'created_at' => now()->subMinutes($index),
            ]);
        }

        $response = $this->get(route('products.show', ['slug' => $current->slug]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page->has('relatedProducts', 8));

        $ids = collect($response->viewData('page')['props']['relatedProducts'])->pluck('id');

        $this->assertCount(8, $ids->unique());
        $this->assertNotContains($current->id, $ids);
    }

    #[Test]
    public function it_exposes_product_gallery_images_as_url_strings(): void
    {
        $approvedShop = ShopOwner::factory()->approved()->create();
        $current = $this->createProduct($approvedShop, [
            'slug' => 'gallery-url-contract',
            'main_image' => 'https://example.com/product.jpg',
        ]);

        $this->get(route('products.show', ['slug' => $current->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.images.0', 'https://example.com/product.jpg')
            );
    }

    #[Test]
    public function it_exposes_active_product_slugs_for_recent_history_validation(): void
    {
        $approvedShop = ShopOwner::factory()->approved()->create();
        $current = $this->createProduct($approvedShop, [
            'slug' => 'history-current-product',
        ]);
        $activeProduct = $this->createProduct($approvedShop, [
            'slug' => 'history-active-product',
        ]);
        $inactiveProduct = $this->createProduct($approvedShop, [
            'slug' => 'history-inactive-product',
            'is_active' => false,
        ]);

        $response = $this->get(route('products.show', ['slug' => $current->slug]));

        $response->assertOk();

        $availableSlugs = collect($response->viewData('page')['props']['availableProductSlugs'])
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            [$activeProduct->slug, $current->slug],
            $availableSlugs,
        );
        $this->assertNotContains($inactiveProduct->slug, $availableSlugs);
    }

    private function createProduct(ShopOwner $shopOwner, array $overrides = []): Product
    {
        static $sequence = 0;

        $sequence++;

        return Product::create(array_merge([
            'shop_owner_id' => $shopOwner->id,
            'name' => "Product {$sequence}",
            'slug' => "product-detail-recommendation-{$sequence}",
            'price' => 2500,
            'compare_at_price' => 3000,
            'brand' => 'Generic',
            'category' => 'casual',
            'stock_quantity' => 5,
            'is_active' => true,
            'main_image' => "products/recommendation-{$sequence}.jpg",
        ], $overrides));
    }
}
