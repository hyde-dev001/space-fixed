<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\ShipmentCustodyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ShipmentCustodyTransferTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('local');
        Storage::disk('local')->put('logistics-proof/audit.jpg', 'synthetic evidence');
        $shop = ShopOwner::factory()->approved()->withLogistics()->create(['registration_type' => 'company']);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo(Permission::findOrCreate('resolve-logistics-exceptions', 'user'));
        $this->clockInEmployee($dispatcher);
        $a = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $b = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        foreach ([$a, $b] as $rider) {
            User::findOrFail($rider->linked_id)->givePermissionTo(Permission::findOrCreate('update-logistics-status', 'user'));
        }
        $leg = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id, 'status' => 'needs_resolution', 'picked_up_at' => now()]);
        $assignment = DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $b->id, 'status' => 'accepted']);
        return [$dispatcher, $a, $b, $leg, $assignment];
    }

    public function test_unknown_historical_custody_cannot_be_inferred_from_assignment(): void
    {
        [$dispatcher, , $b, $leg] = $this->fixture();
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/legs/{$leg->id}/custody-transfers", [
            'to_type' => 'rider', 'to_rider_profile_id' => $b->id, 'request_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('custody');
    }

    public function test_handoff_cards_include_scoped_source_references_without_storage_paths(): void
    {
        [$dispatcher, $a, $b, $leg] = $this->fixture();
        $order = \App\Models\Order::factory()->create(['shop_owner_id' => $dispatcher->shop_owner_id, 'order_number' => 'ORD-HANDOFF']);
        $leg->shipment->update(['source_type' => 'order', 'source_id' => $order->id]);
        $leg->update(['custody_state' => 'rider', 'custody_rider_profile_id' => $a->id, 'custody_confirmed_at' => now()]);
        $service = app(ShipmentCustodyService::class);
        $service->request($leg->fresh(), $dispatcher, 'rider', $b->id, (string) Str::uuid());
        $card = $service->pendingFor(User::findOrFail($a->linked_id))[0];
        $this->assertSame('Order ORD-HANDOFF', $card['source_reference'] ?? null);
        $this->assertStringContainsString('/erp/logistics/deliveries?', $card['details_url']);
        $this->assertArrayNotHasKey('file_path', $card);
    }

    public function test_repair_handoff_uses_the_real_request_reference_column(): void
    {
        [$dispatcher, $a, $b, $leg] = $this->fixture();
        $repair = \App\Models\RepairRequest::factory()->create(['shop_owner_id' => $dispatcher->shop_owner_id, 'request_id' => 'REP-HANDOFF']);
        $leg->shipment->update(['source_type' => 'repair_request', 'source_id' => $repair->id]);
        $leg->update(['custody_state' => 'rider', 'custody_rider_profile_id' => $a->id, 'custody_confirmed_at' => now()]);
        $service = app(ShipmentCustodyService::class);
        $service->request($leg->fresh(), $dispatcher, 'rider', $b->id, (string) Str::uuid());
        $this->assertSame('Repair REP-HANDOFF', $service->pendingFor(User::findOrFail($a->linked_id))[0]['source_reference']);
    }

    public function test_return_handoff_never_projects_another_shops_order_reference(): void
    {
        [$dispatcher, $a, $b, $leg] = $this->fixture();
        $foreignOrder = \App\Models\Order::factory()->create(['order_number' => 'FOREIGN-PRIVATE-ORDER']);
        $return = \App\Models\OrderRefund::factory()->create(['shop_owner_id' => $dispatcher->shop_owner_id, 'order_id' => $foreignOrder->id]);
        $leg->shipment->update(['source_type' => 'order_refund', 'source_id' => $return->id]);
        $leg->update(['custody_state' => 'rider', 'custody_rider_profile_id' => $a->id, 'custody_confirmed_at' => now()]);
        $service = app(ShipmentCustodyService::class);
        $service->request($leg->fresh(), $dispatcher, 'rider', $b->id, (string) Str::uuid());
        $this->assertStringNotContainsString('FOREIGN-PRIVATE-ORDER', $service->pendingFor(User::findOrFail($a->linked_id))[0]['source_reference']);
    }

    public function test_release_alone_does_not_transfer_possession_and_only_target_can_receive(): void
    {
        [$dispatcher, $a, $b, $leg] = $this->fixture();
        $leg->forceFill(['custody_state' => 'rider', 'custody_rider_profile_id' => $a->id, 'custody_confirmed_at' => now()])->save();
        $service = app(ShipmentCustodyService::class);
        $transfer = $service->request($leg, $dispatcher, 'rider', $b->id, (string) Str::uuid());
        $this->assertSame($a->id, $leg->fresh()->custody_rider_profile_id);
        $source = User::findOrFail($a->linked_id);
        $target = User::findOrFail($b->linked_id);
        $service->release($transfer, $source, 'logistics-proof/audit.jpg');
        $this->assertSame($a->id, $leg->fresh()->custody_rider_profile_id);
        try {
            $service->receive($transfer, $source, 'logistics-proof/audit.jpg');
            $this->fail('Releasing rider confirmed the target receipt.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame($a->id, $leg->fresh()->custody_rider_profile_id);
        }
        $service->receive($transfer, $target, 'logistics-proof/audit.jpg');
        $service->receive($transfer, $target, 'logistics-proof/audit.jpg');
        $this->assertSame($b->id, $leg->fresh()->custody_rider_profile_id);
        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame(2, $leg->proofs()->whereIn('handoff_type', ['custody_release', 'custody_receipt'])->count());
        Storage::disk('local')->put('logistics-proof/changed.jpg', 'different parcel evidence');
        try {
            $service->receive($transfer, $target, 'logistics-proof/changed.jpg');
            $this->fail('A completed handoff accepted changed evidence as an identical replay.');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('custody', $exception->errors()); }
        $this->assertSame(2, $leg->proofs()->whereIn('handoff_type', ['custody_release', 'custody_receipt'])->count());
    }

    public function test_shop_mediation_requires_both_confirmed_transfers(): void
    {
        [$dispatcher, $a, $b, $leg] = $this->fixture();
        $leg->forceFill(['custody_state' => 'rider', 'custody_rider_profile_id' => $a->id, 'custody_confirmed_at' => now()])->save();
        $service = app(ShipmentCustodyService::class);
        $first = $service->request($leg, $dispatcher, 'shop', null, (string) Str::uuid());
        $service->release($first, User::findOrFail($a->linked_id), 'logistics-proof/audit.jpg');
        $service->receive($first, $dispatcher, 'logistics-proof/audit.jpg');
        $this->assertSame('shop', $leg->fresh()->custody_state);
        $second = $leg->custodyTransfers()->where('status', 'requested')->firstOrFail();
        $this->assertSame($b->id, $second->to_rider_profile_id);
        $service->release($second, $dispatcher, 'logistics-proof/audit.jpg');
        $this->assertSame('shop', $leg->fresh()->custody_state);
        $service->receive($second, User::findOrFail($b->linked_id), 'logistics-proof/audit.jpg');
        $this->assertSame($b->id, $leg->fresh()->custody_rider_profile_id);
    }

    public function test_competing_transfer_is_rejected_without_overwriting_the_first(): void
    {
        [$dispatcher, $a, $b, $leg] = $this->fixture();
        $leg->forceFill(['custody_state' => 'rider', 'custody_rider_profile_id' => $a->id, 'custody_confirmed_at' => now()])->save();
        $service = app(ShipmentCustodyService::class);
        $key = (string) Str::uuid();
        $transfer = $service->request($leg, $dispatcher, 'rider', $b->id, $key);
        $this->assertSame($transfer->id, $service->request($leg, $dispatcher, 'rider', $b->id, $key)->id);
        $this->expectException(ValidationException::class);
        $service->request($leg, $dispatcher, 'shop', null, (string) Str::uuid());
    }

    public function test_a_reassigned_rider_cannot_start_with_the_previous_pickup_proof(): void
    {
        [, $a, $b, $leg] = $this->fixture();
        $leg->update(['status' => 'assigned', 'requires_pickup_proof' => true]);
        $old = DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $a->id, 'status' => 'cancelled']);
        \App\Models\Logistics\HandoffProof::factory()->create(['shipment_leg_id' => $leg->id, 'handoff_type' => 'pickup', 'delivery_assignment_id' => $old->id]);
        $this->expectException(ValidationException::class);
        app(\App\Services\Logistics\ShipmentLegService::class)->markPickedUp($leg->fresh(), $b);
    }

    public function test_canceling_an_unreleased_handoff_preserves_the_holder_and_released_handoffs_cannot_be_canceled(): void
    {
        [$dispatcher, $a, $b, $leg] = $this->fixture();
        $leg->update(['custody_state' => 'rider', 'custody_rider_profile_id' => $a->id, 'custody_confirmed_at' => now()]);
        $service = app(ShipmentCustodyService::class);
        $first = $service->request($leg, $dispatcher, 'rider', $b->id, (string) Str::uuid());
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/custody-transfers/{$first->id}/cancel", ['reason' => 'Rider unavailable'])->assertOk();
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame($a->id, $leg->fresh()->custody_rider_profile_id);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $b->id, 'status' => 'accepted']);
        $second = $service->request($leg->fresh(), $dispatcher, 'rider', $b->id, (string) Str::uuid());
        $service->release($second, User::findOrFail($a->linked_id), 'logistics-proof/audit.jpg');
        $this->postJson("/api/logistics/custody-transfers/{$second->id}/cancel", ['reason' => 'Do not erase a physical release'])->assertUnprocessable();
        $this->assertSame('released', $second->fresh()->status);
    }
    public function test_already_released_parcel_receipt_can_be_recorded_after_logistics_is_disabled(): void
    {
        [$dispatcher, $a, $b, $leg] = $this->fixture();
        $leg->update(['shop_owner_id' => $dispatcher->shop_owner_id, 'custody_state' => 'rider', 'custody_rider_profile_id' => $a->id, 'custody_confirmed_at' => now()]);
        $service = app(ShipmentCustodyService::class);
        $transfer = $service->request($leg, $dispatcher, 'rider', $b->id, (string) Str::uuid());
        $service->release($transfer, User::findOrFail($a->linked_id), 'logistics-proof/audit.jpg');
        \App\Models\ShopOwnerModule::where('shop_owner_id', $dispatcher->shop_owner_id)->where('module_key', 'logistics')->update(['enabled' => false]);
        $service->receive($transfer, User::findOrFail($b->linked_id), 'logistics-proof/audit.jpg');
        $this->assertSame($b->id, $leg->fresh()->custody_rider_profile_id);
        $leg->update(['resolution_type' => 'retry']);
        $this->expectException(ValidationException::class);
        app(\App\Services\Logistics\ShipmentLegService::class)->markInTransit($leg->fresh(), $b);
    }
    public function test_shop_receipt_while_disabled_does_not_roll_back_for_a_new_outbound_offer(): void
    {
        [$dispatcher, $a, $b, $leg] = $this->fixture();
        $leg->update(['shop_owner_id' => $dispatcher->shop_owner_id, 'custody_state' => 'rider', 'custody_rider_profile_id' => $a->id, 'custody_confirmed_at' => now()]);
        $service = app(ShipmentCustodyService::class);
        $transfer = $service->request($leg, $dispatcher, 'shop', null, (string) Str::uuid());
        $service->release($transfer, User::findOrFail($a->linked_id), 'logistics-proof/audit.jpg');
        \App\Models\ShopOwnerModule::where('shop_owner_id', $dispatcher->shop_owner_id)->where('module_key', 'logistics')->update(['enabled' => false]);
        $service->receive($transfer, $dispatcher, 'logistics-proof/audit.jpg');
        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame('shop', $leg->fresh()->custody_state);
        $this->assertFalse($leg->custodyTransfers()->where('status', 'requested')->exists());
        \App\Models\ShopOwnerModule::where('shop_owner_id', $dispatcher->shop_owner_id)->where('module_key', 'logistics')->update(['enabled' => true]);
        $next = $service->request($leg->fresh(), $dispatcher, 'rider', $b->id, (string) Str::uuid());
        $this->assertSame('requested', $next->status);
    }

    public function test_return_resolution_waits_for_an_outstanding_physical_handoff(): void
    {
        [$dispatcher, $a, $b, $leg] = $this->fixture();
        $leg->update(['custody_state' => 'rider', 'custody_rider_profile_id' => $a->id, 'custody_confirmed_at' => now()]);
        app(ShipmentCustodyService::class)->request($leg, $dispatcher, 'rider', $b->id, (string) Str::uuid());
        $this->expectException(ValidationException::class);
        app(\App\Services\Logistics\ShipmentLegService::class)->requireReturn($leg->fresh(), 'Return instead', $dispatcher);
    }

    public function test_canceling_a_stale_transfer_cannot_reopen_a_terminal_delivery(): void
    {
        [$dispatcher, $a, $b, $leg] = $this->fixture();
        $leg->update(['custody_state' => 'rider', 'custody_rider_profile_id' => $a->id, 'custody_confirmed_at' => now()]);
        $service = app(ShipmentCustodyService::class);
        $transfer = $service->request($leg, $dispatcher, 'rider', $b->id, (string) Str::uuid());
        $leg->update(['status' => 'cancelled', 'resolution_type' => 'loss_confirmed']);
        try {
            $service->cancel($transfer, $dispatcher, 'Stale cancel');
            $this->fail('A stale transfer reopened the terminal delivery.');
        } catch (ValidationException) {
            $this->assertSame('cancelled', $leg->fresh()->status->value);
            $this->assertSame('loss_confirmed', $leg->fresh()->resolution_type);
        }
    }
    public function test_return_after_canceled_retry_stays_with_the_confirmed_holder(): void
    {
        [$dispatcher, $a, $b, $leg] = $this->fixture();
        $leg->update(['custody_state' => 'rider', 'custody_rider_profile_id' => $a->id, 'custody_confirmed_at' => now()]);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $a->id, 'status' => 'cancelled']);
        $service = app(ShipmentCustodyService::class);
        $transfer = $service->request($leg, $dispatcher, 'rider', $b->id, (string) Str::uuid());
        $service->cancel($transfer, $dispatcher, 'Retry declined');
        app(\App\Services\Logistics\ShipmentLegService::class)->requireReturn($leg->fresh(), 'Return the held parcel', $dispatcher);
        $return = $leg->returnLeg()->firstOrFail();
        $this->assertSame($a->id, $return->custody_rider_profile_id);
        $this->assertSame($a->id, $return->assignments()->value('rider_profile_id'));
        $this->assertFalse($leg->assignments()->whereIn('status', ['assigned', 'accepted'])->exists());
    }
    public function test_transfer_endpoints_preserve_tenant_attendance_and_owner_read_only_guards(): void
    {
        [$dispatcher, $a, $b, $leg] = $this->fixture();
        $leg->update(['custody_state' => 'rider', 'custody_rider_profile_id' => $a->id, 'custody_confirmed_at' => now()]);
        $other = User::factory()->create(['shop_owner_id' => ShopOwner::factory()->approved()->withLogistics()->create(['registration_type' => 'company'])->id]);
        $other->givePermissionTo(Permission::findOrCreate('resolve-logistics-exceptions', 'user'));
        $this->clockInEmployee($other);
        $data = ['to_type' => 'rider', 'to_rider_profile_id' => $b->id, 'request_key' => (string) Str::uuid()];
        $this->actingAs($other, 'user')->postJson('/api/logistics/legs/'.$leg->id.'/custody-transfers', $data)->assertForbidden();
        \Illuminate\Support\Facades\DB::table('attendance_records')->where('shop_owner_id', $dispatcher->shop_owner_id)->update(['check_out_time' => now()->format('H:i:s')]);
        $this->actingAs($dispatcher, 'user')->postJson('/api/logistics/legs/'.$leg->id.'/custody-transfers', $data)->assertStatus(423);
        $this->assertSame(0, $leg->custodyTransfers()->count());
        $this->app['auth']->guard('user')->logout();
        $this->actingAs($leg->shipment->shopOwner, 'shop_owner')->postJson('/api/logistics/legs/'.$leg->id.'/custody-transfers', $data)->assertForbidden();
    }

    public function test_confirmed_loss_closes_pending_transfer_without_erasing_release_evidence(): void
    {
        [$dispatcher, $a, $b, $leg] = $this->fixture();
        $leg->update(['custody_state' => 'rider', 'custody_rider_profile_id' => $a->id, 'custody_confirmed_at' => now()]);
        $custody = app(ShipmentCustodyService::class);
        $transfer = $custody->request($leg, $dispatcher, 'rider', $b->id, (string) Str::uuid());
        $custody->release($transfer, User::findOrFail($a->linked_id), 'logistics-proof/audit.jpg');
        app(\App\Services\Logistics\ShipmentLegService::class)->confirmLoss($leg, 'Investigated synthetic loss');
        $this->assertSame('closed_loss', $transfer->fresh()->status);
        $this->assertNotNull($transfer->fresh()->release_proof_id);
        $this->assertSame('lost', $leg->fresh()->custody_state);
        $this->assertFalse($leg->custodyTransfers()->whereIn('status', ['requested', 'released'])->exists());
    }
    public function test_assignment_only_permission_does_not_cancel_an_exception_resolution(): void
    {
        [$dispatcher, $a, $b, $leg] = $this->fixture();
        $leg->update(['custody_state' => 'rider', 'custody_rider_profile_id' => $a->id, 'custody_confirmed_at' => now()]);
        $transfer = app(ShipmentCustodyService::class)->request($leg, $dispatcher, 'rider', $b->id, (string) Str::uuid());
        $assigner = User::factory()->create(['shop_owner_id' => $dispatcher->shop_owner_id]);
        $assigner->givePermissionTo(Permission::findOrCreate('assign-logistics-deliveries', 'user'));
        $this->clockInEmployee($assigner);
        $this->actingAs($assigner, 'user')->postJson('/api/logistics/custody-transfers/'.$transfer->id.'/cancel', ['reason' => 'Cannot override exception authority'])->assertForbidden();
        $this->assertSame('requested', $transfer->fresh()->status);
    }
}
