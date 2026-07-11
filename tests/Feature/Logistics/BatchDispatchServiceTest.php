<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Services\Logistics\BatchDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BatchDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_offer_accept_and_start_preserve_individual_leg_state(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $legs = ShipmentLeg::factory()->count(2)->create([
            'shipment_id' => $shipment->id, 'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning', 'schedule_status' => 'scheduled', 'status' => 'pending',
        ]);
        $service = app(BatchDispatchService::class);

        $batch = $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all());
        $this->assertSame([1, 2], $batch->legs->pluck('stop_sequence')->all());
        $batch = $service->offer($batch, $rider, $shop);
        $this->assertSame('offered', $batch->status);
        $this->assertCount(2, $batch->legs->flatMap->assignments);
        $this->assertSame('accepted', $service->accept($batch, $rider)->status);
        $started = $service->start($batch->fresh(), $rider);
        $this->assertSame('in_progress', $started->status);
        $this->assertSame(['assigned'], $started->legs->pluck('status.value')->unique()->values()->all());
    }

    public function test_rejection_returns_batch_to_draft_and_cancellation_returns_legs_to_pool(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available']);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'scheduled_delivery_date' => '2026-07-15', 'delivery_window' => 'morning',
            'schedule_status' => 'scheduled', 'status' => 'pending',
        ]);
        $service = app(BatchDispatchService::class);
        $batch = $service->offer($service->createDraft($shop, '2026-07-15', 'morning', [$leg->id]), $rider, $shop);

        $this->expectException(ValidationException::class);
        $service->reject($batch, $rider, '');
    }

    public function test_rejection_reason_and_cancel_are_recorded(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'active' => true, 'availability_status' => 'available']);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'scheduled_delivery_date' => '2026-07-15', 'delivery_window' => 'morning',
            'schedule_status' => 'scheduled', 'status' => 'pending',
        ]);
        $service = app(BatchDispatchService::class);
        $batch = $service->offer($service->createDraft($shop, '2026-07-15', 'morning', [$leg->id]), $rider, $shop);

        $rejected = $service->reject($batch, $rider, 'Vehicle unavailable');
        $this->assertSame('draft', $rejected->status);
        $this->assertNull($rejected->rider_profile_id);
        $cancelled = $service->cancel($rejected, 'No longer required');
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNull($leg->fresh()->delivery_batch_id);
    }
}
