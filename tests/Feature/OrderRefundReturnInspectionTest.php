<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\OrderRefundItem;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OrderRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function fixture(): array
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
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
