<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\DeliveryIncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeliveryIncidentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function approveReportedLoss($incident, $rider, $shop, string $note): array
    {
        $service = app(DeliveryIncidentService::class);
        $actor = User::findOrFail($rider->linked_id);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('resolve-logistics-exceptions', 'user'));
        $this->clockInEmployee($actor);
        $this->clockInEmployee($dispatcher);
        $facts = fn ($revision) => ['request_key' => (string) \Illuminate\Support\Str::uuid(), 'expected_revision' => $revision, 'notes' => $note];
        $incident = $service->lossAction($incident, $dispatcher, 'acknowledge', $facts(0));
        $incident = $service->lossAction($incident, $actor, 'confirm', $facts(1));
        $approval = $facts(2);
        return [$service->lossAction($incident, $dispatcher, 'approve', $approval), $dispatcher, $approval];
    }

    public function test_assigned_rider_reports_and_dispatcher_confirms_loss(): void
    {
        $shop = ShopOwner::factory()->create();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id, 'status' => 'picked_up']);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        $service = app(DeliveryIncidentService::class);

        $incident = $service->report($leg, $rider, ['type' => 'lost', 'notes' => 'Missing during route', 'photo_paths' => ['incident-evidence/leg-1/evidence.jpg']]);
        [$resolved] = $this->approveReportedLoss($incident, $rider, $shop, 'Search and investigation completed');

        $this->assertSame('resolved', $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame('loss_confirmed', $leg->fresh()->resolution_type);
        $this->assertSame('cancelled', $leg->fresh()->status->value);
        $this->assertSame('cancelled', $leg->assignments()->firstOrFail()->fresh()->status);
        $this->assertSame('cancelled', $leg->shipment->fresh()->status->value);
        $this->assertDatabaseHas('delivery_events', [
            'shipment_leg_id' => $leg->id,
            'event_type' => 'loss_confirmed',
            'visibility' => 'customer',
        ]);
    }

    public function test_open_incident_is_singleton_per_delivery_and_enters_dispatcher_resolution(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        if ($shop->isCompany()) {
            \App\Models\ShopOwnerModule::updateOrCreate([
                'shop_owner_id' => $shop->id, 'module_key' => 'logistics',
            ], ['enabled' => true]);
        }
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'in_transit',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);
        $service = app(DeliveryIncidentService::class);

        $incident = $service->report($leg, $rider, [
            'type' => 'vehicle_problem',
            'notes' => 'Motorcycle puncture on route.',
            'photo_paths' => ['incident-evidence/leg-incident/report.jpg'],
        ]);

        $this->assertSame('needs_resolution', $leg->fresh()->status->value);
        $this->assertSame($incident->id, $service->report($leg, $rider, [
            'type' => 'vehicle_problem',
            'notes' => 'Retry of the same report.',
            'photo_paths' => ['incident-evidence/leg-incident/retry.jpg'],
        ])->id);

        try {
            $service->report($leg, $rider, [
                'type' => 'damaged',
                'notes' => 'A second open incident.',
                'photo_paths' => ['incident-evidence/leg-incident/second.jpg'],
            ]);
            $this->fail('A second open incident was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('incident', $exception->errors());
        }

        $this->assertSame(1, $leg->incidents()->count());

        $leg->update(['custody_state' => 'rider', 'custody_rider_profile_id' => $rider->id, 'custody_confirmed_at' => now()]);
        app(DeliveryIncidentService::class)->resolve($incident, $shop, 'retry', 'Vehicle issue resolved; retry approved.', [], null, [], null, ['mitigation_plan' => 'Vehicle repaired and the operational obstacle cleared']);

        $this->assertSame('needs_resolution', $leg->fresh()->status->value);
        $this->assertSame('retry', $leg->fresh()->resolution_type);
    }

    public function test_dismissing_an_incident_resumes_the_delivery(): void
    {
        $shop = ShopOwner::factory()->create();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'in_transit',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);

        $incident = app(DeliveryIncidentService::class)->report($leg, $rider, [
            'type' => 'vehicle_problem',
            'notes' => 'Vehicle issue reported.',
            'photo_paths' => ['incident-evidence/leg-incident/resume.jpg'],
        ]);

        $leg->update(['custody_state' => 'rider', 'custody_rider_profile_id' => $rider->id, 'custody_confirmed_at' => now()]);
        app(DeliveryIncidentService::class)->resolve(
            $incident,
            $shop,
            'dismissed',
            'Issue was checked and the rider may continue.',
            [], null, [], null, ['mitigation_plan' => 'Vehicle repaired and the operational obstacle cleared'],
        );

        $this->assertSame('in_transit', $leg->fresh()->status->value);
        $this->assertNull($leg->fresh()->resolution_type);
    }

    public function test_confirmed_loss_creates_an_idempotent_refund_claim_for_a_paid_retail_order(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $customer = User::factory()->create();
        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);
        $finance->assignRole(Role::findOrCreate('Finance', 'user'));
        $foreignFinance = User::factory()->create(['shop_owner_id' => ShopOwner::factory()->create()->id]);
        $foreignFinance->assignRole(Role::findOrCreate('Finance', 'user'));
        $staff = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        $staff->assignRole(Role::findOrCreate('Staff', 'user'));
        $staff->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('access-staff-job-orders', 'user'));
        $product = Product::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Lost parcel shoe',
            'slug' => 'lost-parcel-shoe-'.$customer->id,
            'price' => 500,
            'stock_quantity' => 1,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'size' => '42',
            'color' => 'Black',
            'quantity' => 1,
            'is_active' => true,
        ]);
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'total_amount' => 500,
            'shipping_fee' => 100,
            'payment_method' => 'paymongo_card',
            'payment_status' => 'paid',
            'paymongo_payment_id' => 'loss-payment-'.$customer->id,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => 'Lost parcel shoe',
            'product_slug' => $product->slug,
            'price' => 500,
            'quantity' => 1,
            'subtotal' => 500,
            'size' => '42',
            'color' => 'Black',
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
        ]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'in_transit']);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, [
            'type' => 'lost',
            'notes' => 'Missing during route',
            'photo_paths' => ['incident-evidence/leg-loss/report.png'],
        ]);

        [$final, $dispatcher, $approval] = $this->approveReportedLoss($incident, $rider, $shop, 'Investigation completed');
        $service->lossAction($final, $dispatcher, 'approve', $approval);

        $claim = OrderRefund::query()->where('reason_code', 'delivery_loss_confirmed')->firstOrFail();
        $this->assertSame('not_required', $claim->return_status);
        $this->assertSame('pending', $claim->finance_status);
        $this->assertSame(1, OrderRefund::query()->where('reason_code', 'delivery_loss_confirmed')->count());
        $refunds = app(\App\Services\OrderRefundService::class);
        $this->assertFalse($refunds->canExecuteApprovedRefund($claim->fresh()));
        DB::commit();
        $this->assertDatabaseHas('notifications', ['user_id' => $staff->id, 'type' => 'refund_request', 'title' => 'Refund Eligibility Review Required']);
        $this->assertSame('invalid_state', $refunds->approveRequestedRefund($claim->fresh(), 'finance', $finance->id)['result']);
        $this->assertSame('approved', $refunds->approveRequestedRefund($claim->fresh(), 'staff', $staff->id)['result']);
        $this->assertSame('approved', $refunds->approveRequestedRefund($claim->fresh(), 'finance', $finance->id)['result']);
        if ($claim->fresh()->requires_owner_approval) {
            $this->assertSame('approved', $refunds->approveRequestedRefund($claim->fresh(), 'shop_owner')['result']);
            $this->assertSame('approved', $refunds->approveRequestedRefund($claim->fresh(), 'finance', $finance->id)['result']);
        }
        $this->assertTrue($refunds->canExecuteApprovedRefund($claim->fresh()));
        $this->assertSame('not_required', $claim->fresh()->return_status);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $finance->id,
            'type' => 'refund_request',
            'title' => 'Refund Finance Approval Required',
            'action_url' => '/finance?section=refund-approvals',
            'requires_action' => true,
        ]);
        $notification = Notification::query()
            ->where('user_id', $finance->id)
            ->where('type', 'refund_request')->where('title', 'Refund Finance Approval Required')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($claim->id, (int) data_get($notification->data, 'refund_id'));
        $this->assertSame(1, Notification::query()->where('user_id', $finance->id)->where('title', 'Refund Finance Approval Required')->count());
        $this->assertSame(0, Notification::query()->where('user_id', $foreignFinance->id)
            ->where('type', 'refund_request')->count());
    }

    public function test_service_rejects_client_supplied_paths_outside_the_incident_evidence_prefix(): void
    {
        $shop = ShopOwner::factory()->create();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'picked_up',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);

        $this->expectException(ValidationException::class);

        app(DeliveryIncidentService::class)->report($leg, $rider, [
            'type' => 'lost',
            'notes' => 'Missing during route',
            'photo_paths' => ['../../private/secrets.txt'],
        ]);
    }

    public function test_service_rejects_unknown_incident_resolutions(): void
    {
        $shop = ShopOwner::factory()->create();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'picked_up',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);
        $incident = app(DeliveryIncidentService::class)->report($leg, $rider, [
            'type' => 'damaged',
            'notes' => 'Parcel damaged',
            'photo_paths' => ['incident-evidence/leg-damaged/report.png'],
        ]);

        try {
            app(DeliveryIncidentService::class)->resolve($incident, $shop, 'invented_resolution', 'Not a supported workflow.');
            $this->fail('An unknown incident resolution was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('resolution', $exception->errors());
        }

        $this->assertSame('reported', $incident->fresh()->status);
        $this->assertNull($incident->fresh()->resolution);
    }

    public function test_resolved_incident_cannot_be_overwritten_with_a_different_resolution(): void
    {
        $shop = ShopOwner::factory()->create();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'picked_up',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, [
            'type' => 'damaged',
            'notes' => 'Parcel damaged',
            'photo_paths' => ['incident-evidence/leg-damaged/overwrite.png'],
        ]);
        $leg->update(['custody_state' => 'rider', 'custody_rider_profile_id' => $rider->id, 'custody_confirmed_at' => now()]);
        $incident->forceFill(['recovery_context' => ['inspection' => ['quarantined' => false, 'actor_id' => $shop->id, 'items' => [['reference' => 'parcel', 'quantity' => 1, 'condition' => 'safe']]]]])->save();
        $service->resolve($incident, $shop, 'dismissed', 'Issue reviewed and closed.', [], null, [], null,
            ['condition_verified' => true, 'safe_for_delivery' => true, 'items' => [['reference' => 'parcel', 'quantity' => 1, 'condition' => 'safe']]]);

        try {
            $service->resolve($incident->fresh(), $shop, 'retry', 'Try again instead.');
            $this->fail('A resolved incident was overwritten.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('resolution', $exception->errors());
        }

        $this->assertSame('dismissed', $incident->fresh()->resolution);
        $this->assertSame('resolved', $incident->fresh()->status);
    }
    public function test_investigation_records_explicit_responsibility_without_changing_the_report(): void
    {
        $shop = ShopOwner::factory()->approved()->withLogistics()->create(['registration_type' => 'company']);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id, 'status' => 'assigned']);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, ['type' => 'damaged', 'notes' => 'Synthetic damaged packaging report', 'photo_paths' => ['incident-evidence/test.jpg']]);
        $actor = User::factory()->create(['shop_owner_id' => $shop->id]);
        $leg->update(['custody_state' => 'rider', 'custody_rider_profile_id' => $rider->id, 'custody_confirmed_at' => now()]);
        $incident->forceFill(['recovery_context' => ['inspection' => ['quarantined' => false, 'actor_id' => $actor->id, 'items' => [['reference' => 'parcel', 'quantity' => 1, 'condition' => 'safe']]]]])->save();
        $resolved = $service->resolve($incident, $shop, 'dismissed', 'Packaging inspected, contents intact', [], $actor, [], 'undetermined',
            ['condition_verified' => true, 'safe_for_delivery' => true, 'items' => [['reference' => 'parcel', 'quantity' => 1, 'condition' => 'safe']]]);
        $this->assertSame('undetermined', $resolved->responsible_party);
        $this->assertSame('Synthetic damaged packaging report', $resolved->notes);
        $this->assertDatabaseHas('delivery_events', ['shipment_leg_id' => $leg->id, 'event_type' => 'parcel_recovery_decided', 'created_by_type' => User::class, 'created_by_id' => $actor->id]);
    }
}
