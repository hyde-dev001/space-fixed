<?php

namespace Tests\Feature\Logistics;

use App\Enums\Logistics\LogisticsAction;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\RepairRequest;
use App\Models\RepairPaymentSession;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Logistics\LogisticsActorPolicy;
use App\Services\Logistics\LogisticsMovementEligibility;
use App\Services\Logistics\AssignmentService;
use App\Services\Logistics\BatchDispatchService;
use App\Services\Logistics\ShipmentLegService;
use App\Services\Logistics\ShipmentRequestService;
use App\Services\Logistics\SourceShipmentService;
use App\Services\RepairDeliveryService;
use App\Services\RepairWarrantyService;
use App\Services\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LogisticsModuleMovementBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_logistics_does_not_offer_a_new_repair_quote(): void
    {
        [$shop, $customer, $address] = $this->fixture();
        $quote = app(RepairDeliveryService::class)->quote($shop, $address);
        $this->assertFalse($quote['available']);
        $this->assertSame('logistics_disabled', $quote['reason']);
        $this->assertNull($quote['fee']);
    }

    #[DataProvider('newMovements')]
    public function test_disabled_logistics_rejects_a_new_shipment_even_with_forged_continuation_metadata(string $source, string $purpose, string $legType): void
    {
        [$shop] = $this->fixture();
        $this->assertBlocked(fn () => app(ShipmentRequestService::class)->requestShipment([
            'shop_owner_id' => $shop->id,
            'source_type' => $source, 'source_id' => 999, 'purpose' => $purpose,
            'continuation' => true, 'provider_status' => 'third_party',
            'legs' => [['leg_type' => $legType]],
        ]));
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('shipment_legs', 0);
    }

    public static function newMovements(): array
    {
        return [
            ['order', 'retail_delivery', 'outbound'],
            ['repair_request', 'repair_pickup', 'inbound'],
            ['repair_request', 'repair_return', 'outbound'],
            ['repair_request', 'warranty_pickup', 'inbound'],
            ['repair_request', 'warranty_return', 'outbound'],
            ['order_refund', 'refund_return', 'return_to_shop'],
            ['order', 'replacement', 'outbound'],
        ];
    }

    public function test_same_persisted_started_leg_can_advance_through_the_authenticated_api(): void
    {
        config()->set('shop_modules.enforcement_enabled', true);
        [$shop] = $this->fixture();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'shop_owner_id' => $shop->id,
            'status' => 'picked_up', 'picked_up_at' => now()->subHour(),
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted',
        ]);
        $this->clockInEmployee($rider->linked);
        $rider->linked->givePermissionTo(Permission::findOrCreate('update-logistics-status', 'user'));
        $this->actingAs($rider->linked, 'user')->postJson("/api/logistics/legs/{$leg->id}/in-transit")->assertOk();
        $this->assertSame('in_transit', $leg->fresh()->status->value);
        $this->assertDatabaseCount('shipment_legs', 1);
    }

    public function test_loaded_module_state_cannot_authorize_a_new_quote_after_logistics_is_disabled(): void
    {
        [$shop, , $address] = $this->fixture(enabled: true);
        $shop->load('modules');
        $shop->modules()->where('module_key', 'logistics')->update(['enabled' => false]);
        $this->assertFalse(app(RepairDeliveryService::class)->quote($shop, $address)['available']);
    }

    public function test_repair_booking_rechecks_logistics_after_a_valid_quote_before_persisting(): void
    {
        Storage::fake('public');
        [$shop, $customer, $address] = $this->fixture(enabled: true);
        $customer->update(['identity_verification_status' => User::IDENTITY_APPROVED]);
        $service = RepairService::create([
            'shop_owner_id' => $shop->id, 'name' => 'Deep clean', 'category' => 'Cleaning',
            'price' => 500, 'duration' => '2 days', 'description' => 'Test service', 'status' => 'active',
        ]);
        $toggled = false;
        RepairService::retrieved(function () use ($shop, &$toggled): void {
            $shop->modules()->where('module_key', 'logistics')->update(['enabled' => false]);
            $toggled = true;
        });
        $response = $this->actingAs($customer, 'user')->post('/api/repair-requests', [
            'customer_name' => $customer->name, 'email' => $customer->email, 'phone' => '09171234567',
            'shoe_type' => 'Sneakers', 'shop_owner_id' => $shop->id, 'services' => [$service->id],
            'images' => [UploadedFile::fake()->create('shoe.jpg', 64, 'image/jpeg')], 'total' => 500,
            'intake_delivery_method' => 'shop_pickup', 'intake_address_id' => $address->id,
            'return_delivery_method' => 'walk_in',
        ], ['Accept' => 'application/json']);
        $this->assertTrue($toggled);
        $response->assertUnprocessable()->assertJsonValidationErrors('logistics');
        $this->assertDatabaseCount('repair_requests', 0);
    }

    public function test_started_parent_does_not_authorize_a_later_unstarted_repair_leg(): void
    {
        [$shop, $customer, $address] = $this->fixture();
        $repair = $this->repair($shop, $customer, $address);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id, 'source_type' => 'repair_request',
            'source_id' => $repair->id, 'purpose' => 'repair_pickup', 'status' => 'active',
        ]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'shop_owner_id' => $shop->id,
            'status' => 'delivered', 'sequence' => 1, 'picked_up_at' => now()->subDay(),
        ]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'shop_owner_id' => $shop->id, 'status' => 'pending', 'sequence' => 2,
        ]);
        $this->assertBlocked(fn () => app(SourceShipmentService::class)->ensureRepairInboundShipment($repair));
    }

    public function test_ineligible_shop_cannot_use_a_started_leg_as_module_continuation(): void
    {
        [$shop] = $this->fixture();
        $shop->update(['registration_type' => 'individual']);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'shop_owner_id' => $shop->id, 'status' => 'in_transit',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted',
        ]);
        $this->assertFalse(app(LogisticsActorPolicy::class)->decideCustody($rider->linked, $shop, $leg)['allowed']);
    }

    #[DataProvider('repairMovements')]
    public function test_disabled_logistics_rejects_new_and_replacement_repair_legs(string $method, bool $replacement): void
    {
        [$shop, $customer, $address] = $this->fixture();
        $repair = $this->repair($shop, $customer, $address);
        if ($replacement) {
            $shipment = Shipment::factory()->create([
                'shop_owner_id' => $shop->id, 'source_type' => 'repair_request',
                'source_id' => $repair->id, 'purpose' => $method === 'inbound' ? 'repair_pickup' : 'repair_return',
                'status' => 'cancelled', 'cancelled_at' => now()->subMinutes(2),
            ]);
            ShipmentLeg::factory()->create([
                'shipment_id' => $shipment->id, 'status' => 'cancelled', 'picked_up_at' => now()->subHour(),
            ]);
        }
        $this->assertBlocked(fn () => $method === 'inbound'
            ? app(SourceShipmentService::class)->ensureRepairInboundShipment($repair)
            : app(SourceShipmentService::class)->ensureRepairReturnShipment($repair));
        $this->assertDatabaseCount('shipment_legs', $replacement ? 1 : 0);
    }

    public static function repairMovements(): array
    {
        return [['inbound', false], ['return', false], ['inbound', true], ['return', true]];
    }

    public function test_disabled_logistics_does_not_dispatch_an_existing_unstarted_return(): void
    {
        [$shop, $customer, $address] = $this->fixture();
        $repair = $this->repair($shop, $customer, $address);
        $repair->update([
            'status' => 'ready_for_pickup',
            'return_address_confirmed_version' => data_get($repair->return_address, 'version'),
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id, 'source_type' => 'repair_request',
            'source_id' => $repair->id, 'purpose' => 'repair_return',
        ]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'shop_owner_id' => $shop->id, 'status' => 'pending',
        ]);
        $this->assertNull(app(RepairDeliveryService::class)->tryCreateReturnShipment($repair));
        $this->assertSame('ready_for_pickup', $repair->fresh()->status);
        $this->assertDatabaseCount('shipment_legs', 1);
    }

    public function test_third_party_order_tracking_remains_available_without_logistics(): void
    {
        [$shop] = $this->fixture();
        $order = Order::factory()->create(['shop_owner_id' => $shop->id, 'delivery_method' => 'third_party']);
        $shipment = app(SourceShipmentService::class)->ensureRetailOrderShipment($order);
        $this->assertSame($order->id, $shipment->source_id);
        $this->assertFalse($shipment->legs->first()->requires_delivery_proof);
        $this->assertSame('handoff_pending', $shipment->legs->first()->provider_status);
    }

    #[DataProvider('dispatchStages')]
    public function test_dispatch_revalidates_a_disabled_module_after_preflight(string $stage): void
    {
        [$shop] = $this->fixture(true);
        $shop->load('modules');
        LogisticsSetting::where('shop_owner_id', $shop->id)->update([
            'operating_days' => [1, 2, 3, 4, 5, 6, 7], 'blackout_dates' => [],
        ]);
        $order = Order::factory()->create(['shop_owner_id' => $shop->id, 'delivery_method' => 'shop_owned']);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id, 'source_type' => 'order',
            'source_id' => $order->id, 'purpose' => 'retail_delivery',
        ]);
        $date = now()->addDay()->toDateString();
        $legs = ShipmentLeg::factory()->count(2)->create([
            'shipment_id' => $shipment->id, 'shop_owner_id' => $shop->id,
            'leg_type' => 'outbound', 'status' => 'pending',
            'scheduled_delivery_date' => $date, 'delivery_window' => 'morning',
            'schedule_status' => $stage === 'schedule' ? 'unscheduled' : 'scheduled',
            'requires_pickup_proof' => false, 'requires_delivery_proof' => false,
        ]);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatch = app(BatchDispatchService::class);
        $assignments = app(AssignmentService::class);
        $batch = null;
        if (in_array($stage, ['offer', 'accept', 'start', 'restore'], true)) {
            $batch = $dispatch->createDraft($shop, $date, 'morning', $legs->modelKeys());
            if (in_array($stage, ['accept', 'start'], true)) {
                $batch = $dispatch->offer($batch, $rider, $shop);
            }
            if ($stage === 'start') {
                $batch = $dispatch->accept($batch, $rider);
            }
            if ($stage === 'restore') {
                $batch = $dispatch->cancel($batch, 'Paused before departure');
            }
        }
        if (in_array($stage, ['standalone_accept', 'standalone_pickup'], true)) {
            $assignments->assignInternalRider($legs->first(), $rider, $shop);
            if ($stage === 'standalone_pickup') {
                $assignments->respondToOffer($legs->first(), $rider, true);
            }
        }
        $beforeLegs = $legs->map(fn ($leg) => $leg->fresh()->getAttributes())->all();
        $beforeBatch = $batch?->fresh()->getAttributes();
        $assignmentCount = DeliveryAssignment::count();
        $eventCount = DeliveryEvent::count();
        $batchCount = DeliveryBatch::count();
        ShopOwnerModule::where('shop_owner_id', $shop->id)->where('module_key', 'logistics')->update(['enabled' => false]);

        $action = fn () => match ($stage) {
            'schedule' => $dispatch->schedule($shop, $date, 'morning', $legs->modelKeys()),
            'draft' => $dispatch->createDraft($shop, $date, 'morning', $legs->modelKeys()),
            'offer' => $dispatch->offer($batch, $rider, $shop),
            'accept' => $dispatch->accept($batch, $rider),
            'start' => $dispatch->start($batch, $rider),
            'restore' => $dispatch->restore($batch),
            'standalone_assign' => $assignments->assignInternalRider($legs->first(), $rider, $shop),
            'standalone_accept' => $assignments->respondToOffer($legs->first(), $rider, true),
            'standalone_pickup' => app(ShipmentLegService::class)->markPickedUp($legs->first(), $rider),
        };
        $this->assertBlocked($action);
        $this->assertSame($beforeLegs, $legs->map(fn ($leg) => $leg->fresh()->getAttributes())->all());
        $this->assertSame($beforeBatch, $batch?->fresh()->getAttributes());
        $this->assertSame($assignmentCount, DeliveryAssignment::count());
        $this->assertSame($eventCount, DeliveryEvent::count());
        $this->assertSame($batchCount, DeliveryBatch::count());

        ShopOwnerModule::where('shop_owner_id', $shop->id)->where('module_key', 'logistics')->update(['enabled' => true]);
        $action();
        $this->assertNotSame(
            [$beforeLegs, $beforeBatch, $batchCount, $assignmentCount, $eventCount],
            [$legs->map(fn ($leg) => $leg->fresh()->getAttributes())->all(), $batch?->fresh()->getAttributes(),
                DeliveryBatch::count(), DeliveryAssignment::count(), DeliveryEvent::count()],
        );
    }

    public static function dispatchStages(): array
    {
        return array_map(fn ($stage) => [$stage], [
            'schedule', 'draft', 'offer', 'accept', 'start', 'restore',
            'standalone_assign', 'standalone_accept', 'standalone_pickup',
        ]);
    }

    public function test_third_party_tracking_cannot_be_assigned_an_internal_rider_when_logistics_is_enabled(): void
    {
        [$shop] = $this->fixture(true);
        $order = Order::factory()->create(['shop_owner_id' => $shop->id, 'delivery_method' => 'third_party']);
        $shipment = app(SourceShipmentService::class)->ensureRetailOrderShipment($order);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);

        $this->assertBlocked(
            fn () => app(AssignmentService::class)->assignInternalRider($shipment->legs->first(), $rider, $shop),
            'shipment_leg_id',
        );
        $this->assertDatabaseCount('delivery_assignments', 0);
        $this->assertSame('pending', $shipment->legs->first()->fresh()->status->value);
    }

    public function test_dispatch_capability_does_not_authorize_third_party_tracking(): void
    {
        [$shop] = $this->fixture(true);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'Manager']);
        $dispatcher->givePermissionTo(Permission::findOrCreate('assign-logistics-deliveries', 'user'));
        $internal = ShipmentLeg::factory()->create([
            'shop_owner_id' => $shop->id,
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
        ]);
        $policy = app(LogisticsActorPolicy::class);
        $this->assertTrue($policy->decide($dispatcher, LogisticsAction::ASSIGN_RIDER, $shop, $internal)['allowed']);
        $order = Order::factory()->create(['shop_owner_id' => $shop->id, 'delivery_method' => 'third_party']);
        $external = app(SourceShipmentService::class)->ensureRetailOrderShipment($order)->legs->first();

        $decision = $policy->decide($dispatcher, LogisticsAction::ASSIGN_RIDER, $shop, $external);
        $this->assertFalse($decision['allowed']);
        $this->assertSame('third_party_tracking', $decision['reason_category']);
    }

    #[DataProvider('historicalExternalBatchActions')]
    public function test_historical_external_tracking_batches_cannot_advance_internal_dispatch(string $action): void
    {
        [$shop] = $this->fixture(true);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $batch = DeliveryBatch::factory()->create([
            'shop_owner_id' => $shop->id, 'rider_profile_id' => $rider->id,
            'status' => $action === 'accept' ? 'offered' : 'accepted',
        ]);
        for ($index = 1; $index <= 2; $index++) {
            $order = Order::factory()->create(['shop_owner_id' => $shop->id, 'delivery_method' => 'third_party']);
            $leg = app(SourceShipmentService::class)->ensureRetailOrderShipment($order)->legs->first();
            $leg->update(['delivery_batch_id' => $batch->id, 'stop_sequence' => $index, 'status' => 'assigned']);
            DeliveryAssignment::factory()->create([
                'shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id,
                'status' => $action === 'accept' ? 'assigned' : 'accepted',
            ]);
        }
        $before = $batch->fresh()->getAttributes();
        $beforeAssignments = $batch->legs()->with('assignments')->get()->flatMap->assignments
            ->map(fn ($assignment) => $assignment->getAttributes())->all();
        $events = DeliveryEvent::count();

        $this->assertBlocked(fn () => app(BatchDispatchService::class)->{$action}($batch, $rider), 'shipment_leg_id');
        $this->assertSame($before, $batch->fresh()->getAttributes());
        $this->assertSame($beforeAssignments, $batch->legs()->with('assignments')->get()->flatMap->assignments
            ->map(fn ($assignment) => $assignment->getAttributes())->all());
        $this->assertSame($events, DeliveryEvent::count());
    }

    public static function historicalExternalBatchActions(): array
    {
        return [['accept'], ['start']];
    }

    public function test_external_tracking_timestamps_cannot_authorize_internal_continuation(): void
    {
        [$shop] = $this->fixture();
        $order = Order::factory()->create(['shop_owner_id' => $shop->id, 'delivery_method' => 'third_party']);
        $leg = app(SourceShipmentService::class)->ensureRetailOrderShipment($order)->legs->first();
        $leg = app(ShipmentLegService::class)->updateThirdParty($leg, $shop, 'in_transit', []);
        $this->assertNotNull($leg->picked_up_at);
        $this->assertSame('in_transit', $leg->status->value);

        $this->assertFalse(app(LogisticsMovementEligibility::class)->canContinue($shop, $leg));
    }

    public function test_started_shipment_does_not_authorize_a_new_return_leg_or_retry(): void
    {
        [$shop] = $this->fixture();
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'status' => 'needs_resolution',
            'picked_up_at' => now()->subHour(), 'resolution_type' => 'return_required',
        ]);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'status' => 'accepted']);
        $this->assertBlocked(fn () => app(ShipmentLegService::class)->createReturnToShop($leg));
        $leg->update(['resolution_type' => null]);
        $this->assertBlocked(fn () => app(ShipmentLegService::class)->resolveRetry($leg, 'Try again tomorrow'));
        $this->assertDatabaseCount('shipment_legs', 1);
        $this->assertNull($leg->fresh()->resolution_type);
    }

    public function test_started_persisted_custody_can_continue_with_the_module_off(): void
    {
        [$shop] = $this->fixture();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'active']);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'shop_owner_id' => $shop->id,
            'status' => 'in_transit', 'picked_up_at' => now()->subHour(),
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted',
        ]);
        $rider->linked->givePermissionTo(Permission::findOrCreate('record-logistics-proof', 'user'));
        $policy = app(LogisticsActorPolicy::class);
        $this->assertTrue($policy->decideCustody($rider->linked, $shop, $leg)['allowed']);
        $this->assertTrue($policy->decide($rider->linked, LogisticsAction::SUBMIT_PROOF, $shop, $leg)['allowed']);
        $other = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $this->assertFalse($policy->decideCustody($other->linked, $shop, $leg)['allowed']);
    }

    public function test_unstarted_or_forged_leg_cannot_use_started_parent_as_continuation(): void
    {
        [$shop] = $this->fixture();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'active']);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'assigned']);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted',
        ]);
        $leg->status = 'in_transit';
        $leg->picked_up_at = now();
        $this->assertFalse(app(LogisticsActorPolicy::class)->decideCustody($rider->linked, $shop, $leg)['allowed']);
        $this->assertFalse(app(LogisticsActorPolicy::class)->decideCustody($rider->linked, $shop, new ShipmentLeg([
            'shipment_id' => $shipment->id, 'status' => 'in_transit', 'picked_up_at' => now(),
        ]))['allowed']);
    }

    #[DataProvider('walkInWarrantyMethods')]
    public function test_walk_in_only_original_cannot_create_a_warranty_delivery_even_with_a_saved_address(string $intake, string $return): void
    {
        [$shop, $customer, $address] = $this->fixture(enabled: true);
        $repair = $this->repair($shop, $customer, $address);
        $repair->update(['intake_delivery_method' => 'walk_in', 'return_delivery_method' => 'walk_in']);
        $this->assertBlocked(fn () => $this->claim($repair, $customer, $intake, $return), 'preferred_');
        $this->assertDatabaseCount('repair_warranty_claims', 0);
    }

    public static function walkInWarrantyMethods(): array
    {
        return [['customer_delivery', 'walk_in'], ['walk_in', 'customer_pickup'], ['shop_pickup', 'walk_in'], ['walk_in', 'shop_delivery']];
    }

    public function test_remote_original_preserves_customer_arranged_warranty_delivery_when_logistics_off(): void
    {
        [$shop, $customer, $address] = $this->fixture();
        $repair = $this->repair($shop, $customer, $address);
        $claim = $this->claim($repair, $customer, 'customer_delivery', 'customer_pickup');
        $this->assertSame('customer_delivery', $claim->preferred_return_method);
        $this->assertSame('customer_pickup', $claim->preferred_receive_method);
    }

    public function test_module_toggle_after_warranty_submission_blocks_approval_without_creating_a_job(): void
    {
        [$shop, $customer, $address] = $this->fixture(enabled: true);
        $repair = $this->repair($shop, $customer, $address);
        $claim = $this->claim($repair, $customer, 'shop_pickup', 'shop_delivery');
        $shop->modules()->where('module_key', 'logistics')->update(['enabled' => false]);
        $this->assertBlocked(fn () => app(RepairWarrantyService::class)->approveClaim($claim, $customer->id), 'preferred_');
        $this->assertNull($claim->fresh()->approved_repair_request_id);
        $this->assertDatabaseCount('repair_requests', 1);
        $this->assertDatabaseCount('shipment_legs', 0);
    }

    public function test_customer_cannot_change_an_unlocked_return_plan_to_shop_delivery_while_logistics_off(): void
    {
        [$shop, $customer, $address] = $this->fixture();
        $repair = $this->repair($shop, $customer, $address);
        $repair->update([
            'status' => 'in_progress', 'return_delivery_method' => 'customer_pickup',
            'return_logistics_locked_at' => null,
        ]);
        $this->actingAs($customer, 'user')->patchJson("/api/customer/repairs/{$repair->id}/delivery-method", [
            'return_delivery_method' => 'shop_delivery', 'return_address_id' => $address->id,
            'same_as_intake_address' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('return_address_id');
        $this->assertSame('customer_pickup', $repair->fresh()->return_delivery_method);
    }

    public function test_warranty_child_cannot_change_walk_in_only_original_transport_to_a_courier(): void
    {
        [$shop, $customer, $address] = $this->fixture(enabled: true);
        $original = $this->repair($shop, $customer, $address);
        $original->update(['intake_delivery_method' => 'walk_in', 'return_delivery_method' => 'walk_in']);
        $child = $this->repair($shop, $customer, $address);
        $child->update([
            'is_warranty_job' => true, 'billing_mode' => 'warranty_no_charge',
            'parent_repair_request_id' => $original->id, 'status' => 'in_progress',
            'intake_delivery_method' => 'walk_in', 'return_delivery_method' => 'walk_in',
            'return_logistics_locked_at' => null,
        ]);
        $this->actingAs($customer, 'user')->patchJson("/api/customer/repairs/{$child->id}/delivery-method", [
            'return_delivery_method' => 'customer_pickup', 'return_address_id' => $address->id,
            'same_as_intake_address' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('return_delivery_method');
        $this->assertSame('walk_in', $child->fresh()->return_delivery_method);
    }

    public function test_payment_after_module_toggle_preserves_service_settlement_and_reconciles_delivery_once(): void
    {
        [$shop, $customer, $address] = $this->fixture(enabled: true);
        $repair = $this->repair($shop, $customer, $address);
        $quote = app(RepairDeliveryService::class)->quote($shop, $address);
        $repair->update([
            'total' => 900, 'final_total' => 900, 'payment_policy' => 'full_upfront',
            'status' => 'repairer_accepted', 'payment_status' => 'pending', 'total_paid_amount' => 0,
            'intake_delivery_fee' => $quote['fee'], 'intake_logistics_quote' => $quote,
        ]);
        $session = RepairPaymentSession::create([
            'repair_request_id' => $repair->id, 'provider' => 'paymongo',
            'provider_link_id' => 'cs_before_module_toggle', 'phase' => 'initial', 'status' => 'pending',
            'snapshot_version' => data_get($repair->intake_address, 'version'),
            'delivery_method' => 'shop_pickup', 'service_amount' => 900, 'delivery_amount' => $quote['fee'],
            'quote' => ['payment_policy' => 'full_upfront', 'service_base_amount' => 900, 'tax_mode' => 'vat_inclusive'],
        ]);
        $shop->modules()->where('module_key', 'logistics')->update(['enabled' => false]);
        $payments = app(PaymentSettlementService::class);
        $result = $payments->settleRepairPaid($repair, 'pay_after_toggle', false, $session);
        $this->assertSame('reconciliation', $result['result']);
        $this->assertSame(900.0, (float) $repair->fresh()->total_paid_amount);
        $this->assertSame('reconciliation', $session->fresh()->status);
        $payments->settleRepairPaid($repair->fresh(), 'pay_after_toggle', false, $session->fresh());
        $this->assertCount(1, data_get($repair->fresh()->logistics_payment_reconciliation, 'entries'));
        $this->assertDatabaseCount('shipment_legs', 0);
    }

    public function test_disabled_module_continuation_preserves_proof_review_permissions_and_maker_checker(): void
    {
        [$shop] = $this->fixture();
        $reviewer = User::factory()->create(['shop_owner_id' => $shop->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'shop_owner_id' => $shop->id, 'status' => 'awaiting_proof_approval',
        ]);
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id, 'handoff_type' => 'delivery', 'review_status' => 'pending',
            'confirmed_by_type' => User::class, 'confirmed_by_id' => $reviewer->id,
        ]);
        $policy = app(LogisticsActorPolicy::class);
        $reviewer->givePermissionTo(Permission::findOrCreate('approve-proof-of-delivery', 'user'));
        $this->assertSame('maker_checker_conflict', $policy->decide($reviewer, LogisticsAction::REVIEW_PROOF, $shop, $leg, $proof)['reason_category']);
        $maker = User::factory()->create(['shop_owner_id' => $shop->id]);
        $proof->update(['confirmed_by_id' => $maker->id]);
        $this->assertTrue($policy->decide($reviewer, LogisticsAction::REVIEW_PROOF, $shop, $leg, $proof)['allowed']);
        $other = User::factory()->create(['shop_owner_id' => $shop->id]);
        $this->assertFalse($policy->decide($other, LogisticsAction::REVIEW_PROOF, $shop, $leg, $proof)['allowed']);
    }

    #[DataProvider('existingRetailMovements')]
    public function test_existing_retail_movement_requires_a_persisted_started_leg_when_logistics_is_off(string $source, bool $started): void
    {
        [$shop, $customer] = $this->fixture();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id, 'customer_id' => $customer->id,
            'delivery_method' => 'shop_owned', 'carrier_company' => 'Shop-owned logistics',
        ]);
        $refund = $source === 'order_refund' ? OrderRefund::factory()->create([
            'order_id' => $order->id, 'shop_owner_id' => $shop->id, 'customer_id' => $customer->id,
            'shop_owner_status' => 'approved', 'finance_status' => 'approved',
            'return_source' => 'staff', 'return_status' => 'pending_staff_pickup',
            'staff_return_carrier' => 'Shop-owned logistics',
        ]) : null;
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id, 'source_type' => $source,
            'source_id' => $refund?->id ?? $order->id,
            'purpose' => $refund ? 'refund_return' : 'retail_delivery', 'status' => 'active',
        ]);
        ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'shop_owner_id' => $shop->id,
            'status' => $started ? 'in_transit' : 'assigned',
            'picked_up_at' => $started ? now()->subHour() : null,
        ]);
        $action = fn () => $refund
            ? app(SourceShipmentService::class)->ensureRefundReturnShipment($refund)
            : app(SourceShipmentService::class)->ensureRetailOrderShipment($order);
        if ($started) {
            $this->assertSame($shipment->id, $action()->id);
        } else {
            $this->assertBlocked($action);
        }
        $this->assertDatabaseCount('shipment_legs', 1);
    }

    public static function existingRetailMovements(): array
    {
        return [['order', false], ['order', true], ['order_refund', false], ['order_refund', true]];
    }

    public function test_stale_third_party_order_attributes_cannot_authorize_a_new_internal_shipment(): void
    {
        [$shop] = $this->fixture();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id, 'delivery_method' => 'third_party', 'carrier_company' => 'J&T Express',
        ]);
        Order::query()->whereKey($order->id)->update([
            'delivery_method' => 'shop_owned', 'carrier_company' => 'Shop-owned logistics',
        ]);
        $this->assertBlocked(fn () => app(SourceShipmentService::class)->ensureRetailOrderShipment($order));
        $this->assertDatabaseCount('shipments', 0);
    }

    #[DataProvider('invalidWarrantyAddresses')]
    public function test_warranty_choices_exclude_missing_unowned_unpinned_and_uncovered_addresses(string $invalid): void
    {
        [$shop, $customer, $address] = $this->fixture(enabled: true);
        $repair = $this->repair($shop, $customer, $address);
        if ($invalid === 'missing') {
            $address->delete();
        } elseif ($invalid === 'unowned') {
            $address->update(['user_id' => User::factory()->create()->id]);
        } elseif ($invalid === 'unpinned') {
            $address->update(['latitude' => null, 'longitude' => null]);
        } else {
            $address->update(['latitude' => 15.0, 'longitude' => 121.5]);
        }
        $methods = app(RepairWarrantyService::class)->warrantyState($repair, $customer->id)['delivery_methods'];
        $this->assertNotContains('shop_pickup', $methods['intake']);
        $this->assertNotContains('shop_delivery', $methods['return']);
        if (in_array($invalid, ['missing', 'unowned', 'unpinned'], true)) {
            $this->assertSame(['walk_in'], $methods['intake']);
            $this->assertSame(['walk_in'], $methods['return']);
        } else {
            $this->assertContains('customer_delivery', $methods['intake']);
            $this->assertContains('customer_pickup', $methods['return']);
        }
    }

    public static function invalidWarrantyAddresses(): array
    {
        return [['missing'], ['unowned'], ['unpinned'], ['uncovered']];
    }

    public function test_warranty_pickup_recovery_cannot_override_walk_in_only_original(): void
    {
        [$shop, $customer, $address] = $this->fixture(enabled: true);
        $original = $this->repair($shop, $customer, $address);
        $original->update(['intake_delivery_method' => 'walk_in', 'return_delivery_method' => 'walk_in']);
        $child = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id, 'user_id' => $customer->id,
            'parent_repair_request_id' => $original->id, 'is_warranty_job' => true,
            'billing_mode' => 'warranty_no_charge', 'status' => 'cancelled',
            'logistics_payment_reconciliation' => ['status' => 'resolved', 'entries' => [[
                'type' => 'pickup_recovery', 'status' => 'awaiting_arrangement', 'failed_leg_id' => 123,
            ]]],
        ]);
        $this->assertBlocked(fn () => app(RepairDeliveryService::class)->resolvePickupRecovery(
            $child, 'customer_delivery', User::class, $customer->id, $address->id,
        ), 'method');
        $this->assertSame('cancelled', $child->fresh()->status);
        $this->assertDatabaseCount('shipments', 0);
    }

    #[DataProvider('repairDirections')]
    public function test_repair_source_lookup_never_reuses_another_shops_shipment(string $direction): void
    {
        [$shop, $customer, $address] = $this->fixture(enabled: true);
        $repair = $this->repair($shop, $customer, $address);
        $foreignShop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $foreign = Shipment::factory()->create([
            'shop_owner_id' => $foreignShop->id, 'source_type' => 'repair_request',
            'source_id' => $repair->id, 'purpose' => $direction === 'intake' ? 'repair_pickup' : 'repair_return',
        ]);
        ShipmentLeg::factory()->create(['shipment_id' => $foreign->id, 'shop_owner_id' => $foreignShop->id]);
        $this->assertBlocked(fn () => $direction === 'intake'
            ? app(SourceShipmentService::class)->ensureRepairInboundShipment($repair)
            : app(SourceShipmentService::class)->ensureRepairReturnShipment($repair), 'shipment');
        $this->assertDatabaseCount('shipments', 1);
        $this->assertSame(1, $foreign->legs()->count());
    }

    public static function repairDirections(): array
    {
        return [['intake'], ['return']];
    }

    public function test_cancelled_parent_cannot_authorize_started_leg_continuation(): void
    {
        [$shop] = $this->fixture();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'cancelled']);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id, 'shop_owner_id' => $shop->id,
            'status' => 'in_transit', 'picked_up_at' => now()->subHour(),
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted',
        ]);
        $this->assertFalse(app(LogisticsActorPolicy::class)->decideCustody($rider->linked, $shop, $leg)['allowed']);
    }

    private function claim(RepairRequest $repair, User $customer, string $intake, string $return)
    {
        Storage::fake('public');
        return app(RepairWarrantyService::class)->createCustomerClaim($repair, $customer, [
            'reason_code' => 'issue_returned', 'reason_details' => 'Same seam reopened',
            'same_issue_confirmation' => true,
            'preferred_return_method' => $intake, 'preferred_receive_method' => $return,
        ], [UploadedFile::fake()->create('proof.jpg', 64, 'image/jpeg')]);
    }

    private function fixture(bool $enabled = false): array
    {
        $shop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company', 'business_type' => 'both', 'warranty_enabled' => true,
            'repair_warranty_days' => 30, 'shop_latitude' => 14.5995, 'shop_longitude' => 120.9842,
        ]);
        ShopOwnerModule::factory()->create(['shop_owner_id' => $shop->id, 'module_key' => 'logistics', 'enabled' => $enabled]);
        LogisticsSetting::create(['shop_owner_id' => $shop->id, 'coverage_radius_km' => 10]);
        $customer = User::factory()->create();
        $address = UserAddress::create([
            'user_id' => $customer->id, 'name' => 'Customer', 'phone' => '09171234567',
            'region' => 'NCR', 'province' => 'Metro Manila', 'city' => 'Manila',
            'barangay' => 'Ermita', 'postal_code' => '1000', 'address_line' => '12 Test Street',
            'latitude' => 14.6000, 'longitude' => 120.9800,
        ]);
        return [$shop, $customer, $address];
    }

    private function repair(ShopOwner $shop, User $customer, UserAddress $address): RepairRequest
    {
        $delivery = app(RepairDeliveryService::class);
        return RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id, 'user_id' => $customer->id, 'status' => 'picked_up',
            'payment_status' => 'completed', 'total_paid_amount' => 900, 'picked_up_at' => now()->subDay(),
            'repair_warranty_issued' => true, 'repair_warranty_started_at' => now()->subDay(),
            'repair_warranty_expires_at' => now()->addDays(29), 'repair_warranty_duration' => 30,
            'repair_warranty_duration_unit' => 'days', 'is_warranty_job' => false,
            'intake_delivery_method' => 'shop_pickup', 'return_delivery_method' => 'shop_delivery',
            'intake_address' => $delivery->snapshot($address, 'shop_pickup'),
            'return_address' => $delivery->snapshot($address, 'shop_delivery'),
            'intake_logistics_locked_at' => now(), 'return_logistics_locked_at' => now(),
            'logistics_payment_reconciliation' => ['status' => 'resolved'],
        ]);
    }

    private function assertBlocked(callable $action, string $errorKey = 'logistics'): void
    {
        try {
            $action();
            $this->fail('A new movement must be rejected before creating workflow records.');
        } catch (ValidationException $exception) {
            $this->assertTrue(collect(array_keys($exception->errors()))->contains(fn ($key) => str_starts_with($key, $errorKey)), json_encode($exception->errors()));
        }
    }
}
