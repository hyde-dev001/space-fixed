<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\DeliveryBatch;
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
        $shop = ShopOwner::factory()->create([
            'business_type' => 'retail',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'daily_rider_capacity' => 3]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available',
            'work_days' => [3], 'leave_dates' => [],
        ]);
        RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'inactive',
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order']);
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

    public function test_it_partitions_modules_and_discards_single_stop_suggestions(): void
    {
        $shop = ShopOwner::factory()->create([
            'business_type' => 'both',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'daily_rider_capacity' => 3]);
        RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'active' => true,
            'availability_status' => 'available',
            'work_days' => [3],
            'leave_dates' => [],
        ]);
        $retailShipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order']);
        $repairShipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'repair_request']);
        $retailLegs = ShipmentLeg::factory()->count(2)->create([
            'shipment_id' => $retailShipment->id,
            'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'destination_snapshot' => ['latitude' => 14.60, 'longitude' => 120.98],
        ]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $repairShipment->id,
            'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'destination_snapshot' => ['latitude' => 14.61, 'longitude' => 120.99],
        ]);

        $service = app(BatchSuggestionService::class);
        $all = $service->suggest($shop, CarbonImmutable::parse('2026-07-15'), 'morning');
        $repairOnly = $service->suggest($shop, CarbonImmutable::parse('2026-07-15'), 'morning', 'repair');

        $this->assertCount(1, $all);
        $this->assertEqualsCanonicalizing($retailLegs->pluck('id')->all(), $all[0]['leg_ids']);
        $this->assertSame('retail', $all[0]['module']);
        $this->assertSame([], $repairOnly);
    }

    public function test_it_excludes_riders_at_daily_capacity_and_reports_current_workload(): void
    {
        $shop = ShopOwner::factory()->create([
            'business_type' => 'retail',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'daily_rider_capacity' => 2]);
        $busyRider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'active' => true,
            'availability_status' => 'available',
            'work_days' => [3],
            'leave_dates' => [],
            'daily_capacity' => 2,
        ]);
        $availableRider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'active' => true,
            'availability_status' => 'available',
            'work_days' => [3],
            'leave_dates' => [],
            'daily_capacity' => 2,
        ]);
        DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_profile_id' => $busyRider->id,
            'delivery_date' => '2026-07-15',
            'status' => 'accepted',
            'assigned_stop_count' => 2,
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order']);
        $legs = ShipmentLeg::factory()->count(2)->create([
            'shipment_id' => $shipment->id,
            'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'status' => 'pending',
            'destination_snapshot' => ['latitude' => 14.60, 'longitude' => 120.98],
        ]);

        $result = app(BatchSuggestionService::class)->suggest(
            $shop,
            CarbonImmutable::parse('2026-07-15'),
            'morning',
        );

        $this->assertCount(1, $result);
        $this->assertSame($availableRider->id, $result[0]['rider_profile_id']);
        $this->assertSame(0, $result[0]['assigned_count']);
        $this->assertSame(0, $result[0]['overload_count']);
        $this->assertEqualsCanonicalizing($legs->pluck('id')->all(), $result[0]['leg_ids']);
    }

    public function test_it_allocates_distinct_stop_suggestions_across_riders(): void
    {
        $shop = ShopOwner::factory()->create([
            'business_type' => 'retail',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'daily_rider_capacity' => 2]);
        RiderProfile::factory()->count(2)->create([
            'shop_owner_id' => $shop->id,
            'active' => true,
            'availability_status' => 'available',
            'work_days' => [3],
            'leave_dates' => [],
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order']);
        $legs = ShipmentLeg::factory()->count(4)->create([
            'shipment_id' => $shipment->id,
            'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'status' => 'pending',
            'destination_snapshot' => ['latitude' => 14.60, 'longitude' => 120.98],
        ]);

        $result = app(BatchSuggestionService::class)->suggest(
            $shop,
            CarbonImmutable::parse('2026-07-15'),
            'morning',
        );

        $this->assertCount(2, $result);
        $this->assertNotEquals($result[0]['leg_ids'], $result[1]['leg_ids']);
        $this->assertEqualsCanonicalizing(
            $legs->pluck('id')->all(),
            collect($result)->flatMap(fn (array $suggestion) => $suggestion['leg_ids'])->all(),
        );
    }
}
