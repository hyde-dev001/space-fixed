<?php

namespace Tests\Feature\ShopOwner;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceGraceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_grace_defaults_to_zero_in_business_settings(): void
    {
        $owner = ShopOwner::factory()->approved()->create();

        $this->actingAs($owner, 'shop_owner')
            ->get('/shop-owner/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShopOwner/Settings/shopSetting')
                ->where('shop_settings.late_grace_period_minutes', 0));
    }

    public function test_shop_owner_can_save_grace_period_boundaries(): void
    {
        $owner = ShopOwner::factory()->approved()->create();

        foreach ([0, 15, 120] as $minutes) {
            $this->actingAs($owner, 'shop_owner')
                ->putJson('/shop-owner/settings', [
                    'late_grace_period_minutes' => $minutes,
                ])
                ->assertRedirect();

            $this->assertDatabaseHas('shop_owners', [
                'id' => $owner->id,
                'late_grace_period_minutes' => $minutes,
            ]);
        }
    }

    public function test_shop_owner_cannot_save_grace_period_outside_allowed_range(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'late_grace_period_minutes' => 15,
        ]);

        foreach ([-1, 121, 1.5] as $minutes) {
            $this->actingAs($owner, 'shop_owner')
                ->putJson('/shop-owner/settings', [
                    'late_grace_period_minutes' => $minutes,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['late_grace_period_minutes']);

            $this->assertDatabaseHas('shop_owners', [
                'id' => $owner->id,
                'late_grace_period_minutes' => 15,
            ]);
        }
    }
}
