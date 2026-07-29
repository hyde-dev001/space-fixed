<?php

namespace Tests\Feature\Logistics;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function settingsPayload(array $overrides = []): array
    {
        return [
            'operating_days' => [1, 2, 3, 4, 5],
            'cutoff_time' => '14:00',
            'blackout_dates' => [],
            'lead_time_days' => 2,
            'morning_start' => '08:00',
            'morning_end' => '12:00',
            'afternoon_start' => '13:00',
            'afternoon_end' => '17:00',
            'coverage_radius_km' => 15,
            'arrival_radius_m' => 100,
            'daily_rider_capacity' => 12,
            'max_delivery_attempts' => 3,
            ...$overrides,
        ];
    }

    public function test_shop_owner_can_read_and_update_its_settings(): void
    {
        $shop = ShopOwner::factory()->create();

        $this->actingAs($shop, 'shop_owner')->getJson('/api/logistics/settings')
            ->assertOk()
            ->assertJsonPath('settings.max_delivery_attempts', 2)
            ->assertJsonPath('settings.arrival_radius_m', 100);
        $this->putJson('/api/logistics/settings', $this->settingsPayload([
            'blackout_dates' => ['2026-12-25'],
        ]))->assertOk()->assertJsonPath('settings.cutoff_time', '14:00');

        $this->assertDatabaseHas('logistics_settings', ['shop_owner_id' => $shop->id, 'max_delivery_attempts' => 3]);
    }

    public function test_arrival_radius_accepts_only_whole_metres_between_50_and_500(): void
    {
        $shop = ShopOwner::factory()->create();
        $this->actingAs($shop, 'shop_owner');

        foreach ([50, 500] as $radius) {
            $this->putJson('/api/logistics/settings', $this->settingsPayload([
                'arrival_radius_m' => $radius,
            ]))->assertOk()->assertJsonPath('settings.arrival_radius_m', $radius);
        }

        foreach ([49, 501, 100.5] as $radius) {
            $this->putJson('/api/logistics/settings', $this->settingsPayload([
                'arrival_radius_m' => $radius,
            ]))->assertUnprocessable()->assertJsonValidationErrors('arrival_radius_m');
        }

        $payload = $this->settingsPayload();
        unset($payload['arrival_radius_m']);
        $this->putJson('/api/logistics/settings', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('arrival_radius_m');
    }

    public function test_settings_can_be_saved_without_blackout_dates(): void
    {
        $shop = ShopOwner::factory()->create();

        $this->actingAs($shop, 'shop_owner')
            ->putJson('/api/logistics/settings', $this->settingsPayload())
            ->assertOk();
    }

    public function test_overlapping_windows_and_invalid_days_are_rejected(): void
    {
        $shop = ShopOwner::factory()->create();

        $this->actingAs($shop, 'shop_owner')
            ->putJson('/api/logistics/settings', $this->settingsPayload([
                'operating_days' => [0],
                'lead_time_days' => 1,
                'morning_end' => '14:00',
                'afternoon_start' => '13:00',
                'max_delivery_attempts' => 2,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['operating_days.0', 'afternoon_start']);
    }

    public function test_past_blackout_dates_are_rejected(): void
    {
        $shop = ShopOwner::factory()->create();

        $this->actingAs($shop, 'shop_owner')
            ->putJson('/api/logistics/settings', $this->settingsPayload([
                'operating_days' => [1],
                'blackout_dates' => ['2001-04-04'],
                'lead_time_days' => 1,
                'max_delivery_attempts' => 2,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('blackout_dates.0');
    }
}
