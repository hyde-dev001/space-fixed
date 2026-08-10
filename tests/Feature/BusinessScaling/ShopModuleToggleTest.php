<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessScaling;

use App\Actions\ShopOwner\ToggleShopOwnerModule;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ShopModuleToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_owner_can_toggle_an_eligible_module_and_receives_authoritative_state(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);

        $response = $this->actingAs($owner, 'shop_owner')
            ->patchJson(route('shop-owner.modules.update', 'retail_operations'), ['enabled' => false]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('module_key', 'retail_operations')
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('states.retail_operations.enabled', false)
            ->assertJsonPath('states.retail_operations.accessible', false);
        $this->assertDatabaseHas('shop_owner_modules', [
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => 0,
        ]);
        $this->assertSame(1, DB::table('activity_log')->where('description', 'shop_owner_module_toggled')->count());
    }

    public function test_toggle_is_idempotent_and_missing_or_unknown_modules_are_rejected(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->patchJson(route('shop-owner.modules.update', 'retail_operations'), ['enabled' => true])
            ->assertOk();
        $this->assertSame(0, DB::table('activity_log')->where('description', 'shop_owner_module_toggled')->count());

        $this->actingAs($owner, 'shop_owner')
            ->patchJson(route('shop-owner.modules.update', 'inventory'), ['enabled' => true])
            ->assertStatus(422);
        $this->actingAs($owner, 'shop_owner')
            ->patchJson(route('shop-owner.modules.update', 'not-a-module'), ['enabled' => true])
            ->assertStatus(422);
    }

    public function test_ineligible_suspended_and_cross_tenant_toggle_attempts_cannot_change_state(): void
    {
        $individual = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $individual->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);

        $this->actingAs($individual, 'shop_owner')
            ->patchJson(route('shop-owner.modules.update', 'hr_employees'), ['enabled' => true])
            ->assertStatus(422);

        $suspended = ShopOwner::factory()->create(['status' => 'suspended']);
        $this->actingAs($suspended, 'shop_owner')
            ->patchJson(route('shop-owner.modules.update', 'retail_operations'), ['enabled' => false])
            ->assertForbidden();

        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        $this->actingAs($otherOwner, 'shop_owner')
            ->patchJson(route('shop-owner.modules.update', 'retail_operations'), ['enabled' => false])
            ->assertStatus(422);

        $this->assertTrue((bool) $individual->modules()->where('module_key', 'retail_operations')->value('enabled'));
    }

    public function test_core_settings_remain_reachable_when_all_module_rows_are_disabled(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => false,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->get(route('shop-owner.settings'))
            ->assertOk();
    }

    public function test_stale_owner_and_module_models_are_reloaded_before_the_toggle(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        $module = ShopOwnerModule::factory()->create([
            'shop_owner_id' => $owner->id,
            'module_key' => 'retail_operations',
            'enabled' => true,
        ]);
        $staleOwner = $owner->fresh();
        $staleModule = $module->fresh();
        $module->update(['enabled' => false]);

        $result = app(ToggleShopOwnerModule::class)->handle($staleOwner, 'retail_operations', true);

        $this->assertTrue($result['enabled']);
        $this->assertTrue((bool) $staleModule->fresh()->enabled);
    }
}
