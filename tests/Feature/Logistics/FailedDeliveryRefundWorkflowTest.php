<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OrderRefundService;
use App\Services\PaymongoRefundService;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FailedDeliveryRefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_max_attempt_refund_reserves_remaining_capture_once_and_reconciles_lines(): void
    {
        [$order, $leg, $items] = $this->paidOrderWithOutboundLeg();
        $service = app(OrderRefundService::class);

        $first = $service->reserveFailedDeliveryRefund($order, $leg);
        $refund = $first['refund']->fresh('items');

        $this->assertSame('reserved', $first['result']);
        $this->assertSame('request_approval', $refund->flow_type);
        $this->assertSame('requested', $refund->status);
        $this->assertSame('approved', $refund->shop_owner_status);
        $this->assertNull($refund->shop_owner_approved_by);
        $this->assertSame('pending', $refund->finance_status);
        $this->assertSame('pending_staff_pickup', $refund->return_status);
        $this->assertSame('staff', $refund->return_source);
        $this->assertSame('Shop-owned logistics', $refund->staff_return_carrier);
        $this->assertSame(1100.0, round((float) $refund->amount, 2));
        $this->assertSame("delivery-attempts-exhausted:{$order->id}:{$leg->id}", $refund->idempotency_key);
        $this->assertCount(2, $refund->items);
        $this->assertEqualsCanonicalizing($items->pluck('id')->all(), $refund->items->pluck('order_item_id')->all());
        $this->assertSame([0.0], $refund->items->map(fn ($line) => round((float) $line->line_amount, 2))->unique()->values()->all());

        $refund->items()->where('order_item_id', $items->first()->id)->delete();
        $second = $service->reserveFailedDeliveryRefund($order, $leg);

        $this->assertSame('recovered', $second['result']);
        $this->assertSame(1, OrderRefund::where('order_id', $order->id)->count());
        $this->assertSame(2, $second['refund']->fresh('items')->items->count());
    }

    public function test_succeeded_refund_is_subtracted_from_failed_delivery_reservation(): void
    {
        [$order, $leg] = $this->paidOrderWithOutboundLeg();
        $this->refund($order, 'succeeded', 200, 'prior-success');

        $result = app(OrderRefundService::class)->reserveFailedDeliveryRefund($order, $leg);

        $this->assertSame('reserved', $result['result']);
        $this->assertSame(900.0, round((float) $result['refund']->amount, 2));
        $this->assertLessThanOrEqual(1100.0, (float) OrderRefund::where('order_id', $order->id)
            ->whereIn('status', ['requested', 'pending_approval', 'processing', 'succeeded'])
            ->sum('amount'));
    }

    public function test_every_active_refund_status_blocks_competing_failed_delivery_reservation(): void
    {
        foreach (['requested', 'pending_approval', 'processing'] as $status) {
            [$order, $leg] = $this->paidOrderWithOutboundLeg();
            $this->refund($order, $status, 100, "active-{$status}-{$order->id}");

            $result = app(OrderRefundService::class)->reserveFailedDeliveryRefund($order, $leg);

            $this->assertSame('collision', $result['result'], $status);
            $this->assertSame(1, OrderRefund::where('order_id', $order->id)->count(), $status);
        }
    }

    public function test_cancellation_refund_is_blocked_by_an_active_reservation(): void
    {
        [$order] = $this->paidOrderWithOutboundLeg();
        $this->refund($order, 'requested', 100, 'active-customer-request');

        $result = app(OrderRefundService::class)->autoRefundOnCancellation($order);

        $this->assertSame('collision', $result['result']);
        $this->assertSame(1, OrderRefund::where('order_id', $order->id)->count());
    }

    public function test_cancellation_refund_subtracts_succeeded_amount_and_never_exceeds_capture(): void
    {
        [$order] = $this->paidOrderWithOutboundLeg();
        $this->refund($order, 'succeeded', 200, 'prior-cancellation-success');
        $gateway = $this->mock(PaymongoRefundService::class);
        $gateway->shouldReceive('getPaymentAmountInCentavos')->once()->andReturn(110000);
        $gateway->shouldReceive('createRefund')
            ->once()
            ->withArgs(fn ($secret, $paymentId, $amount) => $amount === 90000)
            ->andReturn(['success' => true, 'status' => 'succeeded', 'refund_id' => 'refund_remaining']);

        $result = app(OrderRefundService::class)->autoRefundOnCancellation($order);

        $this->assertSame('refunded', $result['result']);
        $this->assertSame(900.0, round((float) $result['refund']->amount, 2));
        $this->assertSame(1100.0, round((float) OrderRefund::where('order_id', $order->id)->sum('amount'), 2));
    }

    public function test_maximum_attempt_bootstraps_paid_retail_refund_but_not_cod(): void
    {
        foreach (['paymongo_card' => 1, 'cod' => 0] as $paymentMethod => $expectedRefunds) {
            [$order, $leg] = $this->paidOrderWithOutboundLeg();
            $order->update(['payment_method' => $paymentMethod]);
            $leg->update(['status' => 'in_transit']);
            LogisticsSetting::updateOrCreate(
                ['shop_owner_id' => $order->shop_owner_id],
                ['max_delivery_attempts' => 1]
            );
            $rider = RiderProfile::factory()->create([
                'shop_owner_id' => $order->shop_owner_id,
                'active' => true,
                'availability_status' => 'available',
            ]);
            $assignment = DeliveryAssignment::factory()->create([
                'shipment_leg_id' => $leg->id,
                'rider_profile_id' => $rider->id,
                'status' => 'accepted',
            ]);

            app(ShipmentLegService::class)->recordFailedAttempt($leg, [
                'delivery_assignment_id' => $assignment->id,
                'reason_code' => 'recipient_unavailable',
            ]);

            $this->assertSame($expectedRefunds, OrderRefund::where('order_id', $order->id)->count(), $paymentMethod);
            $this->assertSame('needs_resolution', $leg->fresh()->status->value);
        }
    }

    public function test_failed_delivery_receipt_requires_completed_return_and_applies_every_line_once(): void
    {
        [$order, $leg, $items] = $this->paidOrderWithOutboundLeg();
        [$refund, $return] = $this->exhaustDelivery($order, $leg);
        $service = app(OrderRefundService::class);
        $dispositions = $items->values()->map(fn ($item, $index) => [
            'order_item_id' => $item->id,
            'approved_qty' => 1,
            'inspection_disposition' => $index === 0 ? 'resellable' : 'damaged',
        ])->all();

        $blocked = $service->confirmReturnReceived($refund, $order->customer_id, lineDispositions: $dispositions);
        $this->assertSame('invalid_state', $blocked['result']);

        $return->update(['status' => 'delivered', 'delivered_at' => now()]);
        HandoffProof::factory()->create([
            'shipment_leg_id' => $return->id,
            'handoff_type' => 'receive',
            'proof_type' => 'photo',
            'review_status' => 'approved',
        ]);
        $product = $items->first()->product;
        $variant = $items->first()->productVariant;
        $productStock = $product->stock_quantity;
        $variantStock = $variant->quantity;

        $received = $service->confirmReturnReceived($refund->fresh(), $order->customer_id, lineDispositions: $dispositions);

        $this->assertSame('received', $received['result']);
        $this->assertSame($productStock + 1, $product->fresh()->stock_quantity);
        $this->assertSame($variantStock + 1, $variant->fresh()->quantity);
        $this->assertDatabaseHas('order_refund_items', ['order_item_id' => $items[0]->id, 'inventory_action' => 'restock']);
        $this->assertDatabaseHas('order_refund_items', ['order_item_id' => $items[1]->id, 'inventory_action' => 'write_off']);

        $approved = $service->approveRequestedRefund($refund->fresh(), 'finance', $order->customer_id);
        $this->assertSame('approved', $approved['result']);
        $this->assertSame('approved', $approved['refund']->finance_status);
        $this->assertSame('approved', $approved['refund']->shop_owner_status);
        $this->assertSame('received', $approved['refund']->return_status);
        $this->assertStringContainsString('approval was bypassed', strtolower((string) $approved['refund']->reason_note));

        $service->confirmReturnReceived($refund->fresh(), $order->customer_id, lineDispositions: $dispositions);
        $this->assertSame($productStock + 1, $product->fresh()->stock_quantity);
        $this->assertSame($variantStock + 1, $variant->fresh()->quantity);
    }

    public function test_failed_delivery_receipt_rejects_missing_lines_and_finance_waits_for_receipt(): void
    {
        [$order, $leg, $items] = $this->paidOrderWithOutboundLeg();
        [$refund, $return] = $this->exhaustDelivery($order, $leg);
        $return->update(['status' => 'delivered', 'delivered_at' => now()]);
        HandoffProof::factory()->create([
            'shipment_leg_id' => $return->id,
            'handoff_type' => 'receive',
            'proof_type' => 'photo',
            'review_status' => 'approved',
        ]);
        $service = app(OrderRefundService::class);

        $missing = $service->confirmReturnReceived($refund, 77, lineDispositions: [[
            'order_item_id' => $items->first()->id,
            'approved_qty' => 1,
            'inspection_disposition' => 'resellable',
        ]]);

        $this->assertSame('invalid_state', $missing['result']);
        $this->assertNotSame('received', $refund->fresh()->return_status);
        $this->assertSame('invalid_state', $service->approveRequestedRefund($refund->fresh(), 'finance', 99)['result']);
        $refund->update(['finance_status' => 'approved', 'return_status' => 'in_transit']);
        $this->assertSame('invalid_state', $service->executeApprovedRefund($refund->fresh(), 99)['result']);
    }

    private function paidOrderWithOutboundLeg(): array
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'paymongo_secret_key' => 'sk_test_failed_delivery',
        ]);
        $customer = User::factory()->create();
        $product = Product::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Failed delivery shoe',
            'slug' => 'failed-delivery-shoe-' . $customer->id,
            'price' => 500,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'size' => '42',
            'color' => 'Black',
            'quantity' => 10,
            'is_active' => true,
        ]);
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'total_amount' => 1000,
            'shipping_fee' => 100,
            'payment_method' => 'paymongo_card',
            'payment_status' => 'paid',
            'paymongo_payment_id' => 'pay_failed_delivery_' . $customer->id,
        ]);
        $items = collect([
            OrderItem::create($this->item($order, $product, $variant, 'First item')),
            OrderItem::create($this->item($order, $product, $variant, 'Second item')),
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'leg_type' => 'outbound',
        ]);

        return [$order, $leg, $items];
    }

    private function item(Order $order, Product $product, ProductVariant $variant, string $name): array
    {
        return [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => $name,
            'product_slug' => $product->slug,
            'price' => 500,
            'quantity' => 1,
            'subtotal' => 500,
            'size' => '42',
            'color' => 'Black',
        ];
    }

    private function refund(Order $order, string $status, float $amount, string $key): OrderRefund
    {
        return OrderRefund::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'shop_owner_id' => $order->shop_owner_id,
            'flow_type' => 'request_approval',
            'status' => $status,
            'amount' => $amount,
            'idempotency_key' => $key,
        ]);
    }

    private function exhaustDelivery(Order $order, ShipmentLeg $leg): array
    {
        LogisticsSetting::updateOrCreate(['shop_owner_id' => $order->shop_owner_id], ['max_delivery_attempts' => 1]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $order->shop_owner_id,
            'active' => true,
            'availability_status' => 'available',
        ]);
        $assignment = DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'accepted',
        ]);
        $leg->update(['status' => 'in_transit']);
        app(ShipmentLegService::class)->recordFailedAttempt($leg->fresh(), [
            'delivery_assignment_id' => $assignment->id,
            'reason_code' => 'recipient_unavailable',
        ]);

        return [
            OrderRefund::where('order_id', $order->id)->firstOrFail(),
            ShipmentLeg::where('return_for_leg_id', $leg->id)->firstOrFail(),
        ];
    }
}
