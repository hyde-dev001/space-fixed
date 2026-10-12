<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Services\Logistics\DeliveryEventService;
use App\Services\Logistics\LogisticsNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DeliveryEventAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_is_deferred_until_the_outer_transaction_commits(): void
    {
        DB::commit();
        DB::beginTransaction();
        $notifications = $this->fakeNotifications();

        try {
            [$shipment, $leg] = $this->customerShipment();

            DB::transaction(function () use ($shipment, $leg, $notifications): void {
                app(DeliveryEventService::class, ['notifications' => $notifications])->record($shipment, $leg, [
                    'event_type' => 'in_transit',
                    'visibility' => 'customer',
                    'message' => 'Your order is in transit.',
                ]);

                $this->assertSame(0, $notifications->calls);
            });

            $this->assertSame(1, $notifications->calls);
        } finally {
            DB::rollBack();
            DB::beginTransaction();
        }
    }

    public function test_rollback_discards_the_event_and_its_notification_callback(): void
    {
        DB::commit();
        DB::beginTransaction();
        $notifications = $this->fakeNotifications();

        try {
            [$shipment, $leg] = $this->customerShipment();

            try {
                DB::transaction(function () use ($shipment, $leg, $notifications): void {
                    app(DeliveryEventService::class, ['notifications' => $notifications])->record($shipment, $leg, [
                        'event_type' => 'in_transit',
                        'visibility' => 'customer',
                        'message' => 'Your order is in transit.',
                    ]);

                    throw new RuntimeException('rollback test');
                });
            } catch (RuntimeException $exception) {
                $this->assertSame('rollback test', $exception->getMessage());
            }

            $this->assertSame(0, $notifications->calls);
            $this->assertSame(0, DeliveryEvent::query()->where('shipment_leg_id', $leg->id)->count());
        } finally {
            DB::rollBack();
            DB::beginTransaction();
        }
    }

    private function fakeNotifications(): object
    {
        return new class extends LogisticsNotificationService
        {
            public int $calls = 0;

            public function __construct() {}

            public function notifyForEvent(DeliveryEvent $event): void
            {
                $this->calls++;
            }
        };
    }

    private function customerShipment(): array
    {
        $shipment = Shipment::factory()->create([
            'source_type' => 'manual',
            'purpose' => 'retail_delivery',
        ]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);

        return [$shipment, $leg];
    }
}
