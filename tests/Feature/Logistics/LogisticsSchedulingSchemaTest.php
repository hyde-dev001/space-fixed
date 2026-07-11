<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LogisticsSchedulingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduling_policy_and_results_are_persisted(): void
    {
        $this->assertTrue(Schema::hasTable('logistics_settings'));
        $this->assertTrue(Schema::hasColumns('logistics_settings', [
            'shop_owner_id', 'operating_days', 'cutoff_time', 'blackout_dates',
            'lead_time_days', 'morning_start', 'morning_end', 'afternoon_start',
            'afternoon_end', 'coverage_radius_km', 'daily_rider_capacity',
            'max_delivery_attempts',
        ]));
        $this->assertTrue(Schema::hasColumns('user_addresses', ['latitude', 'longitude', 'delivery_instructions']));
        $this->assertTrue(Schema::hasColumns('shipment_legs', [
            'scheduled_delivery_date', 'delivery_window', 'schedule_status',
            'schedule_override_reason', 'distance_km', 'estimated_at',
        ]));

        $shop = ShopOwner::factory()->create();
        $setting = LogisticsSetting::create(['shop_owner_id' => $shop->id]);

        $this->assertSame([1, 2, 3, 4, 5, 6], $setting->operating_days);
        $this->assertSame([], $setting->blackout_dates);
        $this->assertSame(2, $setting->max_delivery_attempts);
        $this->assertInstanceOf(LogisticsSetting::class, $shop->logisticsSetting);
        $this->assertSame('12.34567800', (new UserAddress(['latitude' => 12.345678]))->latitude);
        $this->assertSame('2026-07-12', (new ShipmentLeg(['scheduled_delivery_date' => '2026-07-12']))->scheduled_delivery_date->toDateString());
    }
}
