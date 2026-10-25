<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\Logistics\DeliveryEventService;
use App\Services\Logistics\LogisticsNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecoveryNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_receive_shop_scoped_incident_and_loss_notices_once_per_occurrence(): void
    {
        [, $shipment, $leg] = $this->customerLeg();
        $permission = \Spatie\Permission\Models\Permission::findOrCreate('access-staff-job-orders', 'user');
        $staff = User::factory()->create(['shop_owner_id' => $shipment->shop_owner_id]);
        $staff->givePermissionTo($permission);
        $foreign = User::factory()->create();
        $foreign->givePermissionTo($permission);
        foreach (['delivery_incident_reported', 'loss_confirmed'] as $type) {
            $event = app(DeliveryEventService::class)->record($shipment, $leg, ['event_type' => $type,
                'visibility' => 'internal', 'metadata' => ['incident_id' => 42]]);
            app(DeliveryEventService::class)->deliverNotifications($event->id);
            app(DeliveryEventService::class)->deliverNotifications($event->id);
        }
        $notices = Notification::where('user_id', $staff->id)->get();
        $this->assertCount(2, $notices);
        $this->assertSame(0, Notification::where('user_id', $foreign->id)->count());
        foreach ($notices as $notice) {
            $this->assertSame('/erp/staff/job-orders', $notice->action_url);
            $this->assertSame($shipment->source_id, $notice->data['order_id']);
        }
    }

    public function test_internal_parcel_transfers_keep_actor_notices_without_customer_action_spam(): void
    {
        [$customer, $shipment, $leg] = $this->customerLeg();
        $rider = \App\Models\Logistics\RiderProfile::factory()->create(['shop_owner_id' => $shipment->shop_owner_id]);
        $event = app(DeliveryEventService::class)->record($shipment, $leg, ['event_type' => 'custody_transfer_requested',
            'visibility' => 'customer', 'metadata' => ['custody_transfer_id' => 42, 'rider_profile_id' => $rider->id]]);
        app(DeliveryEventService::class)->deliverNotifications($event->id);
        $this->assertSame(0, Notification::where('user_id', $customer->id)->count());
        $this->assertSame(1, Notification::where('user_id', $rider->linked_id)->count());
    }

    public function test_recovery_command_is_scheduled_each_minute_with_overlap_protection(): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'logistics:deliver-pending-notifications'));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    public function test_scheduled_retry_fills_partial_recipients_without_duplicates_or_cross_shop_delivery(): void
    {
        [$customer, $shipment, $leg] = $this->customerLeg();
        $role = \Spatie\Permission\Models\Role::findOrCreate('Logistics Dispatcher', 'user');
        $dispatcher = User::factory()->create(['shop_owner_id' => $shipment->shop_owner_id]);
        $dispatcher->assignRole($role);
        $other = User::factory()->create(['shop_owner_id' => \App\Models\ShopOwner::factory()->create()->id]);
        $other->assignRole($role);
        $event = DeliveryEvent::create(['shipment_id' => $shipment->id, 'shipment_leg_id' => $leg->id,
            'event_type' => 'delivery_attempt_failed', 'visibility' => 'customer', 'notification_requested_at' => now(),
            'metadata' => ['delivery_attempt_id' => 789]]);
        $group = implode(':', ['logistics', $customer->id, $shipment->id, $leg->id, 'delivery_attempt_failed', 'delivery_attempt_id=789']);
        $existing = Notification::create(['user_id' => $customer->id, 'shop_id' => $shipment->shop_owner_id,
            'type' => 'logistics_delivery_failed', 'title' => 'Existing delivery notice', 'message' => 'Synthetic notice',
            'action_url' => '/tracking/shipments/'.$shipment->id, 'group_key' => $group, 'logistics_delivery_key' => hash('sha256', $group)]);
        $this->artisan('logistics:deliver-pending-notifications')->assertExitCode(0);
        $this->artisan('logistics:deliver-pending-notifications')->assertExitCode(0);
        $this->assertSame(1, Notification::where('user_id', $customer->id)->count());
        $this->assertModelExists($existing);
        $notice = Notification::where('user_id', $dispatcher->id)->sole();
        $this->assertSame($shipment->shop_owner_id, $notice->shop_id);
        $this->assertStringStartsWith('/erp/logistics/shipments', $notice->action_url);
        $this->assertSame(0, Notification::where('user_id', $other->id)->count());
        $this->assertNotNull($event->fresh()->notifications_processed_at);
        $this->assertSame(1, $event->fresh()->notification_attempts);
    }

    private function customerLeg(): array
    {
        $customer = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $order->shop_owner_id, 'source_type' => 'order', 'source_id' => $order->id]);
        return [$customer, $shipment, ShipmentLeg::factory()->create(['shipment_id' => $shipment->id])];
    }

    public function test_notification_failure_does_not_misreport_committed_business_event_and_can_be_recovered(): void
    {
        [$customer, $shipment, $leg] = $this->customerLeg();
        $broken = new class extends LogisticsNotificationService {
            public function notifyForEvent(DeliveryEvent $event): void { throw new \RuntimeException('Synthetic transport failure'); }
        };
        $service = new DeliveryEventService($broken);
        try {
            $event = $service->record($shipment, $leg, ['event_type' => 'delivery_attempt_failed', 'visibility' => 'customer', 'metadata' => ['delivery_attempt_id' => 123]]);
        } catch (\Throwable) {
            $this->fail('Notification failure escaped after the business event was saved.');
        }
        $this->assertModelExists($event);
        $this->assertNull($event->fresh()->notifications_processed_at);
        app(DeliveryEventService::class)->deliverNotifications($event->id);
        app(DeliveryEventService::class)->deliverNotifications($event->id);
        $this->assertNotNull($event->fresh()->notifications_processed_at);
        $this->assertSame(1, Notification::where('user_id', $customer->id)->where('type', 'logistics_delivery_failed')->count());
    }

    public function test_one_attempt_internal_customer_pair_deduplicates_but_a_new_attempt_notifies_again(): void
    {
        [$customer, $shipment, $leg] = $this->customerLeg();
        foreach ([123, 124] as $attempt) {
            foreach (['internal', 'customer'] as $visibility) {
                app(DeliveryEventService::class)->record($shipment, $leg, ['event_type' => 'delivery_attempt_failed', 'visibility' => $visibility,
                    'metadata' => ['delivery_attempt_id' => $attempt], 'message' => 'Another visit failed.']);
            }
        }
        $this->assertSame(2, Notification::where('user_id', $customer->id)->where('type', 'logistics_delivery_failed')->count());
    }
    public function test_scheduled_recovery_processes_pending_events_once_and_leaves_legacy_history_alone(): void
    {
        [$customer, $shipment, $leg] = $this->customerLeg();
        $legacy = DeliveryEvent::create(['shipment_id' => $shipment->id, 'shipment_leg_id' => $leg->id, 'event_type' => 'delivery_attempt_failed', 'visibility' => 'customer']);
        $current = DeliveryEvent::create(['shipment_id' => $shipment->id, 'shipment_leg_id' => $leg->id, 'event_type' => 'delivery_attempt_failed', 'visibility' => 'customer', 'notification_requested_at' => now(), 'metadata' => ['delivery_attempt_id' => 987]]);
        $this->artisan('logistics:deliver-pending-notifications')->assertExitCode(0);
        $this->artisan('logistics:deliver-pending-notifications')->assertExitCode(0);
        $this->assertNull($legacy->fresh()->notifications_processed_at);
        $this->assertNotNull($current->fresh()->notifications_processed_at);
        $this->assertSame(1, Notification::where('user_id', $customer->id)->count());
    }
}
