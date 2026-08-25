<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RiderProgressBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_legs_are_released_without_changing_business_status(): void
    {
        $legs = collect(['delivered', 'cancelled', 'failed'])
            ->mapWithKeys(fn (string $status): array => [
                $status => ShipmentLeg::factory()->create(['status' => $status]),
            ]);

        Artisan::call('logistics:backfill-rider-progress-state');

        foreach ($legs as $status => $leg) {
            $fresh = $leg->fresh();

            $this->assertSame($status, $fresh->status->value);
            $this->assertSame('rider_released', $fresh->rider_progress_state->value);
        }
    }

    public function test_latest_rejected_delivery_proof_repairs_legacy_transit_status(): void
    {
        $leg = ShipmentLeg::factory()->create(['status' => 'in_transit']);
        $this->proof($leg, 'rejected');

        Artisan::call('logistics:backfill-rider-progress-state');

        $fresh = $leg->fresh();
        $this->assertSame('proof_correction_required', $fresh->status->value);
        $this->assertSame('proof_action_required', $fresh->rider_progress_state->value);
    }

    public function test_awaiting_pending_delivery_proof_is_submitted(): void
    {
        $leg = ShipmentLeg::factory()->create(['status' => 'awaiting_proof_approval']);
        $this->proof($leg, 'pending');

        Artisan::call('logistics:backfill-rider-progress-state');

        $this->assertSame('proof_submitted', $leg->fresh()->rider_progress_state->value);
    }

    public function test_awaiting_approved_delivery_proof_is_released_and_marked_for_reconciliation(): void
    {
        $leg = ShipmentLeg::factory()->create(['status' => 'awaiting_proof_approval']);
        $proof = $this->proof($leg, 'approved');

        Artisan::call('logistics:backfill-rider-progress-state');

        $this->assertSame('rider_released', $leg->fresh()->rider_progress_state->value);
        $this->assertDatabaseHas('delivery_events', [
            'shipment_leg_id' => $leg->id,
            'event_type' => 'proof_reconciliation_required',
        ]);
        $marker = DeliveryEvent::query()
            ->where('shipment_leg_id', $leg->id)
            ->where('event_type', 'proof_reconciliation_required')
            ->firstOrFail();
        $this->assertSame($proof->id, $marker->metadata['proof_id']);
    }

    public function test_awaiting_without_delivery_proof_and_other_non_terminal_legs_are_active(): void
    {
        $awaiting = ShipmentLeg::factory()->create(['status' => 'awaiting_proof_approval']);
        $pending = ShipmentLeg::factory()->create(['status' => 'pending']);
        $inTransit = ShipmentLeg::factory()->create(['status' => 'in_transit']);

        Artisan::call('logistics:backfill-rider-progress-state');

        foreach ([$awaiting, $pending, $inTransit] as $leg) {
            $this->assertSame('active', $leg->fresh()->rider_progress_state->value);
        }
    }

    public function test_receive_proof_is_excluded_from_delivery_backfill(): void
    {
        $leg = ShipmentLeg::factory()->create(['status' => 'awaiting_proof_approval']);
        $this->proof($leg, 'rejected', 'receive');

        Artisan::call('logistics:backfill-rider-progress-state');

        $fresh = $leg->fresh();
        $this->assertSame('awaiting_proof_approval', $fresh->status->value);
        $this->assertSame('active', $fresh->rider_progress_state->value);
    }

    public function test_latest_delivery_proof_uses_id_after_recorded_at_tie(): void
    {
        $leg = ShipmentLeg::factory()->create(['status' => 'in_transit']);
        $recordedAt = now()->subHour();
        $this->proof($leg, 'rejected', recordedAt: $recordedAt);
        $this->proof($leg, 'pending', recordedAt: $recordedAt);

        Artisan::call('logistics:backfill-rider-progress-state');

        $this->assertSame('active', $leg->fresh()->rider_progress_state->value);
        $this->assertSame('in_transit', $leg->fresh()->status->value);
    }

    public function test_backfill_is_idempotent_and_does_not_duplicate_reconciliation_marker(): void
    {
        $leg = ShipmentLeg::factory()->create(['status' => 'awaiting_proof_approval']);
        $this->proof($leg, 'approved');

        Artisan::call('logistics:backfill-rider-progress-state');
        Artisan::call('logistics:backfill-rider-progress-state');

        $this->assertSame(1, DeliveryEvent::query()
            ->where('shipment_leg_id', $leg->id)
            ->where('event_type', 'proof_reconciliation_required')
            ->count());
        $this->assertSame('rider_released', $leg->fresh()->rider_progress_state->value);
    }

    private function proof(
        ShipmentLeg $leg,
        string $reviewStatus,
        string $handoffType = 'delivery',
        mixed $recordedAt = null,
    ): HandoffProof {
        return HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => $handoffType,
            'review_status' => $reviewStatus,
            'recorded_at' => $recordedAt ?? now(),
        ]);
    }
}
