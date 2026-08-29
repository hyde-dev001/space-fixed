<?php

namespace Tests\Feature\Logistics;

use App\Enums\Logistics\RiderProgressState;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use App\Services\Logistics\ProofReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProofReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_delivery_proof_sets_pending_review_and_releases_rider_progress(): void
    {
        [$shop, $rider, $leg] = $this->riderLeg(withArrival: true);
        Storage::fake('local');

        $response = $this->actingAs($rider, 'user')->post(
            "/api/logistics/legs/{$leg->id}/proof",
            [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'idempotency_key' => '11111111-1111-4111-8111-111111111111',
                'proof_file' => $this->photo('initial.png'),
            ],
            ['Accept' => 'application/json']
        )->assertCreated();

        $proofId = $response->json('proof.id');
        $fresh = $leg->fresh();

        $this->assertSame('awaiting_proof_approval', $fresh->status->value);
        $this->assertSame(RiderProgressState::PROOF_SUBMITTED, $fresh->rider_progress_state);
        $this->assertDatabaseHas('handoff_proofs', [
            'id' => $proofId,
            'review_status' => 'pending',
            'handoff_type' => 'delivery',
        ]);
        $this->assertDatabaseHas('delivery_events', [
            'shipment_leg_id' => $leg->id,
            'event_type' => 'proof_required',
        ]);
    }

    public function test_replacement_delivery_proof_is_new_and_does_not_require_a_second_arrival(): void
    {
        [$shop, $rider, $leg, $profile] = $this->riderLeg(withArrival: false, includeProfile: true);
        Storage::fake('local');
        $originalPath = "logistics-proof/{$leg->id}/original.png";
        Storage::disk('local')->put($originalPath, 'original-proof');
        $rejected = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'idempotency_key' => '22222222-2222-4222-8222-222222222222',
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'file_path' => $originalPath,
            'notes' => 'Original submission',
            'metadata' => ['source' => 'original'],
            'review_status' => 'rejected',
            'rejection_reason' => 'Unreadable image.',
        ]);
        $leg->update([
            'status' => 'proof_correction_required',
            'rider_progress_state' => RiderProgressState::PROOF_ACTION_REQUIRED,
        ]);

        $response = $this->actingAs($rider, 'user')->post(
            "/api/logistics/legs/{$leg->id}/proof",
            [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'idempotency_key' => '33333333-3333-4333-8333-333333333333',
                'replaces_proof_id' => $rejected->id,
                'proof_file' => $this->photo('replacement.png'),
            ],
            ['Accept' => 'application/json']
        )->assertCreated();

        $replacementId = $response->json('proof.id');
        $this->assertNotSame($rejected->id, $replacementId);
        $this->assertDatabaseHas('handoff_proofs', [
            'id' => $replacementId,
            'replaces_proof_id' => $rejected->id,
            'review_status' => 'pending',
        ]);
        $this->assertSame([
            'file_path' => $originalPath,
            'notes' => 'Original submission',
            'review_status' => 'rejected',
            'rejection_reason' => 'Unreadable image.',
        ], $rejected->fresh()->only(['file_path', 'notes', 'review_status', 'rejection_reason']));
        $this->assertSame('awaiting_proof_approval', $leg->fresh()->status->value);
        $this->assertSame(RiderProgressState::PROOF_SUBMITTED, $leg->fresh()->rider_progress_state);
        $this->assertSame(2, $leg->fresh()->proofs()->count());
    }

    public function test_replacement_must_reference_the_latest_rejected_delivery_proof(): void
    {
        [$shop, $rider, $leg] = $this->riderLeg(withArrival: false);
        Storage::fake('local');
        $pending = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'review_status' => 'pending',
        ]);
        $leg->update([
            'status' => 'awaiting_proof_approval',
            'rider_progress_state' => RiderProgressState::PROOF_SUBMITTED,
        ]);

        $this->actingAs($rider, 'user')->post(
            "/api/logistics/legs/{$leg->id}/proof",
            [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'idempotency_key' => '44444444-4444-4444-8444-444444444444',
                'replaces_proof_id' => $pending->id,
                'proof_file' => $this->photo('invalid-replacement.png'),
            ],
            ['Accept' => 'application/json']
        )->assertUnprocessable();

        $this->assertSame(1, $leg->fresh()->proofs()->count());
    }

    public function test_reusing_an_idempotency_key_with_a_conflicting_payload_is_rejected(): void
    {
        [$shop, $rider, $leg] = $this->riderLeg(withArrival: true);
        Storage::fake('local');
        $payload = [
            'handoff_type' => 'delivery',
            'proof_type' => 'photo',
            'idempotency_key' => '55555555-5555-4555-8555-555555555555',
        ];

        $this->actingAs($rider, 'user')->post(
            "/api/logistics/legs/{$leg->id}/proof",
            [...$payload, 'notes' => 'First submission', 'proof_file' => $this->photo('first.png')],
            ['Accept' => 'application/json']
        )->assertCreated();

        $this->actingAs($rider, 'user')->post(
            "/api/logistics/legs/{$leg->id}/proof",
            [...$payload, 'notes' => 'Conflicting submission', 'proof_file' => $this->photo('second.png')],
            ['Accept' => 'application/json']
        )->assertUnprocessable();

        $this->assertSame(1, $leg->fresh()->proofs()->count());
    }

    public function test_a_leg_cannot_have_two_pending_delivery_proofs_even_if_its_progress_state_is_stale(): void
    {
        [$shop, $rider, $leg] = $this->riderLeg(withArrival: true);
        Storage::fake('local');
        HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'review_status' => 'pending',
        ]);

        $this->actingAs($rider, 'user')->post(
            "/api/logistics/legs/{$leg->id}/proof",
            [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'idempotency_key' => '88888888-8888-4888-8888-888888888888',
                'proof_file' => $this->photo('duplicate-pending.png'),
            ],
            ['Accept' => 'application/json']
        )->assertUnprocessable();

        $this->assertSame(1, $leg->fresh()->proofs()->count());
    }

    public function test_dispatcher_can_approve_after_rider_progression_and_release_the_leg(): void
    {
        [$shop, $rider, $leg] = $this->riderLeg(withArrival: true);
        Storage::fake('local');

        $proof = $this->actingAs($rider, 'user')->post(
            "/api/logistics/legs/{$leg->id}/proof",
            [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'idempotency_key' => '66666666-6666-4666-8666-666666666666',
                'proof_file' => $this->photo('approval.png'),
            ],
            ['Accept' => 'application/json']
        )->assertCreated()->json('proof');

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/proofs/{$proof['id']}/approve")
            ->assertOk();
        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/proofs/{$proof['id']}/approve")
            ->assertOk();

        $freshLeg = $leg->fresh();
        $this->assertSame('delivered', $freshLeg->status->value);
        $this->assertSame(RiderProgressState::RIDER_RELEASED, $freshLeg->rider_progress_state);
        $this->assertSame('approved', $freshLeg->proofs()->findOrFail($proof['id'])->review_status);
        $this->assertSame(1, $freshLeg->events()->where('event_type', 'proof_required')->count());
        $this->assertSame(1, $freshLeg->events()->where('event_type', 'proof_approved')->count());
        $this->assertSame(1, $freshLeg->events()->where('event_type', 'delivered')->count());
        $this->assertDatabaseHas('delivery_events', [
            'shipment_leg_id' => $leg->id,
            'event_type' => 'proof_approved',
        ]);
    }

    public function test_rejecting_delivery_proof_only_creates_a_correction_requirement(): void
    {
        [$shop, $rider, $leg] = $this->riderLeg(withArrival: true);
        Storage::fake('local');
        $originalAttemptNumber = $leg->fresh()->attempt_number;
        $originalResolution = $leg->resolution_type;
        $originalScheduledDate = $leg->scheduled_delivery_date;

        $proof = $this->actingAs($rider, 'user')->post(
            "/api/logistics/legs/{$leg->id}/proof",
            [
                'handoff_type' => 'delivery',
                'proof_type' => 'photo',
                'idempotency_key' => '77777777-7777-4777-8777-777777777777',
                'proof_file' => $this->photo('rejection.png'),
            ],
            ['Accept' => 'application/json']
        )->assertCreated()->json('proof');

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/proofs/{$proof['id']}/reject", [
                'rejection_reason' => 'The recipient is not identifiable in the image.',
            ])
            ->assertOk();
        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/logistics/proofs/{$proof['id']}/reject", [
                'rejection_reason' => 'A different reason must not overwrite the review.',
            ])
            ->assertOk();

        $freshLeg = $leg->fresh();
        $freshProof = $freshLeg->proofs()->findOrFail($proof['id']);
        $this->assertSame('proof_correction_required', $freshLeg->status->value);
        $this->assertSame(RiderProgressState::PROOF_ACTION_REQUIRED, $freshLeg->rider_progress_state);
        $this->assertSame('rejected', $freshProof->review_status);
        $this->assertSame('The recipient is not identifiable in the image.', $freshProof->rejection_reason);
        $this->assertSame($originalAttemptNumber, $freshLeg->attempt_number);
        $this->assertSame($originalResolution, $freshLeg->resolution_type);
        $this->assertSame($originalScheduledDate, $freshLeg->scheduled_delivery_date);
        $this->assertSame(0, $freshLeg->attempts()->count());
        $this->assertSame(1, $freshLeg->events()->where('event_type', 'proof_rejected')->count());
    }

    public function test_generic_delivery_proof_approval_does_not_complete_a_receive_leg(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $leg = ShipmentLeg::factory()->create([
            'leg_type' => 'return_to_shop',
            'status' => 'awaiting_proof_approval',
        ]);
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'receive',
            'review_status' => 'pending',
        ]);

        try {
            app(ProofReviewService::class)->approve($proof, $shop);
            $this->fail('A receive proof entered the delivery approval flow.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('proof', $exception->errors());
        }

        $this->assertSame('awaiting_proof_approval', $leg->fresh()->status->value);
    }

    private function riderLeg(bool $withArrival, bool $includeProfile = false): array
    {
        Permission::findOrCreate('record-logistics-proof', 'user');
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $shop->id,
            'module_key' => 'logistics',
            'enabled' => true,
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'in_transit',
        ]);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider->givePermissionTo('record-logistics-proof');
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);
        $assignment = $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $profile->id,
            'status' => 'accepted',
            'assigned_at' => now(),
        ]);

        if ($withArrival) {
            $leg->events()->create([
                'shipment_id' => $leg->shipment_id,
                'event_type' => 'dropoff_arrived',
                'visibility' => 'internal',
                'message' => 'Rider arrived at the customer location.',
                'metadata' => ['delivery_assignment_id' => $assignment->id],
            ]);
        }

        return $includeProfile ? [$shop, $rider, $leg, $profile] : [$shop, $rider, $leg];
    }

    private function photo(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 10, 'image/jpeg');
    }
}
