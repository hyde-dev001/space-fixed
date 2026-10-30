<?php
namespace Tests\Feature\Logistics;

use App\Models\Logistics\{DeliveryAssignment, LogisticsSetting, RiderProfile, Shipment, ShipmentLeg};
use App\Models\{OrderRefund, ShopOwner, User};
use App\Services\Logistics\{ArrivalService, ShipmentLegService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class RetailReturnFailureRecoveryTest extends \Tests\TestCase
{
    use RefreshDatabase;

    public function test_failed_retail_collection_is_retryable_without_inventing_custody_or_refunds(): void
    {
        $shop = ShopOwner::factory()->approved()->withLogistics()->create(['registration_type' => 'company', 'business_type' => 'retail']);
        LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], ['max_delivery_attempts' => 1, 'operating_days' => range(1, 7)]);
        $order = \App\Models\Order::factory()->create(['shop_owner_id' => $shop->id]);
        $refund = OrderRefund::factory()->create(['order_id' => $order->id, 'customer_id' => $order->customer_id, 'shop_owner_id' => $shop->id, 'shop_owner_status' => 'approved', 'finance_status' => 'approved', 'return_status' => 'pending_staff_pickup']);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id]);
        $user = User::findOrFail($rider->linked_id);
        foreach (['update-logistics-status', 'record-logistics-proof'] as $permission) $user->givePermissionTo(Permission::findOrCreate($permission, 'user'));
        $this->clockInEmployee($user);
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order_refund', 'source_id' => $refund->id, 'purpose' => 'refund_return']);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'leg_type' => 'return_to_shop', 'status' => 'assigned', 'picked_up_at' => null, 'origin_snapshot' => ['type' => 'customer']]);
        $assignment = DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        app(ArrivalService::class)->record($leg, $user, ['arrival_type' => 'pickup']);
        app(ShipmentLegService::class)->recordFailedAttempt($leg, ['attempt_type' => 'pickup', 'delivery_assignment_id' => $assignment->id, 'reason_code' => 'customer_unavailable', 'file_path' => 'logistics-attempt/test.jpg', 'idempotency_key' => (string) Str::uuid()], true);
        $this->assertSame('needs_resolution', $leg->fresh()->status->value);
        $this->assertSame('pickup_failed', $leg->fresh()->resolution_type);
        $this->assertNull($leg->fresh()->custody_confirmed_at);
        $this->assertSame('pending_staff_pickup', $refund->fresh()->return_status);
        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        foreach (['assign-logistics-deliveries', 'view-logistics-shipments', 'access-logistics-dashboard'] as $permission) $dispatcher->givePermissionTo(Permission::findOrCreate($permission, 'user'));
        $this->actingAs($dispatcher, 'user')->get('/erp/logistics/shipments?status=failed_pickups')
            ->assertOk()->assertInertia(fn ($page) => $page->has('shipments.data', 1));
        app(ShipmentLegService::class)->resolveRetry($leg->fresh(), 'Customer agreed to another collection');
        $this->assertSame('assigned', $leg->fresh()->status->value);
        $this->assertSame(2, $leg->assignments()->count());
        $this->assertSame(1, OrderRefund::whereKey($refund->id)->count());
        $this->assertSame('approved', $refund->fresh()->finance_status);
    }
}
