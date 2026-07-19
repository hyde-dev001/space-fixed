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
use Illuminate\Support\Facades\Log;
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

    public function test_coverage_contract_is_independent_of_rider_capacity(): void
    {
        $shop = ShopOwner::factory()->create(['shop_latitude' => 14.5995, 'shop_longitude' => 120.9842]);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'coverage_radius_km' => 1]);
        $service = app(DeliveryScheduleService::class);

        $inside = $service->coverage($shop, 14.60, 120.98);
        $this->assertSame(['available', 'reason', 'distance_km', 'coverage_radius_km'], array_keys($inside));
        $this->assertTrue($inside['available']);
        $this->assertNull($inside['reason']);
        $this->assertSame(1.0, $inside['coverage_radius_km']);

        $outside = $service->coverage($shop, 14.6760, 121.0437);
        $this->assertFalse($outside['available']);
        $this->assertSame('outside_coverage', $outside['reason']);

        $missingCustomerCoordinates = $service->coverage($shop, null, null);
        $this->assertFalse($missingCustomerCoordinates['available']);
        $this->assertSame('address_needs_pin', $missingCustomerCoordinates['reason']);
        $this->assertNull($missingCustomerCoordinates['distance_km']);

        $boundary = $service->coverage($shop, 14.608493, 120.9842);
        $this->assertTrue($boundary['available']);
        $this->assertSame(1.0, $boundary['distance_km']);

        $shop->update(['shop_latitude' => null, 'shop_longitude' => null]);
        $missingShopCoordinates = $service->coverage($shop->fresh(), 14.60, 120.98);
        $this->assertFalse($missingShopCoordinates['available']);
        $this->assertSame('shop_needs_pin', $missingShopCoordinates['reason']);
        $this->assertNull($missingShopCoordinates['distance_km']);
    }

    public function test_coverage_caches_created_settings_on_an_already_loaded_relation(): void
    {
        $shop = ShopOwner::factory()->create([
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ])->load('logisticsSetting');
        $this->assertNull($shop->getRelation('logisticsSetting'));

        app(DeliveryScheduleService::class)->coverage($shop, 14.60, 120.98);

        $this->assertInstanceOf(LogisticsSetting::class, $shop->getRelation('logisticsSetting'));
        app(DeliveryScheduleService::class)->coverage($shop, 14.60, 120.98);
        $this->assertDatabaseCount('logistics_settings', 1);
    }

    public function test_coverage_fails_closed_when_logistics_is_unavailable(): void
    {
        Log::spy();
        $shop = new ShopOwner;
        $shop->setRawAttributes(['id' => 123]);
        $shop->setConnection('missing_connection');

        $result = app(DeliveryScheduleService::class)->coverage($shop, 14.60, 120.98);

        $this->assertSame([
            'available' => false,
            'reason' => 'logistics_unavailable',
            'distance_km' => null,
            'coverage_radius_km' => null,
        ], $result);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Logistics coverage unavailable.'
                && $context['shop_owner_id'] === 123
                && str_contains($context['exception'], 'missing_connection');
        });
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
