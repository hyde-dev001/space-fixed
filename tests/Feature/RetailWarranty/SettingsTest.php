<?php

namespace Tests\Feature\RetailWarranty;

use App\Models\ShopOwner;
use App\Models\ShopRetailWarrantySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public static function shops(): array
    {
        return [['individual', 'retail', 5, 'days'], ['individual', 'both', 1, 'years'], ['company', 'retail', 2, 'weeks'], ['company', 'both', 3, 'months']];
    }

    #[DataProvider('shops')]
    public function test_owner_can_configure_independent_retail_policy_without_moving_cutover(string $registration, string $business, int $duration, string $unit): void
    {
        $owner = ShopOwner::factory()->approved()->create(['registration_type' => $registration, 'business_type' => $business, 'repair_warranty_days' => 9]);
        $payload = ['enabled' => true, 'title' => 'Shoe Coverage', 'duration_value' => $duration, 'duration_unit' => $unit,
            'description' => 'Shop defined policy', 'terms' => 'Custom evaluation terms', 'exclusions' => 'Wear', 'instructions' => 'Visit our store'];
        $this->actingAs($owner, 'shop_owner')->putJson('/shop-owner/settings/retail-warranty', $payload)->assertOk();
        $setting = ShopRetailWarrantySetting::where('shop_owner_id', $owner->id)->firstOrFail();
        $cutover = $setting->eligible_orders_from->toIso8601String();
        $this->assertTrue($setting->enabled);
        $this->assertSame($unit, $setting->duration_unit);
        $this->assertSame('Custom evaluation terms', $setting->terms);
        $repairDays = $owner->repair_warranty_days;
        $this->travel(2)->days();
        $this->putJson('/shop-owner/settings/retail-warranty', array_replace($payload, ['enabled' => false]))->assertOk();
        $this->putJson('/shop-owner/settings/retail-warranty', array_replace($payload, ['duration_value' => 1]))->assertOk();
        $this->assertSame($cutover, $setting->fresh()->eligible_orders_from->toIso8601String());
        $this->assertSame($repairDays, $owner->fresh()->repair_warranty_days);
    }

    public function test_repair_only_shop_and_invalid_duration_are_rejected(): void
    {
        $owner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $payload = ['enabled' => true, 'title' => 'Product Warranty', 'duration_value' => 1, 'duration_unit' => 'years', 'terms' => 'Custom terms'];
        $this->actingAs($owner, 'shop_owner')->putJson('/shop-owner/settings/retail-warranty', $payload)->assertForbidden();
        $owner->update(['business_type' => 'retail']);
        $this->putJson('/shop-owner/settings/retail-warranty', array_replace($payload, ['duration_value' => 0]))->assertUnprocessable();
        $this->assertDatabaseCount('shop_retail_warranty_settings', 0);
    }

    public function test_malformed_units_are_validation_errors_and_disabled_empty_title_uses_safe_default(): void
    {
        $owner = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        $payload = ['enabled' => false, 'title' => null, 'duration_value' => 5, 'duration_unit' => 'days'];
        $this->actingAs($owner, 'shop_owner')->putJson('/shop-owner/settings/retail-warranty', array_replace($payload, ['duration_unit' => ['years']]))->assertUnprocessable();
        $this->putJson('/shop-owner/settings/retail-warranty', $payload)->assertOk();
        $this->assertDatabaseHas('shop_retail_warranty_settings', ['shop_owner_id' => $owner->id, 'enabled' => false, 'title' => 'Product Warranty']);
    }
}
