<?php

namespace Tests\Feature\UserSide;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CartPriceRefreshTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cart_index_returns_latest_product_price_and_refreshes_snapshot_fields(): void
    {
        $shopOwner = ShopOwner::factory()->create([
            'business_name' => 'Price Sync Shop',
            'status' => 'approved',
            'business_type' => 'retail',
        ]);

        $product = Product::create([
            'shop_owner_id' => $shopOwner->id,
            'name' => 'Velocity Runner',
            'slug' => 'velocity-runner-cart-price-sync',
            'price' => 1200,
            'stock_quantity' => 7,
            'is_active' => true,
            'main_image' => 'products/velocity-runner-latest.jpg',
        ]);

        /** @var User $customer */
        $customer = User::factory()->create([
            'shop_owner_id' => null,
        ]);

        $cartItem = CartItem::create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'size' => 'US 9',
            'quantity' => 2,
            'price' => 900,
            'image' => null,
            'product_name' => 'Old Product Name',
            'stock_quantity' => 3,
            'options' => ['color' => 'Black'],
        ]);

        $response = $this->actingAs($customer, 'user')
            ->getJson('/api/cart');

        $response->assertOk()
            ->assertJsonPath('items.0.id', $cartItem->id)
            ->assertJsonPath('items.0.price', 1200)
            ->assertJsonPath('items.0.stock_quantity', 7)
            ->assertJsonPath('items.0.name', 'Velocity Runner')
            ->assertJsonPath('items.0.image', 'products/velocity-runner-latest.jpg');

        $this->assertDatabaseHas('cart_items', [
            'id' => $cartItem->id,
            'price' => 1200,
            'stock_quantity' => 7,
            'product_name' => 'Velocity Runner',
            'image' => 'products/velocity-runner-latest.jpg',
        ]);
    }
}
