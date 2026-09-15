<?php

namespace Tests\Feature;

use App\Models\PremiumPlan;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShowroomProductPlacement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ShowroomPlacementTest extends TestCase
{
    use RefreshDatabase;

    private function shopWithSubscription(int $limit = 4): ShopOwner
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        $plan = PremiumPlan::create([
            'plan_code' => 'showroom-' . uniqid(),
            'name' => 'Showroom test',
            'description' => 'Showroom placement test plan',
            'price' => 249,
            'duration_days' => 30,
            'showroom_slot_limit' => $limit,
            'status' => 'active',
        ]);
        ShopOwnerSubscription::create([
            'shop_owner_id' => $shop->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $limit,
            'status' => 'active',
            'paymongo_session_id' => 'session-' . uniqid(),
            'paymongo_payment_id' => 'payment-' . uniqid(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        return $shop;
    }

    private function shoe(ShopOwner $shop, string $name): Product
    {
        return Product::create([
            'shop_owner_id' => $shop->id,
            'name' => $name,
            'description' => 'Placement test shoe',
            'price' => 2500,
            'brand' => 'Test',
            'category' => 'shoes',
            'stock_quantity' => 5,
            'is_active' => true,
            'is_featured' => true,
            'main_image' => '/images/test-shoe.jpg',
        ]);
    }

    public function test_owner_can_move_and_swap_featured_shoes(): void
    {
        $shop = $this->shopWithSubscription();
        $first = $this->shoe($shop, 'First');
        $second = $this->shoe($shop, 'Second');

        $this->actingAs($shop, 'shop_owner')
            ->putJson('/api/showroom/placements', [
                'product_id' => $first->id,
                'from_slot_key' => 'slot-0',
                'to_slot_key' => 'slot-1',
            ])->assertOk();

        $this->actingAs($shop, 'shop_owner')
            ->putJson('/api/showroom/placements', [
                'product_id' => $second->id,
                'from_slot_key' => 'slot-0',
                'to_slot_key' => 'slot-1',
            ])->assertOk();

        $this->assertDatabaseHas('showroom_product_placements', [
            'shop_owner_id' => $shop->id,
            'product_id' => $first->id,
            'slot_key' => 'slot-0',
        ]);
        $this->assertDatabaseHas('showroom_product_placements', [
            'shop_owner_id' => $shop->id,
            'product_id' => $second->id,
            'slot_key' => 'slot-1',
        ]);
    }

    public function test_linked_staff_with_product_permission_can_edit_only_their_shop(): void
    {
        Permission::findOrCreate('access-product-management', 'user');
        $shop = $this->shopWithSubscription();
        $otherShop = $this->shopWithSubscription();
        $shoe = $this->shoe($shop, 'Staff shoe');
        $staff = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        $staff->givePermissionTo('access-product-management');

        $this->actingAs($staff, 'user')
            ->putJson('/api/showroom/placements', [
                'product_id' => $shoe->id,
                'from_slot_key' => 'slot-0',
                'to_slot_key' => 'slot-2',
            ])->assertOk();

        $foreignShoe = $this->shoe($otherShop, 'Foreign shoe');
        $this->actingAs($staff, 'user')
            ->putJson('/api/showroom/placements', [
                'product_id' => $foreignShoe->id,
                'from_slot_key' => 'slot-0',
                'to_slot_key' => 'slot-1',
            ])->assertForbidden();
    }

    public function test_customer_cannot_write_and_invalid_slots_are_rejected(): void
    {
        $shop = $this->shopWithSubscription(1);
        $shoe = $this->shoe($shop, 'Protected shoe');
        $customer = User::factory()->create(['shop_owner_id' => null, 'role' => 'CUSTOMER']);

        $this->actingAs($customer, 'user')
            ->putJson('/api/showroom/placements', [
                'product_id' => $shoe->id,
                'from_slot_key' => 'slot-0',
                'to_slot_key' => 'slot-1',
            ])->assertForbidden();

        $this->actingAs($shop, 'shop_owner')
            ->putJson('/api/showroom/placements', [
                'product_id' => $shoe->id,
                'from_slot_key' => 'slot-0',
                'to_slot_key' => 'slot-1',
            ])->assertStatus(422);
    }

    public function test_placement_uses_the_premium_plan_limit_when_subscription_limit_is_empty(): void
    {
        $shop = $this->shopWithSubscription(2);
        ShopOwnerSubscription::query()
            ->where('shop_owner_id', $shop->id)
            ->update(['showroom_slot_limit' => 0]);
        $shoe = $this->shoe($shop, 'Plan limited shoe');

        $this->actingAs($shop, 'shop_owner')
            ->putJson('/api/showroom/placements', [
                'product_id' => $shoe->id,
                'from_slot_key' => 'slot-0',
                'to_slot_key' => 'slot-1',
            ])->assertOk();
    }

    public function test_showroom_page_exposes_saved_placement_and_owner_edit_capability(): void
    {
        $shop = $this->shopWithSubscription();
        $shoe = $this->shoe($shop, 'Saved shoe');
        ShowroomProductPlacement::create([
            'shop_owner_id' => $shop->id,
            'product_id' => $shoe->id,
            'slot_key' => 'slot-3',
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->get(route('shop-profile.virtual-showroom', ['id' => $shop->id]))
            ->assertInertia(fn ($page) => $page
                ->component('UserSide/Profile/VirtualShowroomPage')
                ->where('shop.can_edit_showroom', true)
                ->where('shop.can_manage_showroom_art', true)
                ->where('shop.showroom_placements.0.product_id', $shoe->id)
                ->where('shop.showroom_placements.0.slot_key', 'slot-3'));
    }

    public function test_linked_staff_can_open_the_showroom_and_customers_are_read_only(): void
    {
        Permission::findOrCreate('access-product-upload-staff', 'user');
        $shop = $this->shopWithSubscription();
        $staff = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        $staff->givePermissionTo('access-product-upload-staff');

        $this->actingAs($staff, 'user')
            ->get(route('shop-profile.virtual-showroom', ['id' => $shop->id]))
            ->assertInertia(fn ($page) => $page
                ->component('UserSide/Profile/VirtualShowroomPage')
                ->where('shop.can_edit_showroom', true)
                ->where('shop.can_manage_showroom_art', false));

        $customer = User::factory()->create(['shop_owner_id' => null, 'role' => 'CUSTOMER']);
        $this->actingAs($customer, 'user')
            ->get(route('shop-profile.virtual-showroom', ['id' => $shop->id]))
            ->assertInertia(fn ($page) => $page
                ->component('UserSide/Profile/VirtualShowroomPage')
                ->where('shop.can_edit_showroom', false)
                ->where('shop.can_manage_showroom_art', false));
    }

    public function test_showroom_page_remains_readable_before_placement_migration_runs(): void
    {
        $shop = $this->shopWithSubscription();

        Schema::dropIfExists('showroom_product_placements');

        $this->actingAs($shop, 'shop_owner')
            ->get(route('shop-profile.virtual-showroom', ['id' => $shop->id]))
            ->assertInertia(fn ($page) => $page
                ->component('UserSide/Profile/VirtualShowroomPage')
                ->where('shop.showroom_placements', [])
                ->where('shop.can_edit_showroom', false)
                ->where('shop.showroom_setup_required', true));
    }

    public function test_shop_owner_can_upload_and_remove_wall_art(): void
    {
        Storage::fake('public');
        $shop = $this->shopWithSubscription();

        $upload = $this->actingAs($shop, 'shop_owner')
            ->post('/api/showroom/wall-art', [
                'wall' => 'left',
                'image' => UploadedFile::fake()->create('left-wall.jpg', 100, 'image/jpeg'),
            ])
            ->assertOk()
            ->assertJsonPath('wall', 'left');

        $path = $shop->fresh()->showroom_left_wall_art_path;
        self::assertIsString($path);
        Storage::disk('public')->assertExists($path);
        $upload->assertJsonPath('url', asset('storage/' . $path));

        $this->actingAs($shop, 'shop_owner')
            ->deleteJson('/api/showroom/wall-art/left')
            ->assertOk()
            ->assertJsonPath('wall', 'left');

        self::assertNull($shop->fresh()->showroom_left_wall_art_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_staff_cannot_manage_wall_art(): void
    {
        Permission::findOrCreate('access-product-management', 'user');
        Storage::fake('public');
        $shop = $this->shopWithSubscription();
        $staff = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        $staff->givePermissionTo('access-product-management');

        $this->actingAs($staff, 'user')
            ->post('/api/showroom/wall-art', [
                'wall' => 'left',
                'image' => UploadedFile::fake()->create('left-wall.jpg', 100, 'image/jpeg'),
            ])
            ->assertForbidden();
    }
}
