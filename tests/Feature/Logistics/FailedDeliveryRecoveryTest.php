<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\{DeliveryAssignment, HandoffProof, LogisticsSetting, RiderProfile, Shipment, ShipmentLeg};
use App\Models\{Notification, Order, ShopOwner, ShopOwnerModule, User};
use App\Services\Logistics\{AssignmentService, DeliveryEventService, DeliveryIncidentService, LogisticsNotificationService, ShipmentLegService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

// Acceptance regressions converted from the approved audit observations.
class FailedDeliveryRecoveryTest extends \Tests\TestCase
{
    use RefreshDatabase;

    private function fixture(int $maxAttempts = 2): array
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        ShopOwnerModule::updateOrCreate(['shop_owner_id' => $shop->id, 'module_key' => 'logistics'], ['enabled' => true]);
        LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], ['max_delivery_attempts' => $maxAttempts]);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'in_transit', 'picked_up_at' => now(), 'custody_state' => 'rider', 'custody_rider_profile_id' => $rider->id, 'custody_confirmed_at' => now()]);
        $assignment = DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        return [$shop, $rider, $leg, $assignment];
    }

    private function failure(ShipmentLeg $leg, DeliveryAssignment $assignment)
    {
        return app(ShipmentLegService::class)->recordFailedAttempt($leg->fresh(), [
            'delivery_assignment_id' => $assignment->id,
            'idempotency_key' => (string) Str::uuid(),
            'reason_code' => 'other', 'notes' => 'Synthetic audit observation',
        ]);
    }

    public function test_authorized_retry_records_a_distinct_failure_and_keeps_replay_idempotent(): void
    {
        [, $rider, $leg, $assignment] = $this->fixture(1);
        $first = $this->failure($leg, $assignment);
        app(ShipmentLegService::class)->resolveRetry($leg->fresh(), 'Customer agreed retry');
        $this->travelTo(now()->addDays(7));
        $retryAssignment = $leg->assignments()->latest('id')->firstOrFail();
        app(AssignmentService::class)->respondToOffer($leg->fresh(), $rider, true);
        app(ShipmentLegService::class)->markInTransit($leg->fresh(), $rider);
        $next = $this->failure($leg, $retryAssignment);
        $this->assertNotSame($first->id, $next->id);
        $this->assertSame(2, $leg->attempts()->count());
        $this->assertSame('needs_resolution', $leg->fresh()->status->value);
    }

    public function test_failure_preserves_custody_and_awaits_an_explicit_dispatcher_resolution(): void
    {
        [$shop, , $leg, $oldAssignment] = $this->fixture();
        $leg->update(['requires_pickup_proof' => true]);
        HandoffProof::factory()->create(['shipment_leg_id' => $leg->id, 'handoff_type' => 'pickup', 'review_status' => 'approved']);
        $this->failure($leg, $oldAssignment);
        $this->assertSame('accepted', $oldAssignment->fresh()->status);
        $this->assertNotNull($leg->fresh()->picked_up_at);
        $this->assertSame('needs_resolution', $leg->fresh()->status->value);
    }

    public function test_each_recovery_occurrence_creates_its_customer_notification(): void
    {
        $customer = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $order->shop_owner_id, 'source_type' => 'order', 'source_id' => $order->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
        $notifications = app(LogisticsNotificationService::class);
        foreach ([1, 2] as $number) {
            $event = $leg->events()->create(['shipment_id' => $shipment->id, 'event_type' => 'delivery_attempt_failed', 'visibility' => 'customer', 'message' => 'Synthetic failure '.$number, 'metadata' => ['attempt_number' => $number]]);
            $notifications->notifyForEvent($event);
        }
        foreach (['pickup_attempt_failed', 'pickup_rescheduled', 'delivery_retry_authorized', 'return_received'] as $type) {
            $event = $leg->events()->create(['shipment_id' => $shipment->id, 'event_type' => $type, 'visibility' => 'customer', 'message' => 'Synthetic audit event']);
            $notifications->notifyForEvent($event);
        }
        $this->assertSame(6, Notification::where('user_id', $customer->id)->where('group_key', 'like', 'logistics:%')->count());
    }

    public function test_dismissed_pre_pickup_incident_restores_assigned_state_and_preserves_original_notes(): void
    {
        [$shop, $rider, $leg] = $this->fixture();
        $leg->update(['status' => 'assigned', 'picked_up_at' => null, 'requires_pickup_proof' => true]);
        $incidents = app(DeliveryIncidentService::class);
        $incident = $incidents->report($leg->fresh(), $rider, ['type' => 'vehicle_problem', 'notes' => 'Synthetic vehicle issue', 'photo_paths' => ['incident-evidence/audit/photo.jpg']]);
        $incidents->resolve($incident, $shop, 'dismissed', 'Issue cleared');
        $this->assertSame('assigned', $leg->fresh()->status->value);
        $this->assertSame('Synthetic vehicle issue', $incident->fresh()->notes);
        $this->assertNull($leg->fresh()->picked_up_at);
        $this->assertSame(0, $leg->proofs()->count());
    }
}
