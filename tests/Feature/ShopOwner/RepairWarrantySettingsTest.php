<?php

namespace Tests\Feature\ShopOwner;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairWarrantySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_save_repair_warranty_duration_and_unit(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'warranty_enabled' => false,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->putJson('/shop-owner/settings', [
                'repair_warranty_enabled' => true,
                'repair_warranty_duration' => 4,
                'repair_warranty_duration_unit' => 'weeks',
            ])
            ->assertRedirect();

        $owner->refresh();

        $this->assertTrue((bool) $owner->warranty_enabled);
        $this->assertSame(4, (int) $owner->repair_warranty_days);
        $this->assertSame('weeks', (string) $owner->repair_warranty_duration_unit);
    }

    public function test_enabled_warranty_rejects_duration_above_unit_limit(): void
    {
        $owner = ShopOwner::factory()->approved()->create();

        $this->actingAs($owner, 'shop_owner')
            ->putJson('/shop-owner/settings', [
                'repair_warranty_enabled' => true,
                'repair_warranty_duration' => 53,
                'repair_warranty_duration_unit' => 'weeks',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['repair_warranty_duration']);
    }
}
