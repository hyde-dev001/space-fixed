<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\DeliveryIncident;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\Logistics\DeliveryEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BatchInterruptionFixture;
use Tests\TestCase;

class BatchInterruptionNotificationTest extends TestCase
{
    use RefreshDatabase, BatchInterruptionFixture;

    public function test_customer_tracking_explains_a_hold_without_private_incident_details(): void
    {
        $f = $this->interruptionFixture();
        $this->reportInterruption($f)->assertCreated();
        $leg = $f['legs']->first();
        $data = app(\App\Services\Logistics\CustomerTrackingService::class)->payload($leg->shipment->fresh());
        $this->assertSame('delayed', $data['recovery']['state'] ?? null);
        $this->assertSame('Delivery delayed', $data['recovery']['label']);
        $this->assertArrayNotHasKey('notes', $data['recovery']);
        $this->assertArrayNotHasKey('stops', $data['recovery']);
        foreach (DeliveryEvent::all() as $event) app(DeliveryEventService::class)->deliverNotifications($event->id);
        $customerId = Order::findOrFail($leg->shipment->source_id)->customer_id;
        $this->assertFalse(Notification::where('user_id', $customerId)->sole()->requires_action);
        $this->assertTrue(Notification::where('user_id', $f['dispatcher']->id)->sole()->requires_action);
    }

    public function test_continuation_waits_for_start_and_a_repeated_incident_notifies_again(): void
    {
        $f = $this->interruptionFixture('accepted');
        for ($occurrence = 0; $occurrence < 2; $occurrence++) {
            $incident = DeliveryIncident::findOrFail($this->reportInterruption($f)->assertCreated()->json('incident.id'));
            app(\App\Services\Logistics\BatchInterruptionService::class)->resolve($incident, $f['dispatcher'], [
                'resolution' => 'resume', 'note' => 'Vehicle repaired and route authorized.', 'expected_revision' => 0,
                'decision_key' => (string) \Illuminate\Support\Str::uuid()]);
            if ($occurrence === 0) {
                $this->assertSame(0, DeliveryEvent::where('event_type', 'batch_interruption_continued')->count());
                app(\App\Services\Logistics\BatchDispatchService::class)->start($f['batch']->fresh(), $f['a']);
            }
        }
        foreach (DeliveryEvent::all() as $event) app(DeliveryEventService::class)->deliverNotifications($event->id);
        $this->artisan('logistics:deliver-pending-notifications')->assertExitCode(0);
        $this->assertSame(2, Notification::where('user_id', $f['dispatcher']->id)->where('title', 'Delivery Route Interrupted')->count());
        $this->assertSame(2, Notification::where('user_id', $f['dispatcher']->id)->where('title', 'Delivery Route Continuing')->count());
    }

    public function test_report_persists_one_dispatcher_occurrence_and_each_affected_customer_notice(): void
    {
        $f = $this->interruptionFixture();
        $foreign = User::factory()->create();
        $foreign->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Logistics Dispatcher', 'user'));
        $incidentId = $this->reportInterruption($f)->assertCreated()->json('incident.id');
        foreach (DeliveryEvent::where('event_type', 'batch_interruption_reported')->get() as $event) {
            app(DeliveryEventService::class)->deliverNotifications($event->id);
            app(DeliveryEventService::class)->deliverNotifications($event->id);
        }
        $this->assertSame(1, Notification::where('user_id', $f['dispatcher']->id)->count());
        $notice = Notification::where('user_id', $f['dispatcher']->id)->sole();
        $this->assertSame('/erp/logistics/batches?batch='.$f['batch']->id.'&incident='.$incidentId, $notice->action_url);
        $this->assertSame(0, Notification::where('user_id', $foreign->id)->count());
        foreach ($f['legs'] as $leg) {
            $customerId = Order::findOrFail($leg->shipment->source_id)->customer_id;
            $customerNotice = Notification::where('user_id', $customerId)->sole();
            $this->assertSame('/tracking/shipments/'.$leg->shipment_id, $customerNotice->action_url);
            $this->assertArrayNotHasKey('affected_stops', $customerNotice->data);
            $this->assertArrayNotHasKey('photo_paths', $customerNotice->data);
        }
        $this->assertSame(4, DeliveryEvent::where('event_type', 'batch_interruption_reported')->count());
    }
}
