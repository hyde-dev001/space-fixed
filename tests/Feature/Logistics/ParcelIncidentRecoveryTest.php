<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryIncident;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\DeliveryIncidentService;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ParcelIncidentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now(config('app.shop_timezone', 'Asia/Manila'))->setDate(2026, 10, 27)->setTime(9, 0));
        Storage::fake('local');
        $shop = ShopOwner::factory()->approved()->withLogistics()->create(['registration_type' => 'company', 'business_type' => 'retail']);
        \App\Models\ShopOwnerModule::factory()->create(['shop_owner_id' => $shop->id, 'module_key' => 'retail_operations', 'enabled' => true]);
        $order = Order::factory()->create(['shop_owner_id' => $shop->id, 'payment_method' => 'cod', 'payment_status' => 'unpaid']);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order', 'purpose' => 'retail_delivery', 'source_id' => $order->id]);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'in_transit',
            'custody_state' => 'rider', 'custody_rider_profile_id' => $rider->id, 'custody_confirmed_at' => now()]);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo(Permission::findOrCreate('resolve-logistics-exceptions', 'user'));
        $this->clockInEmployee($dispatcher);
        $incident = app(DeliveryIncidentService::class)->report($leg, $rider, ['type' => 'vehicle_problem', 'notes' => 'Vehicle failed', 'photo_paths' => ['incident-evidence/test/report.jpg']]);
        return [$shop, $order, $leg, $incident, $dispatcher];
    }

    private function historicalLoss(ShipmentLeg $leg, DeliveryIncident $incident, string $note): void
    {
        app(\App\Services\Logistics\ShipmentLegService::class)->confirmLoss($leg, $note);
        $incident->forceFill(['type' => 'lost', 'resolution' => 'loss_confirmed', 'status' => 'under_review',
            'decided_at' => now(), 'revision' => 1, 'resolution_note' => $note, 'recovery_context' => []])->save();
    }

    public function test_return_authorization_uses_real_custody_without_verified_findings_checkboxes(): void
    {
        [, , $leg, $incident, $dispatcher] = $this->fixture();
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/incidents/{$incident->id}/resolve", [
            'resolution' => 'return_required', 'note' => 'Bring the parcel to the original shop for inspection',
            'request_key' => (string) Str::uuid(), 'expected_revision' => 0,
        ])->assertOk();
        $this->assertSame('return_required', $incident->fresh()->resolution);
        $this->assertNull($incident->fresh()->resolved_at);
        $this->assertSame('rider', $leg->returnLeg()->firstOrFail()->custody_state);
        $this->assertSame('shop', $leg->returnLeg->destination_snapshot['type']);
    }

    public function test_returned_retail_goods_complete_physical_handling_once_and_leave_financial_review_actionable(): void
    {
        [$shop, $order, $leg, $incident, $dispatcher] = $this->fixture();
        $order->update(['payment_method' => 'paymongo_card', 'payment_status' => 'paid', 'paymongo_payment_id' => 'pay_synthetic_return']);
        $product = \App\Models\Product::create(['shop_owner_id' => $shop->id, 'stock_quantity' => 8,
            'name' => 'Returned goods', 'slug' => 'returned-goods', 'price' => 100, 'is_active' => true]);
        $item = \App\Models\OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id,
            'product_name' => 'Returned goods', 'price' => 100, 'quantity' => 1, 'subtotal' => 100]);
        app(DeliveryIncidentService::class)->resolve($incident, $shop, 'return_required', 'Return for actual receipt', [], $dispatcher);
        $return = $leg->returnLeg()->firstOrFail();
        $proof = \App\Models\Logistics\HandoffProof::factory()->create(['shipment_leg_id' => $return->id, 'handoff_type' => 'receive', 'review_status' => 'rider_confirmed']);
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        $staff->givePermissionTo(Permission::findOrCreate('access-staff-job-orders', 'user'));
        $this->clockInEmployee($staff);
        $service = app(\App\Services\Logistics\ParcelIncidentRecoveryService::class);
        $facts = fn ($revision) => ['request_key' => (string) Str::uuid(), 'expected_revision' => $revision, 'notes' => 'Actual returned goods inspected'];
        $service->receive($incident, $staff, [...$facts(1), 'proof_id' => $proof->id, 'evidence_path' => 'incident-evidence/receipt.jpg']);
        $service->inspect($incident->fresh(), $staff, [...$facts(2), 'items' => [['reference' => 'order-item:'.$item->id, 'quantity' => 1, 'condition' => 'safe']]]);
        $data = [...$facts(3), 'action' => 'inventory_disposition'];
        $result = $service->disposition($incident->fresh(), $staff, $data);
        $this->assertSame('resolved', $result['operational_status']);
        $this->assertSame(9, $product->fresh()->stock_quantity);
        $service->disposition($incident->fresh(), $staff, $data);
        $this->assertSame(9, $product->fresh()->stock_quantity);
        $this->actingAs($staff, 'user')->getJson('/api/staff/orders')->assertOk()
            ->assertJsonPath('0.parcel_returns.0.incident_id', $incident->id)
            ->assertJsonPath('0.parcel_returns.0.operational_status', 'resolved');
        $this->assertFalse($result['complete']);
        $this->assertTrue(collect($result['actions'])->firstWhere('action', 'refund_review')['allowed']);
        $review = $service->disposition($incident->fresh(), $staff, [...$facts(4), 'action' => 'refund_review']);
        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertNotEmpty($review['source_outcome']['refund_id']);
        $this->assertNotSame('succeeded', $order->refunds()->firstOrFail()->status);
        $this->assertSame(9, $product->fresh()->stock_quantity);
    }

    public static function staffReturnReviews(): array
    {
        return ['allocated' => ['resellable', 1, false, 8, 'under_review', true], 'resellable' => ['resellable', 1, false, 9, 'resolved'], 'damaged' => ['damaged', 1, false, 8, 'resolved'],
            'partial' => ['missing', 0, false, 8, 'under_review'], 'unsafe' => ['unsafe', 1, false, 8, 'under_review'],
            'paid return' => ['resellable', 1, true, 9, 'resolved']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('staffReturnReviews')]
    public function test_staff_uses_the_normal_return_review_to_receive_inspect_and_handle_rider_returns_once(string $condition, int $quantity, bool $paid, int $stock, string $status, bool $allocated = false): void
    {
        [$shop, $order, $leg, $incident, $dispatcher] = $this->fixture();
        if ($paid) $order->update(['payment_method' => 'paymongo_card', 'payment_status' => 'paid', 'paymongo_payment_id' => 'pay_local_staff_return']);
        $product = \App\Models\Product::create(['shop_owner_id' => $shop->id, 'stock_quantity' => 8,
            'name' => 'Staff reviewed goods', 'slug' => 'staff-reviewed-goods', 'price' => 100, 'is_active' => true]);
        $item = \App\Models\OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'price' => 100, 'quantity' => 1, 'subtotal' => 100]);
        app(DeliveryIncidentService::class)->resolve($incident, $shop, 'return_required', 'Return to the shop', [], $dispatcher);
        $return = $leg->returnLeg()->firstOrFail();
        \App\Models\Logistics\HandoffProof::factory()->create(['shipment_leg_id' => $return->id, 'handoff_type' => 'receive', 'review_status' => 'rider_confirmed']);
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        $staff->givePermissionTo(Permission::findOrCreate('access-staff-job-orders', 'user'));
        $this->clockInEmployee($staff);
        $payload = ['incident_id' => $incident->id, 'request_key' => (string) Str::uuid(), 'expected_revision' => 1,
            'return_notes' => 'Actual item received and reviewed by Staff',
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('staff-return.jpg'),
            'line_dispositions' => [['order_item_id' => $item->id, 'approved_qty' => $quantity, 'inspection_disposition' => $condition]]];
        if ($allocated) {
            $active = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order', 'source_id' => $order->id, 'purpose' => 'retail_delivery', 'source_attempt' => 2]);
            ShipmentLeg::factory()->create(['shipment_id' => $active->id, 'status' => 'pending']);
        }
        $url = "/api/staff/orders/{$order->id}/confirm-return-received";
        $other = Order::factory()->create(['shop_owner_id' => $shop->id]);
        $this->actingAs($staff, 'user')->post("/api/staff/orders/{$other->id}/confirm-return-received", $payload, ['Accept' => 'application/json'])->assertNotFound();
        $this->post($url, [...$payload, 'expected_revision' => 0], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame('rider', $return->fresh()->custody_state);
        $this->post($url, $payload, ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($status, $incident->fresh()->status);
        $this->assertSame($staff->id, data_get($incident->fresh()->recovery_context, 'receipt.actor_id'));
        $this->assertSame($stock, $product->fresh()->stock_quantity);
        $this->assertSame($paid ? 1 : 0, $order->refunds()->count(), 'Only an eligible paid return creates the normal refund request.');
        if ($paid) {
            $refund = $order->refunds()->firstOrFail();
            $this->assertSame('received', $refund->return_status);
            $this->assertNotSame('succeeded', $refund->status);
            $this->assertNotSame('approved', $refund->finance_status);
        }
        $this->post($url, $payload, ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($stock, $product->fresh()->stock_quantity);
        $this->post($url, [...$payload, 'line_dispositions' => [['order_item_id' => $item->id, 'approved_qty' => 1, 'inspection_disposition' => $condition === 'damaged' ? 'resellable' : 'damaged']]], ['Accept' => 'application/json'])->assertUnprocessable();
        if ($status === 'resolved') {
            $response = $this->getJson("/api/staff/orders/{$order->id}")->assertOk();
            if ($paid) $response->assertJsonPath('parcel_returns.0.staff_review_required', false);
            else $response->assertJsonCount(0, 'parcel_returns');
        }
        else $this->assertSame('escalate', data_get($incident->fresh()->recovery_context, 'disposition.action'));
    }

    public function test_return_already_at_shop_does_not_invent_another_rider_trip_or_receipt(): void
    {
        [$shop, , $leg, $incident, $dispatcher] = $this->fixture();
        $leg->update(['custody_state' => 'shop', 'custody_rider_profile_id' => null, 'custody_confirmed_at' => now()]);
        $before = $leg->assignments()->count();
        app(DeliveryIncidentService::class)->resolve($incident, $shop, 'return_required', 'Already at shop; receiving record needs review', [], $dispatcher);
        $this->assertFalse($leg->returnLeg()->exists());
        $this->assertSame($before, $leg->assignments()->count());
        $projection = app(\App\Services\Logistics\ParcelIncidentRecoveryService::class)->project($incident->fresh('leg.shipment'), $dispatcher);
        $this->assertFalse($projection['received']);
        $this->assertFalse($projection['can_inspect']);
        $transfer = \App\Models\Logistics\ShipmentCustodyTransfer::create([
            'shop_owner_id' => $shop->id, 'shipment_leg_id' => $leg->id,
            'request_key' => (string) Str::uuid(), 'from_type' => 'rider',
            'requested_by_user_id' => $dispatcher->id, 'to_type' => 'shop', 'status' => 'received',
            'received_by_user_id' => $dispatcher->id, 'received_at' => now(),
        ]);
        $received = app(\App\Services\Logistics\ParcelIncidentRecoveryService::class)->receive($incident->fresh(), $dispatcher, [
            'request_key' => (string) Str::uuid(), 'expected_revision' => 1, 'notes' => 'Link my actual recorded shop receipt',
            'evidence_path' => 'incident-evidence/actual-receiver.jpg',
        ]);
        $this->assertTrue($received['receipt_recorded']);
        $this->assertSame($dispatcher->id, data_get($incident->fresh()->recovery_context, 'receipt.actor_id'));
        $this->assertSame($transfer->id, data_get($incident->fresh()->recovery_context, 'receipt.transfer_id'));

    }

    public function test_decision_replay_requires_identical_facts_and_preserves_report(): void
    {
        [, , $leg, $incident, $dispatcher] = $this->fixture();
        $payload = ['resolution' => 'dismissed', 'note' => 'Vehicle repaired', 'request_key' => (string) Str::uuid(),
            'expected_revision' => 0, 'findings' => ['mitigation_plan' => 'Vehicle repaired and the operational route is available', 'obstacle_resolved' => true]];
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/incidents/{$incident->id}/resolve", $payload)->assertOk();
        $events = $leg->events()->count();
        $this->postJson("/api/logistics/incidents/{$incident->id}/resolve", $payload)->assertOk();
        $this->assertSame($events, $leg->events()->count());
        $this->postJson("/api/logistics/incidents/{$incident->id}/resolve", [...$payload, 'note' => 'Different facts'])->assertUnprocessable();
        $this->assertSame('Vehicle failed', $incident->fresh()->notes);
        $this->assertSame(['incident-evidence/test/report.jpg'], $incident->fresh()->photo_paths);
    }

    public function test_decision_requires_revision_and_idempotency_identity(): void
    {
        [, , , $incident, $dispatcher] = $this->fixture();
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/incidents/{$incident->id}/resolve", [
            'resolution' => 'dismissed', 'note' => 'Vehicle repaired', 'findings' => ['mitigation_plan' => 'Vehicle repaired and the operational route is available', 'obstacle_resolved' => true],
        ])->assertUnprocessable()->assertJsonValidationErrors(['request_key', 'expected_revision']);
        $this->assertSame('reported', $incident->fresh()->status);
    }

    public function test_stop_incident_retry_detaches_only_that_stop_and_can_be_accepted(): void
    {
        [$shop, , $leg, $incident, $dispatcher] = $this->fixture();
        $old = $leg->assignments()->latest('id')->firstOrFail();
        $batch = \App\Models\Logistics\DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id, 'rider_profile_id' => $old->rider_profile_id,
            'status' => 'in_progress', 'started_at' => now(), 'stop_snapshot' => [['id' => $leg->id, 'sequence' => 1]]]);
        $leg->update(['delivery_batch_id' => $batch->id, 'stop_sequence' => 1]);
        $other = ShipmentLeg::factory()->create(['shipment_id' => $leg->shipment_id, 'delivery_batch_id' => $batch->id, 'stop_sequence' => 2]);
        $data = ['resolution' => 'retry', 'note' => 'Vehicle fixed', 'reason' => 'Vehicle fixed',
            'request_key' => (string) Str::uuid(), 'expected_revision' => 0, 'findings' => ['mitigation_plan' => 'Vehicle repaired and the operational route is available', 'obstacle_resolved' => true],
            'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning', 'expected_assignment_id' => $old->id,
            'rider_profile_id' => $old->rider_profile_id, 'handoff_route' => 'direct'];
        \App\Models\Logistics\LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], ['operating_days' => range(1, 7)]);
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/incidents/{$incident->id}/resolve", $data)->assertOk();
        app(\App\Services\Logistics\AssignmentService::class)->respondToOffer($leg->fresh(), $old->riderProfile, true);
        $this->assertNull($leg->fresh()->delivery_batch_id);
        $this->assertSame($batch->id, $other->fresh()->delivery_batch_id);
        $this->assertSame([['id' => $leg->id, 'sequence' => 1]], $batch->fresh()->stop_snapshot);
        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame(1, $batch->fresh()->assigned_stop_count);
    }

    public function test_staff_receives_and_inspects_incident_only_return_without_creating_refund_or_stock(): void
    {
        [$shop, $order, $leg, $incident] = $this->fixture();
        app(DeliveryIncidentService::class)->resolve($incident, $shop, 'return_required', 'Safekeeping return', [], null, [], null, ['safe_for_transport' => true]);
        $return = $leg->returnLeg()->firstOrFail();
        $proof = \App\Models\Logistics\HandoffProof::factory()->create(['shipment_leg_id' => $return->id, 'handoff_type' => 'receive', 'review_status' => 'rider_confirmed']);
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        $staff->givePermissionTo(Permission::findOrCreate('access-staff-job-orders', 'user'));
        $this->clockInEmployee($staff);
        $policy = app(\App\Services\Logistics\LogisticsActorPolicy::class)->decideParcelRecovery($staff, $leg->fresh());
        $this->assertTrue($policy['allowed'], json_encode($policy));
        $this->assertSame('return_required', $incident->fresh()->resolution);
        $this->assertNotNull($incident->fresh()->recovery_context);
        $this->actingAs($shop, 'shop_owner');
        $this->actingAs($staff, 'user')->getJson('/api/logistics/parcel-recovery')->assertOk()
            ->assertJsonPath('recoveries.0.incident_id', $incident->id)->assertJsonPath('recoveries.0.source_id', $order->id);
        $data = ['request_key' => (string) Str::uuid(), 'expected_revision' => 1, 'proof_id' => $proof->id,
            'notes' => 'Received sealed parcel', 'photo' => \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg')];
        $this->post("/api/logistics/parcel-recovery/{$incident->id}/receipt", $data, ['Accept' => 'application/json'])->assertOk();
        $beforeFiles = count(Storage::disk('local')->allFiles());
        $this->post("/api/logistics/parcel-recovery/{$incident->id}/receipt", [...$data,
            'request_key' => (string) Str::uuid(), 'expected_revision' => 2, 'notes' => 'Conflicting second receipt',
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('second.jpg')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame($beforeFiles, count(Storage::disk('local')->allFiles()));
        $this->assertSame('Received sealed parcel', data_get($incident->fresh()->recovery_context, 'receipt.notes'));
        $this->assertSame(User::class, $proof->fresh()->reviewed_by_type);
        $this->assertSame($staff->id, (int) $proof->fresh()->reviewed_by_id);
        $this->assertSame('shop', $leg->fresh()->custody_state);
        $this->assertSame('under_review', $incident->fresh()->status);
        $this->assertSame(0, \App\Models\OrderRefund::where('order_id', $order->id)->count());
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->postJson("/api/logistics/parcel-recovery/{$incident->id}/inspection", [
            'request_key' => (string) Str::uuid(), 'expected_revision' => $incident->fresh()->revision,
            'notes' => 'Packaging and contents inspected', 'items' => [['reference' => 'parcel', 'quantity' => 1, 'condition' => 'safe']],
        ])->assertOk();
        $this->assertSame('under_review', $incident->fresh()->status);
        $this->assertSame(0, \App\Models\OrderRefund::where('order_id', $order->id)->count());
    }

    public function test_recovery_endpoint_does_not_grant_owner_or_wrong_shop_access(): void
    {
        [$shop, , , $incident] = $this->fixture();
        $this->actingAs($shop, 'shop_owner')->getJson('/api/logistics/parcel-recovery')->assertUnauthorized();
        $foreign = User::factory()->create(['shop_owner_id' => ShopOwner::factory()->approved()->withLogistics()->create(['registration_type' => 'company'])->id]);
        $foreign->givePermissionTo(Permission::findOrCreate('access-staff-job-orders', 'user'));
        $this->clockInEmployee($foreign);
        $this->actingAs($foreign, 'user')->postJson("/api/logistics/parcel-recovery/{$incident->id}/inspection", [
            'request_key' => (string) Str::uuid(), 'expected_revision' => 0, 'notes' => 'Forged receipt', 'items' => [],
        ])->assertForbidden();
    }

    public static function viaShopModes(): array { return ['enabled' => [false], 'closing while disabled' => [true], 'Rider temporarily unavailable' => [false, true]]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('viaShopModes')]
    public function test_staff_shop_transfer_receipt_does_not_need_dispatcher_transfer_creation(bool $disabled, bool $unavailable = false): void
    {
        [$shop, , $leg, $incident, $dispatcher] = $this->fixture();
        \App\Models\Logistics\LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], ['operating_days' => range(1, 7)]);
        $profile = $leg->assignments()->latest('id')->firstOrFail()->riderProfile;
        $holder = User::findOrFail($profile->linked_id);
        $holder->givePermissionTo(Permission::findOrCreate('update-logistics-status', 'user'));
        app(DeliveryIncidentService::class)->resolve($incident, $shop, 'retry', 'Via shop inspection', [], $dispatcher,
            ['delivery_date' => now()->toDateString(), 'delivery_window' => 'morning', 'rider_profile_id' => $profile->id,
                'request_key' => (string) Str::uuid(), 'handoff_route' => 'via_shop'], null, ['mitigation_plan' => 'Vehicle repaired and the operational route is available', 'obstacle_resolved' => true]);
        app(\App\Services\Logistics\AssignmentService::class)->respondToOffer($leg->fresh(), $profile, true);
        $transfer = $leg->custodyTransfers()->latest('id')->firstOrFail();
        Storage::disk('local')->put('logistics-proof/release.jpg', 'release');
        Storage::disk('local')->put('logistics-proof/receipt.jpg', 'receipt');
        $custody = app(\App\Services\Logistics\ShipmentCustodyService::class);
        $custody->release($transfer, $holder, 'logistics-proof/release.jpg');
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        $staff->givePermissionTo(Permission::findOrCreate('access-staff-job-orders', 'user'));
        $this->clockInEmployee($staff);
        $this->assertNotEmpty($custody->pendingFor($staff));
        $custody->receive($transfer->fresh(), $staff, 'logistics-proof/receipt.jpg');
        $this->assertSame('shop', $leg->fresh()->custody_state);
        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame(0, $leg->custodyTransfers()->whereIn('status', ['requested', 'released'])->count());
        try {
            $custody->request($leg->fresh(), $dispatcher, 'rider', $profile->id, (string) Str::uuid());
            $this->fail('Uninspected goods were staged for outbound release.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('inspection', $exception->errors());
        }
        $product = \App\Models\Product::create(['shop_owner_id' => $shop->id, 'stock_quantity' => 8, 'name' => 'Retry goods', 'slug' => 'retry-goods', 'price' => 100, 'is_active' => true]);
        $item = \App\Models\OrderItem::create(['order_id' => $leg->shipment->source_id, 'product_id' => $product->id, 'product_name' => $product->name, 'price' => 100, 'quantity' => 1, 'subtotal' => 100]);
        if ($disabled) \App\Models\ShopOwnerModule::where('shop_owner_id', $shop->id)->where('module_key', 'logistics')->update(['enabled' => false]);
        $availableStatus = $profile->availability_status;
        if ($unavailable) $profile->update(['availability_status' => 'inactive']);
        $payload = [
            'incident_id' => $incident->id, 'expected_revision' => $incident->fresh()->revision, 'request_key' => (string) Str::uuid(),
            'return_notes' => 'Actual goods safely received and reviewed for the authorized Rider', 'photo' => \Illuminate\Http\UploadedFile::fake()->image('retry-receipt.jpg'),
            'line_dispositions' => [['order_item_id' => $item->id, 'approved_qty' => 1, 'inspection_disposition' => 'resellable']],
        ];
        $url = "/api/staff/orders/{$item->order_id}/confirm-return-received";
        $this->actingAs($staff, 'user')->post($url, $payload, ['Accept' => 'application/json'])->assertOk();
        if ($disabled || $unavailable) {
            $this->assertSame('shop', $leg->fresh()->custody_state);
            $this->assertSame(0, $leg->custodyTransfers()->where('to_type', 'rider')->count());
            $this->assertTrue(app(\App\Services\Logistics\ParcelIncidentRecoveryService::class)->project($incident->fresh(), $staff)['staff_review_required']);
            \App\Models\ShopOwnerModule::where('shop_owner_id', $shop->id)->where('module_key', 'logistics')->update(['enabled' => true]);
            if ($unavailable) $profile->update(['availability_status' => $availableStatus]);
            $this->post($url, [...$payload, 'request_key' => (string) Str::uuid(), 'expected_revision' => $incident->fresh()->revision], ['Accept' => 'application/json'])->assertOk();
        }
        $outbound = $leg->custodyTransfers()->where('to_type', 'rider')->where('status', 'requested')->first();
        $this->assertNotNull($outbound, 'Staff review must stage the already authorized Rider handoff without another Dispatcher action.');
        $this->assertSame($staff->id, $outbound->requested_by_user_id, 'Retain the real Staff identity; do not impersonate Dispatcher.');
        $this->assertSame(8, $product->fresh()->stock_quantity, 'Retry goods stay allocated and are not restocked.');
        $custody->release($outbound, $staff, 'logistics-proof/receipt.jpg');
        $custody->receive($outbound->fresh(), $holder, 'logistics-proof/release.jpg');
        $this->clockInEmployee($holder);
        $this->assertSame('in_transit', app(ShipmentLegService::class)->markInTransit($leg->fresh(), $profile)->status->value);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $custody->request($leg->fresh(), $staff, 'rider', $profile->id, (string) Str::uuid());
    }

    public function test_operational_closure_keeps_loss_investigation_pending_without_property_payout(): void
    {
        [$shop, $order, $leg, $incident, $dispatcher] = $this->fixture();
        $incident->update(['type' => 'lost']);
        $this->historicalLoss($leg, $incident, 'Search documented');
        $key = (string) Str::uuid();
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/parcel-recovery/{$incident->id}/close", [
            'request_key' => $key, 'expected_revision' => 1, 'notes' => 'Operational loss verified; source investigation remains assigned',
            'outcome' => 'confirmed_loss',
        ])->assertOk()->assertJsonPath('recovery.operational_status', 'resolved')->assertJsonPath('recovery.complete', false);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame(0, \App\Models\OrderRefund::where('order_id', $order->id)->count());
        $this->getJson('/api/logistics/parcel-recovery')->assertOk()->assertJsonPath('recoveries.0.incident_id', $incident->id);
        $this->post("/api/logistics/parcel-recovery/{$incident->id}/investigation", [
            'request_key' => (string) Str::uuid(), 'expected_revision' => 2, 'notes' => 'Recorded search locations and source-owner follow-up',
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('search.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('recovery.operational_status', 'resolved')
            ->assertJsonPath('recovery.can_investigate', true)->assertJsonPath('recovery.complete', false);
        $this->assertSame(0, \App\Models\OrderRefund::where('order_id', $order->id)->count());
        $this->assertSame('Recorded search locations and source-owner follow-up', data_get($incident->fresh()->recovery_context, 'investigation.notes'));
        $this->assertSame('cancelled', $leg->fresh()->status->value);
    }

    public function test_original_parcel_redelivery_uses_new_source_attempt_without_stock_or_fee_effect(): void
    {
        [$shop, $order, $leg, $incident, $dispatcher] = $this->fixture();
        $order->update(['delivery_method' => 'shop_owned', 'status' => 'shipped']);
        $product = \App\Models\Product::create(['shop_owner_id' => $shop->id, 'stock_quantity' => 8, 'name' => 'Original parcel shoe',
            'slug' => 'original-parcel-shoe', 'price' => 100, 'is_active' => true]);
        $item = \App\Models\OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'product_slug' => $product->slug, 'price' => 100, 'quantity' => 1, 'subtotal' => 100]);
        app(DeliveryIncidentService::class)->resolve($incident, $shop, 'return_required', 'Return for inspection', [], null, [], null, ['safe_for_transport' => true]);
        $return = $leg->returnLeg()->firstOrFail();
        $proof = \App\Models\Logistics\HandoffProof::factory()->create(['shipment_leg_id' => $return->id, 'handoff_type' => 'receive', 'review_status' => 'rider_confirmed']);
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        $staff->givePermissionTo(Permission::findOrCreate('access-staff-job-orders', 'user'));
        $this->clockInEmployee($staff);
        $this->actingAs($staff, 'user')->post("/api/logistics/parcel-recovery/{$incident->id}/receipt", [
            'request_key' => (string) Str::uuid(), 'expected_revision' => 1, 'proof_id' => $proof->id,
            'notes' => 'Actual parcel received', 'photo' => \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->postJson("/api/logistics/parcel-recovery/{$incident->id}/inspection", ['request_key' => (string) Str::uuid(),
            'expected_revision' => 2, 'notes' => 'Original goods safe', 'items' => [['reference' => 'order-item:'.$item->id, 'quantity' => 1, 'condition' => 'safe']],
        ])->assertOk();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        \App\Models\Logistics\LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], ['operating_days' => range(1, 7)]);
        $before = $order->fresh()->only(['total_amount', 'shipping_fee', 'payment_status']);
        $data = ['request_key' => (string) Str::uuid(), 'expected_revision' => 3, 'notes' => 'Vehicle repaired; original goods inspected and customer delivery authorized',
            'action' => 'redeliver_original', 'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning', 'rider_profile_id' => $rider->id];
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/parcel-recovery/{$incident->id}/disposition", $data)->assertOk();
        $this->postJson("/api/logistics/parcel-recovery/{$incident->id}/disposition", $data)->assertOk();
        $shipments = Shipment::where('source_type', 'order')->where('source_id', $order->id)->orderBy('source_attempt')->get();
        $this->assertCount(2, $shipments);
        $this->assertSame([1, 2], $shipments->pluck('source_attempt')->all());
        $this->assertSame('cancelled', $leg->fresh()->status->value);
        $this->assertSame('assigned', $shipments->last()->legs()->firstOrFail()->status->value);
        $this->actingAs($staff, 'user')->getJson('/api/logistics/parcel-recovery')->assertOk()
            ->assertJsonPath('recoveries.0.transfers.0.shipment_leg_id', $shipments->last()->legs()->firstOrFail()->id);
        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertSame($before, $order->fresh()->only(['total_amount', 'shipping_fee', 'payment_status']));
        $this->assertSame(0, \App\Models\OrderRefund::where('order_id', $order->id)->count());
        $newLeg = $shipments->last()->legs()->firstOrFail();
        app(\App\Services\Logistics\AssignmentService::class)->respondToOffer($newLeg, $rider, true);
        $recipient = User::findOrFail($rider->linked_id);
        $recipient->givePermissionTo(Permission::findOrCreate('update-logistics-status', 'user'));
        Storage::disk('local')->put('logistics-proof/actual-handoff.jpg', 'actual parcel handoff');
        $transfer = $newLeg->custodyTransfers()->firstOrFail();
        $custody = app(\App\Services\Logistics\ShipmentCustodyService::class);
        $custody->release($transfer, $staff, 'logistics-proof/actual-handoff.jpg');
        $custody->receive($transfer->fresh(), $recipient, 'logistics-proof/actual-handoff.jpg');
        $projection = app(\App\Services\Logistics\ParcelIncidentRecoveryService::class)->project($incident->fresh('leg.shipment'), $staff);
        $this->assertSame('rider', $projection['custody']);
        $this->assertTrue($projection['received']);
        $this->assertFalse($projection['can_inspect']);
        $order->update(['payment_method' => 'paymongo_card', 'payment_status' => 'paid']);
        $recipient->givePermissionTo(Permission::findOrCreate('record-logistics-proof', 'user'));
        $dispatcher->givePermissionTo(Permission::findOrCreate('approve-proof-of-delivery', 'user'));
        $this->clockInEmployee($recipient);
        app(ShipmentLegService::class)->markPickedUp($newLeg->fresh(), $rider);
        app(ShipmentLegService::class)->markInTransit($newLeg->fresh(), $rider);
        $this->actingAs($recipient, 'user')->postJson("/api/logistics/legs/{$newLeg->id}/arrivals", ['arrival_type' => 'dropoff', 'exception_reason' => 'pin_incorrect'])->assertCreated();
        $deliveryProof = $this->post("/api/logistics/legs/{$newLeg->id}/proof", ['handoff_type' => 'delivery', 'proof_type' => 'photo',
            'idempotency_key' => (string) Str::uuid(), 'proof_file' => \Illuminate\Http\UploadedFile::fake()->image('delivered.jpg')], ['Accept' => 'application/json'])->assertCreated()->json('proof.id');
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/proofs/{$deliveryProof}/approve")->assertOk();
        $this->assertSame('delivered', $newLeg->fresh()->status->value);
        $this->assertSame('resolved', $incident->fresh()->status, 'Approved delivery completes the incident without manual Dispatcher closure.');
        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->actingAs($staff, 'user')->postJson("/api/logistics/parcel-recovery/{$incident->id}/inspection", ['request_key' => (string) Str::uuid(),
            'expected_revision' => $incident->fresh()->revision, 'notes' => 'Cannot inspect goods held by Rider',
            'items' => [['reference' => 'order-item:'.$item->id, 'quantity' => 1, 'condition' => 'safe']],
        ])->assertUnprocessable();
    }

    public function test_refund_review_keeps_normal_approval_and_inspection_requirements(): void
    {
        [$shop, $order, $leg, $incident, $dispatcher] = $this->fixture();
        $order->update(['payment_method' => 'paymongo_card', 'payment_status' => 'paid', 'paymongo_payment_id' => 'synthetic-captured-payment']);
        $product = \App\Models\Product::create(['shop_owner_id' => $shop->id, 'stock_quantity' => 8, 'name' => 'Refund parcel shoe',
            'slug' => 'refund-parcel-shoe', 'price' => 100, 'is_active' => true]);
        $item = \App\Models\OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'product_slug' => $product->slug, 'price' => 100, 'quantity' => 1, 'subtotal' => 100]);
        app(DeliveryIncidentService::class)->resolve($incident, $shop, 'return_required', 'Return for refund review', [], null, [], null, ['safe_for_transport' => true]);
        $return = $leg->returnLeg()->firstOrFail();
        $proof = \App\Models\Logistics\HandoffProof::factory()->create(['shipment_leg_id' => $return->id, 'handoff_type' => 'receive', 'review_status' => 'rider_confirmed']);
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        $staff->givePermissionTo(Permission::findOrCreate('access-staff-job-orders', 'user'));
        $this->clockInEmployee($staff);
        $this->actingAs($staff, 'user')->post("/api/logistics/parcel-recovery/{$incident->id}/receipt", ['request_key' => (string) Str::uuid(),
            'expected_revision' => 1, 'proof_id' => $proof->id, 'notes' => 'Received original parcel',
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('receipt.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->postJson("/api/logistics/parcel-recovery/{$incident->id}/inspection", ['request_key' => (string) Str::uuid(),
            'expected_revision' => 2, 'notes' => 'Damage documented', 'items' => [['reference' => 'order-item:'.$item->id, 'quantity' => 1, 'condition' => 'damaged']],
        ])->assertOk();
        $this->postJson("/api/logistics/parcel-recovery/{$incident->id}/disposition", ['request_key' => (string) Str::uuid(),
            'expected_revision' => 3, 'action' => 'refund_review', 'notes' => 'Submit eligibility review through normal refund policy',
        ])->assertOk();
        $refund = \App\Models\OrderRefund::where('order_id', $order->id)->sole();
        $this->assertSame('parcel_incident_recovery', $refund->reason_code);
        $this->assertSame('pending', $refund->finance_status);
        $this->assertSame('pending', $refund->items()->sole()->inventory_action);
        $this->assertFalse(app(\App\Services\OrderRefundService::class)->canExecuteApprovedRefund($refund));
        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_new_incident_after_retry_is_a_distinct_hold_and_needs_new_dispatch_decision(): void
    {
        [$shop, , $leg, $first, $dispatcher] = $this->fixture();
        \App\Models\Logistics\LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], ['operating_days' => range(1, 7)]);
        $profile = $leg->assignments()->latest('id')->firstOrFail()->riderProfile;
        $options = ['request_key' => (string) Str::uuid(), 'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning',
            'rider_profile_id' => $profile->id, 'handoff_route' => 'direct'];
        $service = app(DeliveryIncidentService::class);
        $service->resolve($first, $shop, 'retry', 'First recovery', [], $dispatcher, $options, null, ['mitigation_plan' => 'Vehicle repaired and the operational route is available', 'obstacle_resolved' => true]);
        app(\App\Services\Logistics\AssignmentService::class)->respondToOffer($leg->fresh(), $profile, true);
        app(\App\Services\Logistics\ShipmentLegService::class)->markInTransit($leg->fresh(), $profile);
        $second = $service->report($leg->fresh(), $profile, ['type' => 'vehicle_problem', 'notes' => 'Second actual occurrence', 'photo_paths' => ['incident-evidence/second.jpg']]);
        $this->assertNotSame($first->id, $second->id);
        try {
            app(\App\Services\Logistics\ShipmentLegService::class)->markInTransit($leg->fresh(), $profile);
            $this->fail('A new unresolved occurrence reused the old retry authorization.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('incident', $exception->errors());
        }
        $service->resolve($second, $shop, 'retry', 'Second authorized recovery', [], $dispatcher,
            [...$options, 'request_key' => (string) Str::uuid()], null, ['mitigation_plan' => 'Vehicle repaired and the operational route is available', 'obstacle_resolved' => true]);
        $this->assertSame(3, $leg->assignments()->count());
        $this->assertSame(2, $leg->incidents()->count());
        $this->assertSame('Second actual occurrence', $second->fresh()->notes);
    }

    public function test_expired_unstarted_immediate_recovery_can_be_reauthorized_with_new_offer_acceptance(): void
    {
        [$shop, , $leg, $incident, $dispatcher] = $this->fixture();
        \App\Models\Logistics\LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], ['operating_days' => range(1, 7), 'morning_start' => '08:00', 'morning_end' => '12:00']);
        $profile = $leg->assignments()->latest('id')->firstOrFail()->riderProfile;
        $payload = ['resolution' => 'retry', 'note' => 'Dispatcher authorized bounded immediate recovery', 'reason' => 'Dispatcher authorized bounded immediate recovery',
            'request_key' => (string) Str::uuid(), 'expected_revision' => 0, 'findings' => ['mitigation_plan' => 'Vehicle repaired and the operational route is available', 'obstacle_resolved' => true],
            'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning', 'expected_assignment_id' => $leg->assignments()->latest('id')->value('id'),
            'rider_profile_id' => $profile->id, 'handoff_route' => 'direct', 'immediate_recovery' => true,
            'effective_start' => now()->toISOString(), 'effective_end' => now()->addMinutes(5)->toISOString(), 'immediate_reason' => 'Customer and rider ready now'];
        $this->actingAs($dispatcher, 'user')->postJson("/api/logistics/incidents/{$incident->id}/resolve", $payload)->assertOk();
        app(\App\Services\Logistics\AssignmentService::class)->respondToOffer($leg->fresh(), $profile, true);
        $this->travelTo(now()->addMinutes(5));
        $this->postJson("/api/logistics/incidents/{$incident->id}/resolve", [...$payload,
            'request_key' => (string) Str::uuid(), 'expected_revision' => 1, 'expected_assignment_id' => $leg->assignments()->latest('id')->value('id'),
            'effective_start' => now()->toISOString(), 'effective_end' => now()->addMinutes(10)->toISOString(),
        ])->assertOk();
        $this->assertSame('assigned', $leg->assignments()->latest('id')->value('status'));
        app(\App\Services\Logistics\AssignmentService::class)->respondToOffer($leg->fresh(), $profile, true);
        $this->assertSame('in_transit', app(ShipmentLegService::class)->markInTransit($leg->fresh(), $profile)->status->value);
        $this->assertSame(3, $leg->assignments()->count());
        $this->postJson("/api/logistics/incidents/{$incident->id}/resolve", [...$payload, 'request_key' => (string) Str::uuid(),
            'expected_revision' => 2, 'expected_assignment_id' => $leg->assignments()->latest('id')->value('id')])->assertUnprocessable();
        $this->assertSame(3, $leg->assignments()->count());
        $this->travelTo(now()->addDay());
        $this->clockInEmployee($dispatcher);
        $this->postJson("/api/logistics/incidents/{$incident->id}/resolve", $payload)->assertOk();
        $this->assertSame(3, $leg->assignments()->count());
    }

    public function test_source_authorized_staff_can_view_private_recovery_evidence_but_other_shops_and_owner_cannot(): void
    {
        [$shop, , , $incident] = $this->fixture();
        Storage::disk('local')->put('incident-evidence/test/report.jpg', 'private evidence');
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        $staff->givePermissionTo(Permission::findOrCreate('access-staff-job-orders', 'user'));
        $url = "/api/logistics/parcel-recovery/{$incident->id}/evidence/".hash('sha256', 'incident-evidence/test/report.jpg');
        $response = $this->actingAs($staff, 'user')->get($url)->assertOk();
        $this->assertSame(Storage::disk('local')->path('incident-evidence/test/report.jpg'), $response->baseResponse->getFile()->getPathname());
        $other = User::factory()->create();
        $other->givePermissionTo(Permission::findOrCreate('access-staff-job-orders', 'user'));
        $this->actingAs($other, 'user')->get($url)->assertForbidden();
        auth('user')->logout();
        $this->actingAs($shop, 'shop_owner')->getJson($url)->assertUnauthorized();
    }

    public function test_queue_query_count_does_not_grow_per_incident(): void
    {
        [$shop, , $leg, $incident, $dispatcher] = $this->fixture();
        $incident->forceFill(['resolution' => 'return_required', 'recovery_context' => [], 'decided_at' => now(), 'status' => 'under_review'])->save();
        $service = app(\App\Services\Logistics\ParcelIncidentRecoveryService::class);
        $service->pendingFor($dispatcher);
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $service->pendingFor($dispatcher);
        $one = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();
        foreach (range(1, 5) as $index) {
            $order = Order::factory()->create(['shop_owner_id' => $shop->id]);
            $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order', 'source_id' => $order->id]);
            $extraLeg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);
            $copy = $incident->replicate();
            $copy->shipment_leg_id = $extraLeg->id;
            $copy->save();
        }
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $rows = $service->pendingFor($dispatcher);
        $many = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();
        $this->assertCount(6, $rows);
        $this->assertLessThanOrEqual($one + 1, $many, "Queue queries grew from {$one} to {$many}.");
    }

    public function test_historical_confirmed_loss_uses_original_rider_recovery_without_reopening_sale_or_claim(): void
    {
        [$shop, $order, $leg, $incident, $dispatcher] = $this->fixture();
        $incident->update(['type' => 'lost']);
        $holder = $leg->assignments()->latest('id')->firstOrFail()->riderProfile;
        $this->historicalLoss($leg, $incident, 'Original loss investigation');
        $this->actingAs($dispatcher, 'user')->post("/api/logistics/parcel-recovery/{$incident->id}/investigation", [
            'request_key' => (string) Str::uuid(), 'expected_revision' => 1, 'notes' => 'Found historical parcel; actual Rider report required',
            'recovered_rider_profile_id' => $holder->id, 'photo' => \Illuminate\Http\UploadedFile::fake()->image('found.jpg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('recovery');
        $this->assertFalse($leg->returnLeg()->exists());
        $this->assertSame('lost', $leg->fresh()->custody_state);
        $reporter = User::findOrFail($holder->linked_id);
        $this->clockInEmployee($reporter);
        $service = app(DeliveryIncidentService::class);
        $child = $service->reportRecovery($incident->fresh(), $reporter, ['request_key' => (string) Str::uuid(),
            'expected_revision' => 1, 'notes' => 'Actual reporting Rider recovered historical parcel', 'confirmed' => true,
            'condition' => 'safe', 'photo_paths' => ['incident-evidence/found.jpg']]);
        $service->reviewRecovery($child, $dispatcher, ['request_key' => (string) Str::uuid(), 'expected_revision' => 0,
            'notes' => 'Return the recovered parcel through a real pickup and employee receipt', 'action' => 'return',
            'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning']);
        $this->assertSame('unknown', $leg->returnLeg()->firstOrFail()->custody_state);
        $this->assertSame('assigned', $leg->returnLeg->assignments()->firstOrFail()->status);
        $this->assertSame('lost', $leg->fresh()->custody_state);
        $this->assertSame('loss_confirmed', $incident->fresh()->resolution);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame(0, $order->refunds()->count());
    }

    public function test_pending_cod_remittance_prevents_aggregate_completion_after_operational_closure(): void
    {
        [, $order, $leg, $incident, $dispatcher] = $this->fixture();
        $profile = $leg->assignments()->latest('id')->firstOrFail()->riderProfile;
        $cash = \App\Models\CodCollection::create(['shop_owner_id' => $order->shop_owner_id, 'order_id' => $order->id,
            'shipment_id' => $leg->shipment_id, 'shipment_leg_id' => $leg->id, 'rider_profile_id' => $profile->id, 'rider_user_id' => $profile->linked_id,
            'expected_amount' => 100, 'collected_amount' => 100, 'status' => 'cash_collected', 'collected_at' => now()]);
        $incident->forceFill(['resolution' => 'retry', 'status' => 'resolved', 'recovery_context' => []])->save();
        $service = app(\App\Services\Logistics\ParcelIncidentRecoveryService::class);
        $row = $service->project($incident->fresh('leg.shipment'), $dispatcher);
        $this->assertFalse($row['complete']);
        $this->assertSame('cash_collected', collect($row['obligations'])->firstWhere('type', 'cod_cash')['status']);
        $this->assertSame('cash_collected', $cash->fresh()->status);
        $cash->update(['status' => 'settled', 'settled_at' => now()]);
        $this->assertTrue($service->project($incident->fresh('leg.shipment'), $dispatcher)['complete']);
    }

    public function test_detaching_last_unfinished_stop_completes_original_batch_without_changing_finished_stops(): void
    {
        [$shop, , $leg, $incident, $dispatcher] = $this->fixture();
        $assignment = $leg->assignments()->latest('id')->firstOrFail();
        $batch = \App\Models\Logistics\DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id, 'rider_profile_id' => $assignment->rider_profile_id,
            'status' => 'in_progress', 'started_at' => now()]);
        $leg->update(['delivery_batch_id' => $batch->id, 'stop_sequence' => 2]);
        $completed = ShipmentLeg::factory()->create(['shipment_id' => $leg->shipment_id, 'delivery_batch_id' => $batch->id, 'stop_sequence' => 1, 'status' => 'delivered']);
        app(DeliveryIncidentService::class)->resolve($incident, $shop, 'retry', 'Recover only unfinished stop', [], $dispatcher,
            ['request_key' => (string) Str::uuid(), 'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning', 'rider_profile_id' => $assignment->rider_profile_id],
            null, ['mitigation_plan' => 'Vehicle repaired and the operational route is available', 'obstacle_resolved' => true]);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame('delivered', $completed->fresh()->status->value);
        $this->assertSame($batch->id, $completed->fresh()->delivery_batch_id);
    }

    public function test_legacy_source_is_an_owned_escalation_without_coercing_another_tenants_order_id(): void
    {
        [$shop, , $leg, $incident, $dispatcher] = $this->fixture();
        $foreign = Order::factory()->create(['payment_status' => 'paid', 'payment_method' => 'paymongo_card', 'paymongo_payment_id' => 'synthetic-foreign-capture']);
        $leg->shipment->update(['source_id' => $foreign->id]);
        $incident->update(['type' => 'lost']);
        $this->historicalLoss($leg, $incident, 'Source mismatch requires investigation');
        $this->assertSame(0, $foreign->refunds()->count());
        $this->assertSame('paid', $foreign->fresh()->payment_status);
        $this->actingAs($dispatcher, 'user')->getJson('/api/logistics/parcel-recovery')->assertOk()
            ->assertJsonPath('recoveries.0.obligations.0.type', 'source_review')->assertJsonPath('recoveries.0.complete', false);
        $this->postJson("/api/logistics/parcel-recovery/{$incident->id}/disposition", [
            'request_key' => (string) Str::uuid(), 'expected_revision' => 1, 'notes' => 'Assigned source verification to Dispatcher', 'action' => 'escalate',
        ])->assertOk();
        $this->postJson("/api/logistics/parcel-recovery/{$incident->id}/disposition", [
            'request_key' => (string) Str::uuid(), 'expected_revision' => 2, 'notes' => 'Cannot infer a refund by numeric identity', 'action' => 'refund_review',
        ])->assertUnprocessable();
        $this->assertSame(0, $foreign->refunds()->count());
    }
}
