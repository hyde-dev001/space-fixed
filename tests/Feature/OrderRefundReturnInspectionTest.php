<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\OrderRefundItem;
use App\Models\Logistics\Shipment;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OrderRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderRefundReturnInspectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_return_requires_every_refund_line_to_be_inspected_exactly_once(): void
    {
        [$refund, $staff, $line] = $this->fixture();
        $service = app(OrderRefundService::class);

        $missing = $service->confirmReturnReceived($refund, $staff->id, lineDispositions: []);
        $this->assertSame('invalid_state', $missing['result']);
        $this->assertSame('in_transit', $refund->fresh()->return_status);

        $duplicate = $service->confirmReturnReceived($refund->fresh(), $staff->id, lineDispositions: [
            $this->disposition($line->order_item_id),
            $this->disposition($line->order_item_id),
        ]);
        $this->assertSame('invalid_state', $duplicate['result']);
        $this->assertSame('in_transit', $refund->fresh()->return_status);
    }

    public function test_complete_company_inspection_marks_return_received_and_applies_inventory(): void
    {
        [$refund, $staff, $line, $product] = $this->fixture();
        $startingStock = (int) $product->stock_quantity;

        $result = app(OrderRefundService::class)->confirmReturnReceived(
            $refund,
            $staff->id,
            'Inspected at the shop.',
            [$this->disposition($line->order_item_id)],
        );

        $this->assertSame('received', $result['result']);
        $this->assertDatabaseHas('order_refunds', ['id' => $refund->id, 'return_status' => 'received']);
        $this->assertDatabaseHas('order_refund_items', [
            'id' => $line->id,
            'inspection_disposition' => 'resellable',
            'inventory_action' => 'restock',
        ]);
        $this->assertSame($startingStock + 1, (int) $product->fresh()->stock_quantity);
    }

    public function test_full_refund_without_saved_lines_uses_every_original_order_item_as_the_checklist(): void
    {
        [$refund, $staff, $line, $product] = $this->fixture();
        $orderItemId = (int) $line->order_item_id;
        $line->delete();

        $result = app(OrderRefundService::class)->confirmReturnReceived(
            $refund,
            $staff->id,
            lineDispositions: [$this->disposition($orderItemId)],
        );

        $this->assertSame('received', $result['result']);
        $this->assertDatabaseHas('order_refund_items', [
            'order_refund_id' => $refund->id,
            'order_item_id' => $orderItemId,
            'inspection_disposition' => 'resellable',
            'inventory_action' => 'restock',
        ]);
        $this->assertSame(6, (int) $product->fresh()->stock_quantity);
    }

    public function test_individual_return_cannot_be_received_without_complete_inspection(): void
    {
        [$refund, $staff, $line, $product] = $this->fixture('individual');

        $result = app(OrderRefundService::class)->confirmReturnReceived(
            $refund,
            $staff->id,
            lineDispositions: [],
        );

        $this->assertSame('invalid_state', $result['result']);
        $this->assertSame('in_transit', $refund->fresh()->return_status);

        $result = app(OrderRefundService::class)->confirmReturnReceived(
            $refund->fresh(),
            $staff->id,
            lineDispositions: [$this->disposition($line->order_item_id)],
        );

        $this->assertSame('received', $result['result']);
        $this->assertDatabaseHas('order_refund_items', [
            'id' => $line->id,
            'inspection_disposition' => 'resellable',
            'inventory_action' => 'restock',
        ]);
        $this->assertSame(6, (int) $product->fresh()->stock_quantity);
    }

    public function test_individual_damaged_return_is_written_off_without_restocking(): void
    {
        [$refund, $staff, $line, $product] = $this->fixture('individual');
        $startingStock = (int) $product->stock_quantity;

        $result = app(OrderRefundService::class)->confirmReturnReceived(
            $refund,
            $staff->id,
            lineDispositions: [[
                ...$this->disposition($line->order_item_id),
                'inspection_disposition' => 'damaged',
            ]],
        );

        $this->assertSame('received', $result['result']);
        $this->assertDatabaseHas('order_refund_items', [
            'id' => $line->id,
            'inspection_disposition' => 'damaged',
            'inventory_action' => 'write_off',
        ]);
        $this->assertSame($startingStock, (int) $product->fresh()->stock_quantity);
    }

    public function test_individual_return_cannot_be_confirmed_before_customer_submits_shipment(): void
    {
        [$refund, $staff] = $this->fixture('individual');
        $refund->update([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'pending_customer_shipment',
        ]);

        $result = app(OrderRefundService::class)->confirmReturnReceived(
            $refund->fresh(),
            $staff->id,
            lineDispositions: [],
        );

        $this->assertSame('invalid_state', $result['result']);
        $this->assertSame('pending_customer_shipment', $refund->fresh()->return_status);
    }

    public function test_customer_can_submit_individual_return_shipment_details_after_approval(): void
    {
        [$refund] = $this->fixture('individual');
        $refund->update([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'pending_customer_shipment',
        ]);

        $this->actingAs($refund->customer, 'user')
            ->postJson("/orders/refunds/{$refund->id}/mark-shipped-return", [
                'tracking_number' => 'TRK-IND-001',
                'carrier' => 'J&T',
            ])
            ->assertOk()
            ->assertJsonPath('refund.return_status', 'in_transit')
            ->assertJsonPath('refund.customer_return_tracking_number', 'TRK-IND-001');

        $this->assertDatabaseHas('order_refunds', [
            'id' => $refund->id,
            'return_status' => 'in_transit',
            'customer_return_tracking_number' => 'TRK-IND-001',
            'customer_return_carrier' => 'J&T',
        ]);
        $this->assertDatabaseMissing('shipments', [
            'source_type' => 'order_refund',
            'source_id' => $refund->id,
            'purpose' => 'refund_return',
        ]);
    }

    public function test_customer_can_request_shop_owned_return_for_dispatcher_assignment(): void
    {
        [$refund] = $this->fixture('individual');
        $refund->update([
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'pending_customer_shipment',
        ]);

        $this->actingAs($refund->customer, 'user')
            ->postJson("/orders/refunds/{$refund->id}/mark-shipped-return", [
                'delivery_method' => 'shop_owned',
                'note' => 'Please arrange pickup from my delivery address.',
            ])
            ->assertOk()
            ->assertJsonPath('refund.return_status', 'pending_staff_pickup')
            ->assertJsonPath('refund.return_source', 'staff')
            ->assertJsonPath('refund.staff_return_carrier', 'Shop-owned logistics')
            ->assertJsonPath('refund.logistics_shipment_id', fn ($value) => is_int($value));

        $this->assertDatabaseHas('order_refunds', [
            'id' => $refund->id,
            'return_status' => 'pending_staff_pickup',
            'return_source' => 'staff',
            'staff_return_carrier' => 'Shop-owned logistics',
        ]);
        $this->assertDatabaseHas('shipments', [
            'source_type' => 'order_refund',
            'source_id' => $refund->id,
            'purpose' => 'refund_return',
        ]);
        $this->assertDatabaseHas('shipment_legs', [
            'leg_type' => 'return_to_shop',
        ]);
    }

    public function test_company_staff_third_party_tracking_starts_customer_return_and_allows_physical_receipt_inspection(): void
    {
        [$refund, $staff, $line] = $this->fixture();
        $refund->update([
            'return_status' => 'pending_customer_shipment',
            'return_source' => 'customer',
        ]);

        $result = app(OrderRefundService::class)->arrangeStaffReturnPickup(
            $refund->fresh(),
            [
                'delivery_method' => 'third_party',
                'carrier_company' => 'J&T Express',
                'rider_name' => 'Juan Rider',
                'rider_phone' => '09171234567',
                'tracking_number' => 'JT-RETURN-001',
                'tracking_link' => 'https://example.test/returns/JT-RETURN-001',
            ],
            $staff->id,
        );

        $this->assertSame('pickup_arranged', $result['result']);
        $this->assertDatabaseHas('order_refunds', [
            'id' => $refund->id,
            'return_status' => 'in_transit',
            'return_source' => 'customer',
            'customer_return_carrier' => 'J&T Express',
            'customer_return_tracking_number' => 'JT-RETURN-001',
        ]);
        $savedRefund = $refund->fresh();
        $this->assertSame('Juan Rider', $savedRefund->customer_return_rider_name);
        $this->assertNull($savedRefund->staff_return_carrier);
        $this->assertDatabaseMissing('shipments', [
            'source_type' => 'order_refund',
            'source_id' => $refund->id,
            'purpose' => 'refund_return',
        ]);

        $notYetReceived = app(OrderRefundService::class)->confirmReturnReceived(
            $savedRefund,
            $staff->id,
            lineDispositions: [],
        );
        $this->assertSame('invalid_state', $notYetReceived['result']);
        $this->assertSame('in_transit', $refund->fresh()->return_status);

        $received = app(OrderRefundService::class)->confirmReturnReceived(
            $refund->fresh(),
            $staff->id,
            lineDispositions: [$this->disposition($line->order_item_id)],
        );

        $this->assertSame('received', $received['result']);
        $this->assertDatabaseHas('order_refunds', [
            'id' => $refund->id,
            'return_status' => 'received',
        ]);
    }

    public function test_third_party_return_ignores_stale_shop_owned_shipment_in_customer_tracking(): void
    {
        [$refund, $staff] = $this->fixture();
        $refund->update([
            'return_status' => 'pending_customer_shipment',
            'return_source' => 'customer',
        ]);

        app(OrderRefundService::class)->arrangeStaffReturnPickup(
            $refund->fresh(),
            [
                'delivery_method' => 'third_party',
                'carrier_company' => 'LBC',
                'rider_name' => 'Maria Rider',
                'rider_phone' => '09171234568',
                'tracking_number' => 'LBC-RETURN-001',
                'tracking_link' => 'https://example.test/returns/LBC-RETURN-001',
            ],
            $staff->id,
        );

        $staleShipment = Shipment::factory()->create([
            'shop_owner_id' => $refund->shop_owner_id,
            'source_type' => 'order_refund',
            'source_id' => $refund->id,
            'purpose' => 'refund_return',
            'status' => 'requested',
        ]);

        $this->actingAs($refund->customer, 'user')
            ->get('/my-orders')
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.0.refund_stage.return_delivery_method', 'third_party')
                ->where('orders.0.refund_stage.is_shop_owned_return', false)
                ->where('orders.0.refund_stage.logistics_shipment_id', null)
                ->where('orders.0.refund_stage.customer_return_tracking_number', 'LBC-RETURN-001')
            );

        $this->assertDatabaseHas('shipments', ['id' => $staleShipment->id]);
    }

    private function fixture(string $registrationType = 'company'): array
    {
        $shop = ShopOwner::factory()->create(['registration_type' => $registrationType]);
        $customer = User::factory()->create();
        $staff = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        $product = Product::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Inspection Test Shoe',
            'slug' => 'inspection-test-shoe-' . uniqid(),
            'price' => 1000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'payment_status' => 'paid',
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'price' => 1000,
            'quantity' => 1,
            'subtotal' => 1000,
        ]);
        $refund = OrderRefund::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $shop->id,
            'flow_type' => 'request_approval',
            'status' => 'pending_approval',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'in_transit',
            'reason_code' => 'quality_issue',
        ]);
        $line = OrderRefundItem::create([
            'order_refund_id' => $refund->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $product->id,
            'requested_qty' => 1,
            'approved_qty' => 1,
            'unit_price_snapshot' => 1000,
            'line_amount' => 1000,
        ]);

        return [$refund, $staff, $line, $product];
    }

    private function disposition(int $orderItemId): array
    {
        return [
            'order_item_id' => $orderItemId,
            'approved_qty' => 1,
            'inspection_disposition' => 'resellable',
        ];
    }
}
