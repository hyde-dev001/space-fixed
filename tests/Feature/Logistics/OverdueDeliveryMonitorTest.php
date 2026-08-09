<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverdueDeliveryMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_offer_is_flagged_once_and_never_cancelled(): void
    {
        $shop = ShopOwner::factory()->create();
        $batch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'offered', 'offered_at' => now()->subHour()]);
        ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id, 'delivery_batch_id' => $batch->id]);

        $this->artisan('logistics:monitor-overdue')->assertSuccessful();
        $this->artisan('logistics:monitor-overdue')->assertSuccessful();

        $this->assertDatabaseCount('delivery_events', 1);
        $this->assertDatabaseHas('delivery_events', ['event_type' => 'overdue_batch_offer']);
        $this->assertSame('offered', $batch->fresh()->status);
    }

    public function test_same_day_delivery_is_not_delayed_before_its_window_ends(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10 09:00', config('app.shop_timezone', 'Asia/Manila')));
        $shop = ShopOwner::factory()->create();
        LogisticsSetting::create([
            'shop_owner_id' => $shop->id,
            'operating_days' => [1, 2, 3, 4, 5],
            'blackout_dates' => [],
            'morning_end' => '12:00',
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'pending',
            'scheduled_delivery_date' => '2026-08-10',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
        ]);

        $this->artisan('logistics:monitor-overdue')->assertSuccessful();

        $this->assertSame('2026-08-10', $leg->fresh()->scheduled_delivery_date->toDateString());
        $this->assertDatabaseMissing('delivery_events', [
            'shipment_leg_id' => $leg->id,
            'event_type' => 'delivery_estimate_delayed',
        ]);
    }

    public function test_overdue_delivery_moves_to_the_next_valid_operating_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-14 13:00', config('app.shop_timezone', 'Asia/Manila')));
        $shop = ShopOwner::factory()->create();
        LogisticsSetting::create([
            'shop_owner_id' => $shop->id,
            'operating_days' => [1, 2, 3, 4, 5],
            'blackout_dates' => ['2026-08-17'],
            'morning_end' => '12:00',
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'pending',
            'scheduled_delivery_date' => '2026-08-14',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
        ]);

        $this->artisan('logistics:monitor-overdue')->assertSuccessful();

        $this->assertSame('2026-08-18', $leg->fresh()->scheduled_delivery_date->toDateString());
        $this->assertDatabaseHas('delivery_events', [
            'shipment_leg_id' => $leg->id,
            'event_type' => 'delivery_estimate_delayed',
            'visibility' => 'customer',
        ]);
    }

    public function test_monitor_does_not_rewrite_estimate_after_custody_has_started(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10 19:00', config('app.shop_timezone', 'Asia/Manila')));
        $shop = ShopOwner::factory()->create();
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'operating_days' => [1, 2, 3, 4, 5]]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'in_transit',
            'scheduled_delivery_date' => '2026-08-09',
            'delivery_window' => 'afternoon',
            'schedule_status' => 'scheduled',
        ]);

        $this->artisan('logistics:monitor-overdue')->assertSuccessful();

        $this->assertSame('2026-08-09', $leg->fresh()->scheduled_delivery_date->toDateString());
        $this->assertDatabaseHas('delivery_events', [
            'shipment_leg_id' => $leg->id,
            'event_type' => 'overdue_delivery_stop',
        ]);
        $this->assertDatabaseMissing('delivery_events', [
            'shipment_leg_id' => $leg->id,
            'event_type' => 'delivery_estimate_delayed',
        ]);
    }
}
