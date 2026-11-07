<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\DeliveryIncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LostParcelLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now(config('app.shop_timezone', 'Asia/Manila'))->setTime(9, 0));
        $shop = ShopOwner::factory()->approved()->withLogistics()->create(['registration_type' => 'company', 'business_type' => 'retail']);
        $order = Order::factory()->create(['shop_owner_id' => $shop->id, 'payment_method' => 'cod', 'payment_status' => 'unpaid']);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $actor = User::findOrFail($rider->linked_id);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo(Permission::findOrCreate('resolve-logistics-exceptions', 'user'));
        $this->clockInEmployee($actor);
        $this->clockInEmployee($dispatcher);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order', 'source_id' => $order->id, 'purpose' => 'retail_delivery']);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'in_transit',
            'custody_state' => 'rider', 'custody_rider_profile_id' => $rider->id, 'custody_confirmed_at' => now()]);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        return [$shop, $order, $rider, $actor, $dispatcher, $leg];
    }

    private function action(int $revision, string $notes = 'Checked the route and parcel history'): array
    {
        return ['request_key' => (string) Str::uuid(), 'expected_revision' => $revision, 'notes' => $notes];
    }

    public function test_recovery_confirmation_is_attached_to_original_incident_and_needs_no_custody_dropdown(): void
    {
        [, , $rider, $actor, $dispatcher, $leg] = $this->fixture();
        $service = app(DeliveryIncidentService::class);
        $parent = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Missing parcel']);
        $data = [...$this->action(0), 'confirmed' => true, 'condition' => 'safe', 'photo_paths' => ['incident-evidence/recovery.jpg']];
        $recovery = $service->reportRecovery($parent, $actor, $data);
        $this->assertSame($rider->id, data_get($recovery->recovery_context, 'recovered_loss.holder_id'));
        $this->assertSame($actor->id, data_get($recovery->recovery_context, 'recovered_loss.reported_by_user_id'));
        $workflow = $parent->fresh()->lossWorkflow($dispatcher);
        $this->assertSame(['return', 'continue'], $workflow['actions']);
        $this->assertSame($recovery->id, $workflow['action_incident_id']);
        $this->assertSame($parent->id, $workflow['recovery']['parent_id']);
        $this->assertSame($recovery->id, $service->reportRecovery($parent, $actor, $data)->id);
        $this->assertSame('needs_resolution', $leg->fresh()->status->value);
        $this->assertSame('rider', $leg->fresh()->custody_state, 'Recovery evidence must not change physical custody');
    }

    public function test_legacy_unresolved_loss_has_an_accessible_acknowledgement_without_invented_confirmation(): void
    {
        [, , $rider, , $dispatcher, $leg] = $this->fixture();
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Historical report']);
        $incident->forceFill(['recovery_context' => null])->save();
        $this->assertContains('acknowledge', $incident->lossWorkflow($dispatcher)['actions']);
        try {
            $service->resolve($incident, $leg->shipment->shopOwner, 'loss_confirmed', 'Historical loss review',
                ['incident-evidence/history.jpg'], $dispatcher);
            $this->fail('Historical loss skipped actual acknowledgement and Rider confirmation');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('Acknowledge', $error->errors()['resolution'][0]);
        }

        $ack = $service->lossAction($incident, $dispatcher, 'acknowledge', $this->action(0));
        $this->assertSame('acknowledged', data_get($ack->recovery_context, 'loss.stage'));
        $this->assertNull(data_get($ack->recovery_context, 'loss.confirm'));
    }

    public function test_historical_recovery_has_a_real_reporting_rider_confirmation_action(): void
    {
        [, , $rider, $actor, $dispatcher, $leg] = $this->fixture();
        $service = app(DeliveryIncidentService::class);
        $parent = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Missing parcel']);
        $child = $service->reportRecovery($parent, $actor, [...$this->action(0), 'confirmed' => true,
            'condition' => 'safe', 'photo_paths' => ['incident-evidence/recovered.jpg']]);
        $context = $child->recovery_context;
        unset($context['recovered_loss']['confirmed']);
        $child->forceFill(['recovery_context' => $context])->save();
        $this->assertContains('confirm_recovery', $parent->fresh()->lossWorkflow($actor)['actions']);
        $this->assertSame([], $parent->fresh()->lossWorkflow($dispatcher)['actions']);
        $this->actingAs($actor, 'user')->postJson("/api/logistics/incidents/{$child->id}/recovery-review", [
            ...$this->action(0), 'action' => 'confirm_report', 'confirmed' => true,
        ])->assertOk();
        $this->assertSame(['return', 'continue'], $parent->fresh()->lossWorkflow($dispatcher)['actions']);
        $this->assertSame('rider', $leg->fresh()->custody_state);
    }

    public function test_dispatcher_page_contains_one_original_incident_with_linked_recovery_controls(): void
    {
        [, , $rider, $actor, $dispatcher, $leg] = $this->fixture();
        $dispatcher->givePermissionTo(Permission::findOrCreate('view-logistics-shipments', 'user'));
        $dispatcher->givePermissionTo(Permission::findOrCreate('assign-logistics-deliveries', 'user'));
        $leg->shipment->source_id && \App\Models\Order::findOrFail($leg->shipment->source_id)->update(['delivery_method' => 'shop_owned']);
        $service = app(DeliveryIncidentService::class);
        $parent = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Missing parcel']);
        $child = $service->reportRecovery($parent, $actor, [...$this->action(0), 'confirmed' => true,
            'condition' => 'safe', 'photo_paths' => ['incident-evidence/recovery.jpg']]);
        $this->actingAs($dispatcher, 'user')->get('/erp/logistics/shipments')->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->has('shipments.data.0.legs.0.incidents', 1)
                ->where('shipments.data.0.legs.0.incidents.0.id', $parent->id)
                ->where('shipments.data.0.legs.0.incidents.0.loss.actions', ['return', 'continue'])
                ->where('shipments.data.0.legs.0.incidents.0.loss.action_incident_id', $child->id));
    }

    public function test_photo_free_loss_has_three_separate_stages_and_final_approval_needs_no_extra_closure(): void
    {
        [, $order, $rider, $actor, $dispatcher, $leg] = $this->fixture();
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Parcel missing after route search']);
        $this->assertSame('reported', data_get($incident->recovery_context, 'loss.stage'));
        $this->assertSame('loss_reported', app(\App\Services\Logistics\CustomerTrackingService::class)->payload($leg->shipment->fresh())['recovery']['state']);
        $ack = $service->lossAction($incident, $dispatcher, 'acknowledge', $this->action(0));
        $this->assertSame('acknowledged', data_get($ack->recovery_context, 'loss.stage'));
        $this->assertNull($ack->resolved_at);
        $this->assertSame('needs_resolution', $leg->fresh()->status->value);
        $this->assertSame(0, $order->refunds()->count());
        $request = $service->lossAction($ack, $actor, 'confirm', $this->action(1));
        $this->assertSame('confirmation_requested', data_get($request->recovery_context, 'loss.stage'));
        $this->assertNull($request->resolved_at);
        $payload = $this->action(2);
        $final = $service->lossAction($request, $dispatcher, 'approve', $payload);
        $this->assertSame('resolved', $final->status);
        $this->assertNotNull($final->resolved_at);
        $this->assertSame('confirmed', data_get($final->recovery_context, 'loss.stage'));
        $this->assertSame('cancelled', $leg->fresh()->status->value);
        $this->assertSame('lost', $leg->fresh()->custody_state);
        $this->assertSame('loss_confirmed', app(\App\Services\Logistics\CustomerTrackingService::class)->payload($leg->shipment->fresh())['recovery']['state']);
        $this->assertSame(0, $order->refunds()->count(), 'Uncollected COD is not refundable');
        $count = $leg->events()->count();
        $service->lossAction($final, $dispatcher, 'approve', $payload);
        $this->assertSame($count, $leg->events()->count());
        $this->assertSame('Parcel missing after route search', $final->notes);
        $this->assertSame([], $final->photo_paths);
    }

    public function test_final_approval_cannot_skip_reporting_rider_confirmation(): void
    {
        [, , $rider, , $dispatcher, $leg] = $this->fixture();
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Parcel is missing', 'photo_paths' => ['incident-evidence/test.jpg']]);
        $this->expectException(ValidationException::class);
        $service->lossAction($incident, $dispatcher, 'approve', $this->action(0));
    }

    public function test_refund_initiation_failure_rolls_back_final_loss_and_retry_reserves_only_once(): void
    {
        [, $order, $rider, $actor, $dispatcher, $leg] = $this->fixture();
        $order->update(['payment_method' => 'paymongo_card', 'payment_status' => 'paid', 'paymongo_payment_id' => 'pay_synthetic_loss']);
        $this->mock(\App\Services\OrderRefundService::class, fn ($mock) => $mock->shouldReceive('reserveConfirmedLossRefund')
            ->once()->andThrow(new \RuntimeException('Synthetic reservation failure')));
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Missing parcel']);
        $incident = $service->lossAction($incident, $dispatcher, 'acknowledge', $this->action(0));
        $incident = $service->lossAction($incident, $actor, 'confirm', $this->action(1));
        $payload = $this->action(2);
        try {
            $service->lossAction($incident, $dispatcher, 'approve', $payload);
            $this->fail('A failed reservation was reported as final success');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic reservation failure', $error->getMessage());
        }
        $this->assertSame('confirmation_requested', data_get($incident->fresh()->recovery_context, 'loss.stage'));
        $this->assertSame('needs_resolution', $leg->fresh()->status->value);
        $this->assertSame(0, $order->refunds()->count());
        $this->app->forgetInstance(\App\Services\OrderRefundService::class);
        $service = app(DeliveryIncidentService::class);
        $final = $service->lossAction($incident->fresh(), $dispatcher, 'approve', $payload);
        $service->lossAction($final, $dispatcher, 'approve', $payload);
        $this->assertSame('resolved', $final->status);
        $this->assertSame(1, $order->refunds()->count());
        $this->assertSame('requested', $order->refunds()->first()->status);
        $this->assertSame('pending', $order->refunds()->first()->finance_status);
    }

    public function test_non_reporting_rider_cannot_request_final_loss(): void
    {
        [$shop, , $rider, , $dispatcher, $leg] = $this->fixture();
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Parcel is missing', 'photo_paths' => ['incident-evidence/test.jpg']]);
        $ack = $service->lossAction($incident, $dispatcher, 'acknowledge', $this->action(0));
        $other = User::factory()->create(['shop_owner_id' => $shop->id]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->lossAction($ack, $other, 'confirm', $this->action(1));
    }

    public function test_damage_still_requires_photographic_evidence(): void
    {
        [, , $rider, , , $leg] = $this->fixture();
        $this->expectException(ValidationException::class);
        app(DeliveryIncidentService::class)->report($leg, $rider, ['type' => 'damaged', 'notes' => 'Shoe box torn']);
    }

    public function test_recovery_notice_requires_dispatcher_review_only_until_actual_authorization(): void
    {
        [, , $rider, $actor, $dispatcher, $leg] = $this->fixture();
        $dispatcher->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Logistics Dispatcher', 'user'));
        $service = app(DeliveryIncidentService::class);
        $parent = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Missing parcel']);
        $child = $service->reportRecovery($parent, $actor, [...$this->action(0), 'confirmed' => true,
            'condition' => 'safe', 'photo_paths' => ['incident-evidence/found.jpg']]);
        $reported = $leg->events()->where('event_type', 'parcel_recovery_reported')->firstOrFail();
        $notifier = app(\App\Services\Logistics\LogisticsNotificationService::class);
        $notifier->notifyForEvent($reported);
        $notice = \App\Models\Notification::where('user_id', $dispatcher->id)->where('data->event_type', 'parcel_recovery_reported')->firstOrFail();
        $this->assertTrue((bool) $notice->requires_action);
        $service->reviewRecovery($child, $dispatcher, [...$this->action(0), 'action' => 'return',
            'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning']);
        $notifier->notifyForEvent($leg->events()->where('event_type', 'parcel_recovery_authorized')->firstOrFail());
        $notifier->notifyForEvent($reported);
        $this->assertFalse((bool) $notice->fresh()->requires_action);
    }

    public function test_stage_notifications_are_deduplicated_and_final_loss_requires_no_rider_action(): void
    {
        [, , $rider, $actor, $dispatcher, $leg] = $this->fixture();
        $dispatcher->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Logistics Dispatcher', 'user'));
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Missing parcel']);
        $incident = $service->lossAction($incident, $dispatcher, 'acknowledge', $this->action(0));
        $acknowledgment = $leg->events()->where('event_type', 'parcel_loss_stage')->firstOrFail();
        app(\App\Services\Logistics\LogisticsNotificationService::class)->notifyForEvent($acknowledgment);
        app(\App\Services\Logistics\LogisticsNotificationService::class)->notifyForEvent($acknowledgment);
        $this->assertTrue((bool) \App\Models\Notification::where('user_id', $actor->id)->firstOrFail()->requires_action);
        $incident = $service->lossAction($incident, $actor, 'confirm', $this->action(1));
        $service->lossAction($incident, $dispatcher, 'approve', $this->action(2));
        foreach ($leg->events()->where('event_type', 'parcel_loss_stage')->get() as $event) {
            app(\App\Services\Logistics\DeliveryEventService::class)->deliverNotifications($event->id);
            app(\App\Services\Logistics\DeliveryEventService::class)->deliverNotifications($event->id);
        }
        $notices = \App\Models\Notification::where('user_id', $actor->id)->get();
        $this->assertCount(3, $notices);
        $final = $notices->first(fn ($notice) => str_contains($notice->message, 'confirmed - final'));
        $this->assertNotNull($final);
        $this->assertFalse((bool) $final->requires_action);
        $this->assertFalse($notices->contains(fn ($notice) => (bool) $notice->requires_action), 'Delayed notices must not reopen a completed loss stage');
    }

    public function test_loss_stage_api_requires_current_revision_and_employee_actor(): void
    {
        [$shop, , $rider, $actor, $dispatcher, $leg] = $this->fixture();
        $incident = app(DeliveryIncidentService::class)->report($leg, $rider, ['type' => 'lost', 'notes' => 'Missing parcel']);
        $url = "/api/logistics/incidents/{$incident->id}/loss";
        $this->actingAs($dispatcher, 'user')->postJson($url, ['action' => 'acknowledge', ...$this->action(0)])
            ->assertOk()->assertJsonPath('incident.loss.stage', 'acknowledged');
        $this->actingAs($actor, 'user')->postJson($url, ['action' => 'confirm', ...$this->action(0)])->assertUnprocessable();
        $this->postJson($url, ['action' => 'confirm', ...$this->action(1)])->assertOk();
        $this->postJson($url, ['action' => 'approve', ...$this->action(2)])->assertForbidden();
        $this->actingAs($shop, 'shop_owner')->postJson($url, ['action' => 'approve', ...$this->action(2)])->assertForbidden();
    }

    public function test_recovery_is_separate_and_blocks_stale_final_approval(): void
    {
        [, , $rider, $actor, $dispatcher, $leg] = $this->fixture();
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Missing parcel']);
        $incident = $service->lossAction($incident, $dispatcher, 'acknowledge', $this->action(0));
        $incident = $service->lossAction($incident, $actor, 'confirm', $this->action(1));
        $data = [...$this->action(2), 'confirmed' => true, 'condition' => 'safe',
            'photo_paths' => ['incident-evidence/recovered.jpg']];
        $recovery = $service->reportRecovery($incident, $actor, $data);
        $this->assertNotSame($incident->id, $recovery->id);
        $this->assertSame($incident->id, data_get($recovery->recovery_context, 'recovered_loss.parent_id'));
        $this->assertSame($recovery->id, $service->reportRecovery($incident, $actor, $data)->id);
        $this->assertSame('confirmation_requested', data_get($incident->fresh()->recovery_context, 'loss.stage'));
        $this->expectException(ValidationException::class);
        $service->lossAction($incident, $dispatcher, 'approve', $this->action(2));
    }

    public function test_final_loss_recovery_preserves_original_custody_and_requires_offer_acceptance(): void
    {
        [, , $rider, $actor, $dispatcher, $leg] = $this->fixture();
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Missing parcel']);
        $incident = $service->lossAction($incident, $dispatcher, 'acknowledge', $this->action(0));
        $incident = $service->lossAction($incident, $actor, 'confirm', $this->action(1));
        $incident = $service->lossAction($incident, $dispatcher, 'approve', $this->action(2));
        $snapshot = $incident->getRawOriginal();
        $recovery = $service->reportRecovery($incident, $actor, [...$this->action(3), 'confirmed' => true,
            'condition' => 'safe', 'photo_paths' => ['incident-evidence/recovered.jpg']]);
        try {
            $service->reviewRecovery($recovery, $dispatcher, [...$this->action(0), 'action' => 'continue',
                'safe_for_transport' => true, 'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning',
                'customer_agreement' => 'Customer agreement does not reopen a final loss']);
            $this->fail('Final loss allowed direct customer delivery');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('action', $error->errors());
        }
        $service->reviewRecovery($recovery, $dispatcher, [...$this->action(0), 'action' => 'return',
            'safe_for_transport' => true, 'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning']);
        $this->assertSame($snapshot, $incident->fresh()->getRawOriginal());
        $this->assertSame('lost', $leg->fresh()->custody_state);
        $return = $leg->returnLeg()->firstOrFail();
        $this->assertSame('shop', $return->destination_snapshot['type']);
        $this->assertSame('assigned', $return->assignments()->firstOrFail()->status);
        $this->assertNull($return->assignments()->firstOrFail()->accepted_at);
        $this->assertSame('unknown', $return->custody_state);
        $this->assertNull($return->custody_rider_profile_id);
        $this->assertNull($return->custody_confirmed_at);
        $this->assertTrue((bool) $return->requires_pickup_proof);
        app(\App\Services\Logistics\AssignmentService::class)->respondToOffer($return, $rider, true);
        $pickup = app(\App\Services\Logistics\ProofService::class)->recordProof($return->fresh(), [
            'handoff_type' => 'pickup', 'proof_type' => 'photo', 'file_path' => 'logistics-proof/actual-pickup.jpg',
            'idempotency_key' => (string) Str::uuid(),
        ], $rider);
        $this->assertSame('unknown', $return->fresh()->custody_state, 'Upload alone cannot confirm pickup');
        app(\App\Services\Logistics\ShipmentLegService::class)->confirmPickup($return->fresh(), $pickup, $rider);
        $this->assertSame('rider', $return->fresh()->custody_state);
        $this->assertSame($rider->id, $return->fresh()->custody_rider_profile_id);

    }

    public function test_pre_final_recovery_continues_through_fresh_acceptance_arrival_proof_and_automatic_completion(): void
    {
        [, $order, $rider, $actor, $dispatcher, $leg] = $this->fixture();
        $order->update(['payment_method' => 'paymongo_card', 'payment_status' => 'paid']);
        $leg->update(['destination_snapshot' => ['latitude' => 14.5, 'longitude' => 120.9]]);
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Missing parcel']);
        $recovery = $service->reportRecovery($incident, $actor, [...$this->action(0), 'confirmed' => true,
            'condition' => 'safe', 'photo_paths' => ['incident-evidence/recovered.jpg']]);
        $recovery = $service->reviewRecovery($recovery, $dispatcher, [...$this->action(0), 'action' => 'continue',
            'safe_for_transport' => true, 'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning',
            'customer_agreement' => 'Customer confirmed original delivery arrangements']);
        $this->assertSame('dismissed', $incident->fresh()->resolution);
        $this->assertSame(2, $leg->assignments()->count());
        app(\App\Services\Logistics\AssignmentService::class)->respondToOffer($leg->fresh(), $rider, true);
        app(\App\Services\Logistics\ShipmentLegService::class)->markInTransit($leg->fresh(), $rider);
        app(\App\Services\Logistics\ArrivalService::class)->record($leg->fresh(), $actor, ['arrival_type' => 'dropoff',
            'latitude' => 14.5, 'longitude' => 120.9, 'accuracy_m' => 5, 'captured_at' => now()->toISOString(), 'idempotency_key' => (string) Str::uuid()]);
        \Illuminate\Support\Facades\Storage::fake('local');
        $path = \Illuminate\Http\UploadedFile::fake()->image('recovered.jpg')->store('handoff-proofs', 'local');
        $proof = app(\App\Services\Logistics\ProofService::class)->recordProof($leg->fresh(), ['handoff_type' => 'delivery',
            'proof_type' => 'photo', 'file_path' => $path, 'idempotency_key' => (string) Str::uuid()], $rider);
        app(\App\Services\Logistics\ProofReviewService::class)->approve($proof, $dispatcher);
        $this->assertSame('delivered', $leg->fresh()->status->value);
        $this->assertSame('resolved', $recovery->fresh()->status);
        $this->assertSame(0, $order->refunds()->count());
    }

    public static function inventoryCases(): array
    {
        return ['eligible' => ['safe', null, false], 'damaged' => ['damaged', null, false], 'unsafe' => ['unsafe', null, false],
            'previous write-off' => ['safe', 'write_off', false], 'already restocked' => ['safe', 'restock', false],
            'active allocation' => ['safe', null, true], 'customer property' => ['safe', null, false, true],
            'partial recovery' => ['safe', null, false, false, true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inventoryCases')]
    public function test_received_recovered_retail_goods_restock_once_without_another_refund_or_manual_close(string $condition, ?string $priorAction, bool $active, bool $customerProperty = false, bool $partial = false): void
    {
        [$shop, $order, $rider, $actor, $dispatcher, $leg] = $this->fixture();
        \App\Models\ShopOwnerModule::factory()->create(['shop_owner_id' => $shop->id, 'module_key' => 'retail_operations', 'enabled' => true]);
        $product = \App\Models\Product::create(['shop_owner_id' => $shop->id, 'name' => 'Recovered shoes',
            'slug' => 'recovered-shoes', 'price' => 500, 'stock_quantity' => 0, 'is_active' => true]);
        $item = \App\Models\OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'product_slug' => $product->slug, 'price' => 500, 'quantity' => $partial ? 2 : 1, 'subtotal' => $partial ? 1000 : 500]);
        if ($customerProperty) {
            $shop->update(['business_type' => 'both']);
            \App\Models\ShopOwnerModule::factory()->create(['shop_owner_id' => $shop->id, 'module_key' => 'repair_operations', 'enabled' => true]);
            $repair = \App\Models\RepairRequest::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'shipped']);
            $leg->shipment->update(['source_type' => 'repair_request', 'source_id' => $repair->id, 'purpose' => 'repair_return']);
        }
        $initialStock = $priorAction === 'restock' ? 1 : 0;
        $product->update(['stock_quantity' => $initialStock]);
        if ($priorAction) {
            $order->update(['payment_method' => 'paymongo_card', 'payment_status' => 'refunded', 'total_amount' => 500, 'shipping_fee' => 0]);
            $refund = \App\Models\OrderRefund::factory()->create(['order_id' => $order->id, 'shop_owner_id' => $shop->id,
                'customer_id' => $order->customer_id, 'status' => 'succeeded', 'amount' => 500,
                'reason_code' => 'delivery_loss_confirmed', 'return_status' => 'not_required']);
            $oldLine = \App\Models\OrderRefundItem::create(['order_refund_id' => $refund->id, 'order_item_id' => $item->id,
                'product_id' => $product->id, 'requested_qty' => 1, 'approved_qty' => 1, 'unit_price_snapshot' => 500,
                'line_amount' => 500, 'inspection_disposition' => $priorAction === 'restock' ? 'resellable' : 'damaged',
                'inventory_action' => $priorAction, 'inventory_applied_at' => now()]);
        }
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Missing parcel']);
        $incident = $service->lossAction($incident, $dispatcher, 'acknowledge', $this->action(0));
        $incident = $service->lossAction($incident, $actor, 'confirm', $this->action(1));
        $incident = $service->lossAction($incident, $dispatcher, 'approve', $this->action(2));
        $recovery = $service->reportRecovery($incident, $actor, [...$this->action(3), 'confirmed' => true,
            'condition' => 'safe', 'photo_paths' => ['incident-evidence/recovered.jpg']]);
        $recovery = $service->reviewRecovery($recovery, $dispatcher, [...$this->action(0), 'action' => 'return',
            'safe_for_transport' => true, 'delivery_date' => now()->toDateString(), 'delivery_window' => 'morning']);
        $return = $leg->returnLeg()->firstOrFail();
        app(\App\Services\Logistics\AssignmentService::class)->respondToOffer($return, $rider, true);
        $proof = \App\Models\Logistics\HandoffProof::factory()->create(['shipment_leg_id' => $return->id,
            'shop_owner_id' => $shop->id, 'handoff_type' => 'receive', 'review_status' => 'rider_confirmed',
            'confirmed_by_type' => User::class, 'confirmed_by_id' => $actor->id]);
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        $staff->givePermissionTo(Permission::findOrCreate($customerProperty ? 'access-repair-job-orders' : 'access-staff-job-orders', 'user'));
        if ($customerProperty) $repair->update(['assigned_repairer_id' => $staff->id]);
        $this->clockInEmployee($staff);
        $coordinator = app(\App\Services\Logistics\ParcelIncidentRecoveryService::class);
        if ($priorAction === 'write_off') {
            app(\App\Services\Logistics\ShipmentLegService::class)->confirmReturnReceipt($return, $proof, $shop, $staff);
        }
        $coordinator->receive($recovery, $staff, [...$this->action(1), 'proof_id' => $proof->id, 'evidence_path' => 'incident-evidence/receipt.jpg']);
        $this->assertSame($initialStock, $product->fresh()->stock_quantity);
        $coordinator->inspect($recovery->fresh(), $staff, [...$this->action(2), 'items' => [
            ['reference' => $customerProperty ? 'repair_request:'.$repair->id : 'order-item:'.$item->id, 'quantity' => 1, 'condition' => $condition]]]);
        $this->assertSame($initialStock, $product->fresh()->stock_quantity, 'Inspection alone is not stock authorization');
        $payload = [...$this->action(3), 'action' => $customerProperty ? 'repair_recovery' : 'inventory_disposition'];
        if ($partial) {
            try {
                $coordinator->disposition($recovery->fresh(), $staff, $payload);
                $this->fail('Unsupported partial quantity was treated as complete');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('inventory', $error->errors());
            }
            $this->assertSame(1, data_get($recovery->fresh()->recovery_context, 'inspection.items.0.quantity'));
            $escalated = $coordinator->disposition($recovery->fresh(), $staff, [...$this->action(3), 'action' => 'escalate']);
            $this->assertSame('under_review', $escalated['operational_status']);
            $this->assertFalse($escalated['can_close']);
            $this->assertSame(0, $product->fresh()->stock_quantity);
            return;
        }
        if ($active) {
            $allocation = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order',
                'source_id' => $order->id, 'purpose' => 'retail_delivery', 'source_attempt' => 2]);
            ShipmentLeg::factory()->create(['shipment_id' => $allocation->id, 'status' => 'assigned']);
            try {
                $coordinator->disposition($recovery->fresh(), $staff, $payload);
                $this->fail('Active allocation was restocked');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('inventory', $exception->errors());
            }
            $this->assertSame($initialStock, $product->fresh()->stock_quantity);
            return;
        }
        $result = $coordinator->disposition($recovery->fresh(), $staff, $payload);
        $this->assertSame('resolved', $result['operational_status']);
        $this->assertFalse($result['can_inspect']);
        $this->assertSame($condition === 'safe' && ! $customerProperty ? 1 : 0, $product->fresh()->stock_quantity);
        $coordinator->disposition($recovery->fresh(), $staff, $payload);
        $this->assertSame($condition === 'safe' && ! $customerProperty ? 1 : 0, $product->fresh()->stock_quantity);
        $this->assertSame($priorAction ? 1 : 0, $order->refunds()->count());
        if ($condition === 'safe' && ! $customerProperty && ! $priorAction) {
            $laterRefund = \App\Models\OrderRefund::factory()->create(['order_id' => $order->id, 'shop_owner_id' => $shop->id]);
            $laterLine = \App\Models\OrderRefundItem::create(['order_refund_id' => $laterRefund->id, 'order_item_id' => $item->id,
                'product_id' => $product->id, 'requested_qty' => 1, 'approved_qty' => 1, 'unit_price_snapshot' => 500,
                'line_amount' => 500, 'inspection_disposition' => 'resellable', 'inventory_action' => 'pending']);
            app(\App\Services\RefundInventoryDispositionService::class)->applyOrderLine($laterLine);
            $this->assertSame(1, $product->fresh()->stock_quantity, 'Later refund execution must consume the prior recovery restock');
            $this->assertNotNull($laterLine->fresh()->inventory_applied_at);
        }
        if ($priorAction) $this->assertSame($priorAction, $oldLine->fresh()->inventory_action);
        $this->assertSame('confirmed', data_get($incident->fresh()->recovery_context, 'loss.stage'));
        $this->assertSame('lost', $leg->fresh()->custody_state);
        try {
            $service->reportRecovery($incident->fresh(), $actor, [...$this->action(3), 'confirmed' => true,
                'condition' => 'safe', 'photo_paths' => ['incident-evidence/repeated.jpg']]);
            $this->fail('A completed physical recovery was recreated');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('incident', $error->errors());
        }
    }
}
