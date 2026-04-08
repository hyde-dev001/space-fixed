<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RetailPosBusinessTypeAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function retail_only_user_cannot_access_repair_pos_endpoint(): void
    {
        $retailOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        /** @var User $user */
        $user = User::factory()->create([
            'shop_owner_id' => $retailOwner->id,
        ]);

        $this->actingAs($user, 'user');

        $response = $this->postJson('/api/repair-pos/checkout', []);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'BUSINESS_TYPE_FORBIDDEN_MODE');
    }

    #[Test]
    public function retail_only_user_can_access_retail_pos_products(): void
    {
        $retailOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        Product::create([
            'shop_owner_id' => $retailOwner->id,
            'name' => 'Walk-in Sneaker',
            'slug' => 'walk-in-sneaker',
            'price' => 1299,
            'stock_quantity' => 8,
            'is_active' => true,
        ]);

        Product::create([
            'shop_owner_id' => ShopOwner::factory()->approved()->create(['business_type' => 'retail'])->id,
            'name' => 'Other Shop Shoe',
            'slug' => 'other-shop-shoe',
            'price' => 999,
            'stock_quantity' => 4,
            'is_active' => true,
        ]);

        /** @var User $user */
        $user = User::factory()->create([
            'shop_owner_id' => $retailOwner->id,
        ]);

        $this->actingAs($user, 'user');

        $response = $this->getJson('/api/retail-pos/products');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'walk-in-sneaker');
    }
}
