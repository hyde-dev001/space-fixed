<?php

namespace Tests\Feature\ShopOwner;

use App\Models\Logistics\RiderProfile;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CodSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_retail_owner_can_update_cod_settings(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'company',
            'cod_enabled' => true,
            'cod_order_threshold' => 5000,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->putJson('/shop-owner/settings', [
                'cod_enabled' => false,
                'cod_order_threshold' => 7500,
            ])
            ->assertRedirect();

        $owner->refresh();

        $this->assertFalse((bool) $owner->cod_enabled);
        $this->assertSame('7500.00', (string) $owner->cod_order_threshold);
    }

    public function test_non_retail_owner_cannot_change_cod_settings(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'cod_enabled' => true,
            'cod_order_threshold' => 5000,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->putJson('/shop-owner/settings', [
                'cod_enabled' => false,
                'cod_order_threshold' => 7500,
            ])
            ->assertRedirect();

        $owner->refresh();

        $this->assertTrue((bool) $owner->cod_enabled);
        $this->assertSame('5000.00', (string) $owner->cod_order_threshold);
    }

    public function test_cod_threshold_must_be_positive(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->putJson('/shop-owner/settings', [
                'cod_enabled' => true,
                'cod_order_threshold' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cod_order_threshold']);
    }

    public function test_cod_cannot_be_enabled_before_logistics_is_ready(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'company',
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->putJson('/shop-owner/settings', [
                'cod_enabled' => true,
                'cod_order_threshold' => 5000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cod_enabled']);

        $this->assertFalse((bool) $owner->fresh()->cod_enabled);
    }

    public function test_cod_can_be_enabled_after_logistics_staff_is_ready(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'registration_type' => 'company',
        ]);
        ShopOwnerModule::create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'logistics',
            'enabled' => true,
        ]);
        $dispatcher = User::factory()->create([
            'shop_owner_id' => $owner->id,
            'status' => 'active',
        ]);
        $dispatcher->givePermissionTo(Permission::findOrCreate('assign-logistics-deliveries', 'user'));
        RiderProfile::factory()->create([
            'shop_owner_id' => $owner->id,
            'active' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->putJson('/shop-owner/settings', [
                'cod_enabled' => true,
                'cod_order_threshold' => 5000,
            ])
            ->assertRedirect();

        $this->assertTrue((bool) $owner->fresh()->cod_enabled);
    }
}
