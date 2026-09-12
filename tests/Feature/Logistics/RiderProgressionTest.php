<?php

namespace Tests\Feature\Logistics;

use App\Enums\Logistics\RiderProgressState;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Services\Logistics\RiderActiveWorkGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RiderProgressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_progress_batch_with_only_review_states_does_not_block_new_standalone_work(): void
    {
        [$shop, $rider, $batch] = $this->batchFixture();
        $this->batchLeg($batch, $rider, 1, 'awaiting_proof_approval', RiderProgressState::PROOF_SUBMITTED);
        $this->batchLeg($batch, $rider, 2, 'proof_correction_required', RiderProgressState::PROOF_ACTION_REQUIRED);
        $target = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'in_transit',
            'rider_progress_state' => RiderProgressState::ACTIVE,
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $target->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);

        app(RiderActiveWorkGuard::class)->assertCanStartStandalone($rider, $target);

        $this->assertSame('in_progress', $batch->fresh()->status);
    }

    public function test_first_active_batch_stop_is_the_only_stop_that_can_advance(): void
    {
        [$shop, $rider, $batch] = $this->batchFixture();
        $first = $this->batchLeg($batch, $rider, 1, 'in_transit', RiderProgressState::ACTIVE);
        $second = $this->batchLeg($batch, $rider, 2, 'in_transit', RiderProgressState::ACTIVE);
        $guard = app(RiderActiveWorkGuard::class);

        try {
            $guard->assertCanAdvanceLeg($rider, $second);
            $this->fail('A later active batch stop advanced before the first active stop.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('active_work', $exception->errors());
        }

        $guard->assertCanAdvanceLeg($rider, $first);
        $this->assertSame('in_progress', $batch->fresh()->status);
    }

    public function test_a_submitted_first_stop_releases_the_next_active_batch_stop(): void
    {
        [$shop, $rider, $batch] = $this->batchFixture();
        $this->batchLeg($batch, $rider, 1, 'awaiting_proof_approval', RiderProgressState::PROOF_SUBMITTED);
        $next = $this->batchLeg($batch, $rider, 2, 'in_transit', RiderProgressState::ACTIVE);

        app(RiderActiveWorkGuard::class)->assertCanAdvanceLeg($rider, $next);

        $this->assertSame('in_progress', $batch->fresh()->status);
    }

    private function batchFixture(): array
    {
        $shop = ShopOwner::factory()->create();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $batch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_profile_id' => $rider->id,
            'status' => 'in_progress',
            'started_at' => now()->subMinutes(10),
        ]);

        return [$shop, $rider, $batch];
    }

    private function batchLeg(
        DeliveryBatch $batch,
        RiderProfile $rider,
        int $sequence,
        string $status,
        RiderProgressState $progressState,
    ): ShipmentLeg {
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $batch->shop_owner_id])->id,
            'delivery_batch_id' => $batch->id,
            'stop_sequence' => $sequence,
            'status' => $status,
            'rider_progress_state' => $progressState,
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);

        return $leg;
    }
}
