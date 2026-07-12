<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Services\Logistics\BatchSuggestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_eligible_riders_and_nearest_first_pending_legs(): void
    {
        $shop = ShopOwner::factory()->create(['shop_latitude' => 14.5995, 'shop_longitude' => 120.9842]);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'daily_rider_capacity' => 3]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available',
            'work_days' => [3], 'leave_dates' => [],
        ]);
        RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'inactive',
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $far = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning', 'schedule_status' => 'scheduled',
            'destination_snapshot' => ['latitude' => 14.70, 'longitude' => 121.00],
        ]);
        $near = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning', 'schedule_status' => 'scheduled',
            'destination_snapshot' => ['latitude' => 14.60, 'longitude' => 120.98],
        ]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'afternoon', 'schedule_status' => 'scheduled',
        ]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning', 'schedule_status' => 'scheduled',
            'destination_snapshot' => ['address' => 'No coordinates'],
        ]);

        $result = app(BatchSuggestionService::class)->suggest($shop, CarbonImmutable::parse('2026-07-15'), 'morning');

        $this->assertCount(1, $result);
        $this->assertSame($rider->id, $result[0]['rider_profile_id']);
        $this->assertSame([$near->id, $far->id], $result[0]['leg_ids']);
        $this->assertSame(3, $result[0]['capacity']);
    }
}
