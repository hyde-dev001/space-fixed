<?php

namespace Tests\Feature\Logistics;

use App\Enums\Logistics\RiderProgressState;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RiderMyDeliveriesPageTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shop;

    private User $user;

    private RiderProfile $rider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->shop = ShopOwner::factory()->create();
        $this->user = User::factory()->create(['shop_owner_id' => $this->shop->id]);
        $this->user->assignRole('Logistics Rider');
        $this->rider = RiderProfile::factory()->create([
            'shop_owner_id' => $this->shop->id,
            'linked_type' => User::class,
            'linked_id' => $this->user->id,
        ]);
    }

    public function test_it_returns_one_current_item_and_the_next_scheduled_work(): void
    {
        [$activeBatch, $activeLeg] = $this->batchWithLeg(
            'in_progress',
            'repair_pickup',
            ['started_at' => '2026-07-29 08:00:00'],
            ['status' => 'in_transit'],
        );
        $this->assign($activeLeg, $this->rider, 'accepted');

        [, $nextLeg] = $this->shipmentWithLeg('retail_delivery', [
            'status' => 'assigned',
            'scheduled_delivery_date' => '2026-07-30',
            'delivery_window' => 'morning',
        ]);
        $this->assign($nextLeg, $this->rider, 'accepted');

        $otherUser = User::factory()->create(['shop_owner_id' => $this->shop->id]);
        $otherRider = RiderProfile::factory()->create([
            'shop_owner_id' => $this->shop->id,
            'linked_type' => User::class,
            'linked_id' => $otherUser->id,
        ]);
        [, $otherLeg] = $this->shipmentWithLeg();
        $this->assign($otherLeg, $otherRider);

        $props = $this->deliveryData();

        $this->assertSame("batch:{$activeBatch->id}", $props['current']['key']);
        $this->assertSame("single:{$nextLeg->id}", $props['up_next']['key']);
        $this->assertFalse($props['has_active_conflict']);
        $this->assertNotContains("single:{$otherLeg->id}", $this->allKeys($props));
    }

    public function test_business_filter_changes_only_the_lower_list(): void
    {
        [$currentBatch, $currentLeg] = $this->batchWithLeg(
            'in_progress',
            'retail_delivery',
            ['started_at' => '2026-07-29 08:00:00'],
            ['status' => 'in_transit'],
        );
        $this->assign($currentLeg, $this->rider, 'accepted');

        [, $repairLeg] = $this->shipmentWithLeg('repair_return', [
            'status' => 'assigned',
            'scheduled_delivery_date' => '2026-07-30',
        ]);
        $this->assign($repairLeg, $this->rider);

        $props = $this->deliveryData('?tab=all&business=repair');

        $this->assertSame("batch:{$currentBatch->id}", $props['current']['key']);
        $this->assertSame(["single:{$repairLeg->id}"], collect($props['list']['data'])->pluck('key')->all());
    }

    public function test_issues_are_delivery_rows_with_their_parent_batch(): void
    {
        [$batch, $leg] = $this->batchWithLeg(
            'in_progress',
            'retail_delivery',
            ['started_at' => '2026-07-29 08:00:00'],
            ['status' => 'delivery_attempted'],
        );
        $assignment = $this->assign($leg, $this->rider, 'accepted');
        $attempt = $leg->attempts()->create([
            'delivery_assignment_id' => $assignment->id,
            'delivery_batch_id' => $batch->id,
            'attempt_type' => 'delivery',
            'status' => 'failed',
            'reason_code' => 'customer_unavailable',
            'attempted_at' => '2026-07-29 09:00:00',
        ]);

        $issue = $this->deliveryData('?tab=issues')['list']['data'][0];

        $this->assertSame('issue', $issue['item_type']);
        $this->assertSame($attempt->id, $issue['id']);
        $this->assertSame($leg->id, $issue['delivery_id']);
        $this->assertSame("batch:{$batch->id}", $issue['parent_key']);
    }

    public function test_declined_batches_appear_in_history_from_rejected_assignments(): void
    {
        [$batch, $leg] = $this->batchWithLeg('draft');
        $batch->update(['rider_profile_id' => null, 'rejected_at' => '2026-07-29 08:00:00']);
        $this->assign($leg, $this->rider, 'rejected', ['rejected_at' => '2026-07-29 08:00:00']);

        $item = $this->deliveryData('?tab=history')['list']['data'][0];

        $this->assertSame("batch:{$batch->id}", $item['key']);
        $this->assertSame('declined', $item['status']);
        $this->assertSame('history', $item['group']);
    }

    public function test_all_contains_each_work_item_once(): void
    {
        [$batch, $leg] = $this->batchWithLeg('offered', 'retail_delivery', [
            'offered_at' => '2026-07-29 08:00:00',
        ]);
        $this->assign($leg, $this->rider);

        [, $single] = $this->shipmentWithLeg('repair_pickup', ['status' => 'assigned']);
        $this->assign($single, $this->rider);

        $props = $this->deliveryData('?tab=all');
        $keys = collect($props['list']['data'])->pluck('key')->all();

        $this->assertContains("batch:{$batch->id}", $keys);
        $this->assertContains("single:{$single->id}", $keys);
        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function test_reassigned_standalone_work_is_history_not_active_work(): void
    {
        [, $leg] = $this->shipmentWithLeg('retail_delivery', ['status' => 'assigned']);
        $this->assign($leg, $this->rider);
        $otherRider = RiderProfile::factory()->create(['shop_owner_id' => $this->shop->id]);
        $this->assign($leg, $otherRider, 'assigned', ['assigned_at' => now()->addMinute()]);

        $props = $this->deliveryData('?tab=history');
        $item = collect($props['list']['data'])->firstWhere('key', "single:{$leg->id}");

        $this->assertNull($props['up_next']);
        $this->assertSame('reassigned', $item['status']);
        $this->assertSame('history', $item['group']);
    }

    public function test_earliest_started_work_is_current_and_the_rest_are_conflicts(): void
    {
        [$batch, $batchLeg] = $this->batchWithLeg(
            'in_progress',
            'retail_delivery',
            ['started_at' => '2026-07-29 09:00:00'],
            ['status' => 'in_transit'],
        );
        $this->assign($batchLeg, $this->rider, 'accepted');

        [, $single] = $this->shipmentWithLeg('repair_pickup', [
            'status' => 'in_transit',
            'picked_up_at' => '2026-07-29 08:00:00',
        ]);
        $this->assign($single, $this->rider, 'accepted');

        $props = $this->deliveryData();

        $this->assertSame("single:{$single->id}", $props['current']['key']);
        $this->assertSame(["batch:{$batch->id}"], collect($props['active_conflicts'])->pluck('key')->all());
        $this->assertTrue($props['has_active_conflict']);
        $this->assertSame('conflict', $props['active_conflicts'][0]['group']);
    }

    public function test_offer_order_falls_back_to_offered_at_without_a_response_deadline(): void
    {
        [$later, $laterLeg] = $this->batchWithLeg('offered', 'retail_delivery', [
            'offered_at' => '2026-07-29 09:00:00',
        ]);
        $this->assign($laterLeg, $this->rider);
        [$earlier, $earlierLeg] = $this->batchWithLeg('offered', 'repair_pickup', [
            'offered_at' => '2026-07-29 08:00:00',
        ]);
        $this->assign($earlierLeg, $this->rider);

        $offers = $this->deliveryData()['offers'];

        $this->assertSame(["batch:{$earlier->id}", "batch:{$later->id}"], collect($offers)->pluck('key')->all());
        $this->assertSame([null, null], collect($offers)->pluck('response_deadline')->all());
    }

    public function test_up_next_is_not_duplicated_in_the_upcoming_list(): void
    {
        [, $first] = $this->shipmentWithLeg('retail_delivery', [
            'status' => 'assigned',
            'scheduled_delivery_date' => '2026-07-30',
            'delivery_window' => 'morning',
        ]);
        $this->assign($first, $this->rider, 'accepted', ['assigned_at' => '2026-07-29 08:00:00']);
        [, $second] = $this->shipmentWithLeg('repair_pickup', [
            'status' => 'assigned',
            'scheduled_delivery_date' => '2026-07-30',
            'delivery_window' => 'afternoon',
        ]);
        $this->assign($second, $this->rider, 'accepted', ['assigned_at' => '2026-07-29 09:00:00']);

        $upcoming = $this->deliveryData();
        $all = $this->deliveryData('?tab=all');

        $this->assertSame("single:{$first->id}", $upcoming['up_next']['key']);
        $this->assertSame(["single:{$second->id}"], collect($upcoming['list']['data'])->pluck('key')->all());
        $this->assertSame(1, collect($all['list']['data'])->where('key', "single:{$first->id}")->count());
    }

    public function test_standalone_payload_contains_only_the_current_riders_assignments(): void
    {
        [, $leg] = $this->shipmentWithLeg('retail_delivery', ['status' => 'assigned']);
        $otherRider = RiderProfile::factory()->create(['shop_owner_id' => $this->shop->id]);
        $this->assign($leg, $otherRider, 'rejected', ['assigned_at' => '2026-07-29 08:00:00']);
        $assignment = $this->assign($leg, $this->rider, 'accepted', ['assigned_at' => '2026-07-29 09:00:00']);

        $delivery = $this->deliveryData()['up_next']['deliveries'][0];

        $this->assertSame([$assignment->id], collect($delivery['assignments'])->pluck('id')->all());
    }

    public function test_staged_delivery_retry_is_current_and_exposes_only_rider_resolution_fields(): void
    {
        [, $leg] = $this->shipmentWithLeg('retail_delivery', [
            'status' => 'needs_resolution',
            'resolution_type' => 'retry',
            'resolution_reason' => 'Customer requested another attempt.',
            'scheduled_delivery_date' => '2026-07-30',
        ]);
        $this->assign($leg, $this->rider, 'accepted');

        $props = $this->deliveryData();
        $delivery = $props['current']['deliveries'][0];

        $this->assertSame("single:{$leg->id}", $props['current']['key']);
        $this->assertSame('needs_resolution', $delivery['status']);
        $this->assertSame('retry', $delivery['resolution_type']);
        $this->assertSame('Customer requested another attempt.', $delivery['resolution_reason']);
        $this->assertStringStartsWith('2026-07-30', $delivery['scheduled_delivery_date']);
        $this->assertArrayNotHasKey('search_text', $props['current']);
    }

    public function test_exhausted_custody_hold_is_visible_as_current_work(): void
    {
        [, $leg] = $this->shipmentWithLeg('retail_delivery', [
            'status' => 'needs_resolution',
            'resolution_type' => null,
        ]);
        $this->assign($leg, $this->rider, 'accepted');

        $props = $this->deliveryData();
        $delivery = $props['current']['deliveries'][0];

        $this->assertSame("single:{$leg->id}", $props['current']['key']);
        $this->assertSame('needs_resolution', $delivery['status']);
        $this->assertNull($delivery['resolution_type']);
    }

    public function test_review_only_batch_is_not_current_work_while_business_batch_stays_in_progress(): void
    {
        [$batch, $first] = $this->batchWithLeg(
            'in_progress',
            'retail_delivery',
            ['started_at' => '2026-07-29 08:00:00'],
            [
                'status' => 'awaiting_proof_approval',
                'rider_progress_state' => RiderProgressState::PROOF_SUBMITTED,
            ],
        );
        $this->assign($first, $this->rider, 'accepted');
        [, $second] = $this->shipmentWithLeg('retail_delivery', [
            'delivery_batch_id' => $batch->id,
            'stop_sequence' => 2,
            'status' => 'proof_correction_required',
            'rider_progress_state' => RiderProgressState::PROOF_ACTION_REQUIRED,
        ]);
        $this->assign($second, $this->rider, 'accepted');

        $props = $this->deliveryData('?tab=history');

        $this->assertNull($props['current']);
        $this->assertFalse($props['has_active_conflict']);
        $item = collect($props['list']['data'])->firstWhere('key', "batch:{$batch->id}");
        $this->assertSame('review_pending', $item['status']);
        $this->assertSame('history', $item['group']);
    }

    public function test_batch_with_a_later_active_stop_remains_current_after_first_proof_submission(): void
    {
        [$batch, $first] = $this->batchWithLeg(
            'in_progress',
            'retail_delivery',
            ['started_at' => '2026-07-29 08:00:00'],
            [
                'status' => 'awaiting_proof_approval',
                'rider_progress_state' => RiderProgressState::PROOF_SUBMITTED,
            ],
        );
        $this->assign($first, $this->rider, 'accepted');
        [, $next] = $this->shipmentWithLeg('retail_delivery', [
            'delivery_batch_id' => $batch->id,
            'stop_sequence' => 2,
            'status' => 'in_transit',
            'rider_progress_state' => RiderProgressState::ACTIVE,
        ]);
        $this->assign($next, $this->rider, 'accepted');

        $props = $this->deliveryData();

        $this->assertSame("batch:{$batch->id}", $props['current']['key']);
        $this->assertSame(
            RiderProgressState::ACTIVE->value,
            collect($props['current']['deliveries'])->firstWhere('id', $next->id)['rider_progress_state'],
        );
    }

    public function test_submitted_standalone_proof_is_history_instead_of_current_work(): void
    {
        [, $leg] = $this->shipmentWithLeg('retail_delivery', [
            'status' => 'awaiting_proof_approval',
            'rider_progress_state' => RiderProgressState::PROOF_SUBMITTED,
        ]);
        $this->assign($leg, $this->rider, 'accepted');

        $props = $this->deliveryData('?tab=history');

        $this->assertNull($props['current']);
        $item = collect($props['list']['data'])->firstWhere('key', "single:{$leg->id}");
        $this->assertSame('history', $item['group']);
        $this->assertSame(RiderProgressState::PROOF_SUBMITTED->value, $item['deliveries'][0]['rider_progress_state']);
    }

    public function test_rejected_delivery_proof_is_an_issue_with_replacement_metadata(): void
    {
        [, $leg] = $this->shipmentWithLeg('retail_delivery', [
            'status' => 'proof_correction_required',
            'rider_progress_state' => RiderProgressState::PROOF_ACTION_REQUIRED,
        ]);
        $this->assign($leg, $this->rider, 'accepted');
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'review_status' => 'rejected',
            'rejection_reason' => 'The delivery image is not readable.',
        ]);

        $props = $this->deliveryData('?tab=issues');
        $issue = $props['list']['data'][0];

        $this->assertSame('proof_correction', $issue['issue_type']);
        $this->assertSame($leg->id, $issue['delivery_id']);
        $this->assertSame($proof->id, $issue['proof_id']);
        $this->assertTrue($issue['replacement_allowed']);
        $this->assertSame('The delivery image is not readable.', $issue['reason']);
    }

    private function deliveryData(string $query = ''): array
    {
        return $this->actingAs($this->user->fresh(), 'user')
            ->get('/erp/logistics/deliveries'.$query)
            ->assertOk()
            ->viewData('page')['props']['deliveryData'];
    }

    private function shipmentWithLeg(string $purpose = 'retail_delivery', array $legAttributes = []): array
    {
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $this->shop->id,
            'purpose' => $purpose,
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'destination_snapshot' => [
                'name' => 'Miguel Dela Rosa',
                'phone' => '09123456789',
                'address' => 'Block 20 Lot 20, Dasmarinas, Cavite',
            ],
            ...$legAttributes,
        ]);

        return [$shipment, $leg];
    }

    private function batchWithLeg(
        string $status,
        string $purpose = 'retail_delivery',
        array $batchAttributes = [],
        array $legAttributes = [],
    ): array {
        $batch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $this->shop->id,
            'rider_profile_id' => $this->rider->id,
            'status' => $status,
            ...$batchAttributes,
        ]);
        [, $leg] = $this->shipmentWithLeg($purpose, [
            'delivery_batch_id' => $batch->id,
            'stop_sequence' => 1,
            ...$legAttributes,
        ]);

        return [$batch, $leg];
    }

    private function assign(
        ShipmentLeg $leg,
        RiderProfile $rider,
        string $status = 'assigned',
        array $attributes = [],
    ): DeliveryAssignment {
        return DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => $status,
            ...$attributes,
        ]);
    }

    private function allKeys(array $props): array
    {
        return collect($props['offers'])
            ->concat([$props['current']])
            ->concat($props['active_conflicts'])
            ->concat([$props['up_next']])
            ->concat($props['list']['data'])
            ->filter()
            ->pluck('key')
            ->all();
    }
}
