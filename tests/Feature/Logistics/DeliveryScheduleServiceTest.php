<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Services\Logistics\DeliveryScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_schedules_after_cutoff_across_closed_and_blackout_days(): void
    {
        $shop = ShopOwner::factory()->create(['shop_latitude' => 14.5995, 'shop_longitude' => 120.9842]);
        LogisticsSetting::create([
            'shop_owner_id' => $shop->id,
            'operating_days' => [1, 2, 3, 4, 5, 6],
            'blackout_dates' => ['2026-07-13'],
            'lead_time_days' => 1,
            'cutoff_time' => '15:00',
        ]);
        RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available']);

        $result = app(DeliveryScheduleService::class)->estimate(
            $shop,
            CarbonImmutable::parse('2026-07-11 08:00:00', 'UTC'), // 16:00 Saturday Manila
            14.60,
            120.98,
        );

        $this->assertSame('scheduled', $result['schedule_status']);
        $this->assertSame('2026-07-15', $result['scheduled_delivery_date']);
        $this->assertSame('morning', $result['delivery_window']);
    }

    public function test_it_flags_missing_coordinates_and_outside_coverage(): void
    {
        $shop = ShopOwner::factory()->create(['shop_latitude' => 14.5995, 'shop_longitude' => 120.9842]);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'coverage_radius_km' => 1]);
        RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available']);
        $service = app(DeliveryScheduleService::class);

        $this->assertSame('needs_coordinates', $service->estimate($shop, now(), null, null)['schedule_status']);
        $this->assertSame('outside_coverage', $service->estimate($shop, now(), 14.6760, 121.0437)['schedule_status']);

        $shop->update(['shop_latitude' => null, 'shop_longitude' => null]);
        $this->assertSame('needs_shop_coordinates', $service->estimate($shop->fresh(), now(), 14.60, 120.98)['schedule_status']);
    }

    public function test_daily_capacity_is_shared_across_windows_and_days(): void
    {
        $shop = ShopOwner::factory()->create(['shop_latitude' => 14.5995, 'shop_longitude' => 120.9842]);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'lead_time_days' => 0, 'daily_rider_capacity' => 2]);
        RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'scheduled_delivery_date' => '2026-07-13', 'delivery_window' => 'morning', 'schedule_status' => 'scheduled']);
        $service = app(DeliveryScheduleService::class);
        $ready = CarbonImmutable::parse('2026-07-13 01:00:00', 'UTC');

        $this->assertSame('afternoon', $service->estimate($shop, $ready, 14.60, 120.98)['delivery_window']);

        ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'scheduled_delivery_date' => '2026-07-13', 'delivery_window' => 'afternoon', 'schedule_status' => 'scheduled']);
        $this->assertSame('2026-07-14', $service->estimate($shop, $ready, 14.60, 120.98)['scheduled_delivery_date']);
    }

    public function test_no_available_rider_requires_capacity_review(): void
    {
        $shop = ShopOwner::factory()->create(['shop_latitude' => 14.5995, 'shop_longitude' => 120.9842]);

        $this->assertSame('needs_capacity', app(DeliveryScheduleService::class)
            ->estimate($shop, now(), 14.60, 120.98)['schedule_status']);
    }
}
