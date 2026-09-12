<?php

namespace Tests\Feature\Logistics;

use App\Enums\Logistics\RiderProgressState;
use App\Enums\Logistics\ShipmentLegStatus;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RiderProofReviewStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_rider_progress_state_and_replacement_link_are_persisted(): void
    {
        $this->assertTrue(Schema::hasColumn('shipment_legs', 'rider_progress_state'));
        $this->assertTrue(Schema::hasColumn('handoff_proofs', 'replaces_proof_id'));
        $this->assertSame('active', RiderProgressState::ACTIVE->value);
        $this->assertSame('proof_submitted', RiderProgressState::PROOF_SUBMITTED->value);
        $this->assertSame('proof_action_required', RiderProgressState::PROOF_ACTION_REQUIRED->value);
        $this->assertSame('rider_released', RiderProgressState::RIDER_RELEASED->value);
        $this->assertSame('proof_correction_required', ShipmentLegStatus::PROOF_CORRECTION_REQUIRED->value);
    }

    public function test_rider_progress_state_is_cast_and_proof_chain_is_linked(): void
    {
        $leg = ShipmentLeg::factory()->create([
            'rider_progress_state' => RiderProgressState::PROOF_ACTION_REQUIRED,
        ]);
        $rejected = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'review_status' => 'rejected',
        ]);
        $replacement = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'replaces_proof_id' => $rejected->id,
            'review_status' => 'pending',
        ]);

        $this->assertSame(RiderProgressState::PROOF_ACTION_REQUIRED, $leg->fresh()->rider_progress_state);
        $this->assertTrue($rejected->fresh()->replacements->contains('id', $replacement->id));
        $this->assertTrue($replacement->fresh()->replacedProof->is($rejected));
    }
}
