<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Notification;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\AssignmentService;
use App\Services\Logistics\BatchDispatchService;
use App\Services\Logistics\DeliveryEventService;
use App\Services\Logistics\ShipmentRequestService;
use App\Services\Logistics\ProofService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LogisticsNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_notification_is_created_for_customer_visible_delivery_event(): void
    {
        Notification::query()->delete();
        $user = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $user->id]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $order->shop_owner_id,
            'source_type' => 'order',
            'source_id' => $order->id,
        ]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);

        app(DeliveryEventService::class)->record($shipment, $leg, [
            'event_type' => 'in_transit',
            'visibility' => 'customer',
            'message' => 'Your order is in transit.',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'logistics_in_transit',
            'action_url' => "/tracking/shipments/{$shipment->id}",
        ]);
    }

    public function test_dispatcher_is_notified_when_a_shipment_is_requested(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $shop = ShopOwner::factory()->create();
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole('Logistics Dispatcher');

        $shipment = app(ShipmentRequestService::class)->requestShipment([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => 1,
            'purpose' => 'retail_delivery',
            'legs' => [[
                'leg_type' => 'outbound',
            ]],
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $dispatcher->id,
            'type' => 'logistics_shipment_requested',
            'action_url' => '/erp/logistics/shipments',
        ]);
    }

    public function test_dispatcher_is_notified_when_delivery_proof_awaits_approval(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $shop = ShopOwner::factory()->create();
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->assignRole('Logistics Dispatcher');
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'in_transit']);

        app(ProofService::class)->recordProof($leg, ['handoff_type' => 'delivery', 'proof_type' => 'photo']);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $dispatcher->id,
            'type' => 'logistics_proof_required',
            'action_url' => '/erp/logistics/shipments?status=awaiting_proof_approval',
            'requires_action' => true,
        ]);
    }

    public function test_rider_is_notified_when_a_delivery_is_assigned(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $riderUser = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $riderUser->id,
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);

        app(AssignmentService::class)->assignInternalRider($leg, $rider, $shop);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $riderUser->id,
            'type' => 'logistics_assigned',
            'action_url' => '/erp/logistics/shipments',
        ]);
    }

    public function test_rider_is_notified_once_when_a_batch_is_offered(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $riderUser = User::factory()->create(['shop_owner_id' => $shop->id]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $riderUser->id,
            'active' => true,
            'availability_status' => 'available',
        ]);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $legs = ShipmentLeg::factory()->count(2)->create([
            'shipment_id' => $shipment->id,
            'scheduled_delivery_date' => '2026-07-15',
            'delivery_window' => 'morning',
            'schedule_status' => 'scheduled',
            'status' => 'pending',
        ]);

        $service = app(BatchDispatchService::class);
        $batch = $service->createDraft($shop, '2026-07-15', 'morning', $legs->pluck('id')->all());
        $service->offer($batch, $rider, $shop);

        $this->assertSame(1, Notification::query()
            ->where('user_id', $riderUser->id)
            ->where('type', 'logistics_batch_offered')
            ->count());
        $this->assertSame(0, Notification::query()
            ->where('user_id', $riderUser->id)
            ->where('type', 'logistics_assigned')
            ->count());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $riderUser->id,
            'title' => 'Delivery Batch Offered',
            'message' => 'A delivery batch with 2 stops has been offered to you.',
            'action_url' => '/erp/logistics/deliveries',
        ]);
        $this->assertSame(2, DeliveryEvent::where('event_type', 'leg_assigned')->count());
        $this->assertSame(1, DeliveryEvent::where('event_type', 'batch_offered')->count());
    }

    public function test_retail_order_staff_is_notified_once_when_a_delivery_is_cancelled(): void
    {
        $shop = ShopOwner::factory()->create();
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        Permission::findOrCreate('access-staff-job-orders', 'user');
        $staff->givePermissionTo('access-staff-job-orders');
        $order = Order::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'shipped']);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
        ]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id]);

        app(DeliveryEventService::class)->record($shipment, $leg, [
            'event_type' => 'delivery_cancelled',
            'visibility' => 'customer',
            'message' => 'Delivery cancelled: Recipient was unavailable.',
        ]);

        $this->assertSame('shipped', $order->fresh()->status->value);
        $this->assertSame(1, Notification::query()->where('user_id', $staff->id)->count());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $staff->id,
            'type' => 'logistics_delivery_failed',
            'priority' => 'high',
            'message' => 'Delivery cancelled: Recipient was unavailable.',
            'action_url' => '/erp/staff/job-orders',
            'requires_action' => true,
        ]);
    }
}
