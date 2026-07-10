<?php

namespace Tests\Feature\Logistics;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\SourceShipmentService;
use App\Services\OrderRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SourceModuleShipmentRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_marked_shipped_requests_outbound_shipment(): void
    {
        $shop = ShopOwner::factory()->create([
            'business_type' => 'retail',
            'registration_type' => 'individual',
            'status' => 'approved',
        ]);
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'processing',
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->patchJson("/api/shop-owner/orders/{$order->id}/status", [
                'status' => 'shipped',
            ])
            ->assertOk();

        $this->assertDatabaseHas('shipments', [
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
        ]);
        $this->assertDatabaseHas('shipment_legs', [
            'leg_type' => 'outbound',
        ]);
    }

    public function test_staff_marking_order_shipped_requests_outbound_shipment(): void
    {
        $shop = ShopOwner::factory()->create([
            'business_type' => 'retail',
            'registration_type' => 'company',
            'status' => 'approved',
        ]);
        $staff = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'STAFF',
        ]);
        Permission::findOrCreate('access-staff-job-orders', 'user');
        $staff->givePermissionTo('access-staff-job-orders');
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'processing',
        ]);

        $this->actingAs($staff, 'user')
            ->patchJson("/api/staff/orders/{$order->id}/status", [
                'status' => 'shipped',
                'carrier_company' => 'Shop-owned logistics',
                'eta' => '1-2 business days',
            ])
            ->assertOk();

        $this->assertDatabaseHas('shipments', [
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
        ]);
        $this->assertDatabaseHas('shipment_legs', [
            'leg_type' => 'outbound',
        ]);
    }

    public function test_approved_refund_return_creates_inbound_shipment(): void
    {
        $shop = ShopOwner::factory()->create();
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
        ]);
        $refund = OrderRefund::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $shop->id,
            'return_status' => 'pending_customer_shipment',
        ]);

        app(OrderRefundService::class)->ensureReturnShipment($refund);

        $this->assertDatabaseHas('shipments', [
            'source_type' => 'order_refund',
            'source_id' => $refund->id,
            'purpose' => 'refund_return',
        ]);
        $this->assertDatabaseHas('shipment_legs', [
            'leg_type' => 'inbound',
        ]);
    }

    public function test_staff_can_arrange_a_shop_owned_return_pickup(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $staff = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        Permission::findOrCreate('access-staff-job-orders', 'user');
        $staff->givePermissionTo('access-staff-job-orders');
        $order = Order::factory()->create(['shop_owner_id' => $shop->id]);
        $refund = OrderRefund::factory()->create([
            'order_id' => $order->id,
            'shop_owner_id' => $shop->id,
            'flow_type' => 'request_approval',
            'status' => 'processing',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'pending_customer_shipment',
        ]);

        $this->actingAs($staff, 'user')
            ->postJson("/api/staff/orders/{$order->id}/arrange-return-pickup", [
                'delivery_method' => 'shop_owned',
            ])
            ->assertOk();

        $this->assertDatabaseHas('order_refunds', [
            'id' => $refund->id,
            'staff_return_carrier' => 'Shop-owned logistics',
            'return_status' => 'pending_staff_pickup',
        ]);
        $this->assertDatabaseHas('shipments', [
            'source_type' => 'order_refund',
            'source_id' => $refund->id,
            'purpose' => 'refund_return',
        ]);

        $this->actingAs($staff, 'user')
            ->postJson("/api/staff/orders/{$order->id}/arrange-return-pickup", [
                'delivery_method' => 'shop_owned',
            ])
            ->assertStatus(422);
    }

    public function test_repair_pickup_creates_inbound_shipment(): void
    {
        $shop = ShopOwner::factory()->create();
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'delivery_method' => 'pickup',
            'intake_delivery_method' => 'customer_delivery',
            'intake_address' => [
                'address_line' => '123 Customer Street',
                'barangay' => 'Barangay One',
                'city' => 'Quezon City',
                'region' => 'NCR',
                'postal_code' => '1100',
            ],
        ]);

        app(SourceShipmentService::class)->ensureRepairInboundShipment($repair);

        $this->assertDatabaseHas('shipments', [
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_pickup',
        ]);
        $this->assertDatabaseHas('shipment_legs', [
            'leg_type' => 'inbound',
        ]);
    }

    public function test_repair_return_creates_outbound_shipment(): void
    {
        $shop = ShopOwner::factory()->create();
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'shipped',
            'return_delivery_method' => 'shop_delivery',
            'return_address' => [
                'address_line' => '456 Return Avenue',
                'barangay' => 'Barangay Two',
                'city' => 'Makati',
                'region' => 'NCR',
                'postal_code' => '1200',
            ],
        ]);

        app(SourceShipmentService::class)->ensureRepairReturnShipment($repair);

        $this->assertDatabaseHas('shipments', [
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_return',
        ]);
        $this->assertDatabaseHas('shipment_legs', [
            'leg_type' => 'outbound',
        ]);
    }
}
