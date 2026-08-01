<?php

namespace Tests\Feature;

use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\OrderRefundItem;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OrderRefundService;
use App\Services\PaymongoRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StaffOrderRefundPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_list_show_and_finance_share_refund_payout_evidence_and_return_logistics(): void
    {
        [$shop, $staff, $finance, $order, $refund] = $this->refundFixture();

        $product = Product::create([
            'shop_owner_id' => $shop->id,
            'name' => 'Refund Payload Shoe',
            'slug' => 'refund-payload-shoe',
            'price' => 2231.25,
            'stock_quantity' => 1,
            'is_active' => true,
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'price' => 2231.25,
            'quantity' => 1,
            'subtotal' => 2231.25,
            'size' => '42',
            'color' => 'Black',
        ]);
        OrderRefundItem::create([
            'order_refund_id' => $refund->id,
            'order_item_id' => $item->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'requested_qty' => 1,
            'approved_qty' => 1,
            'unit_price_snapshot' => 2499,
            'line_amount' => 2499,
            'inspection_disposition' => 'resellable',
            'inventory_action' => 'pending',
        ]);

        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order_refund',
            'source_id' => $refund->id,
            'purpose' => 'refund_return',
            'status' => 'completed',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'leg_type' => 'return_to_shop',
            'status' => 'delivered',
            'tracking_number' => 'RETURN-1001',
            'tracking_url' => 'https://example.test/returns/1001',
        ]);
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'receive',
            'proof_type' => 'photo',
            'file_path' => 'logistics-proof/private-return.jpg',
            'review_status' => 'approved',
        ]);

        $list = $this->actingAs($staff, 'user')->getJson('/api/staff/orders')->assertOk();
        $show = $this->actingAs($staff, 'user')->getJson("/api/staff/orders/{$order->id}")->assertOk();

        foreach ([$list->json('0.latest_refund'), $show->json('latest_refund')] as $payload) {
            $this->assertSame(2499, $payload['payout_amount_value']);
            $this->assertSame(['/storage/refunds/customer-evidence.jpg'], $payload['evidence_media']);
            $this->assertSame($shipment->id, $payload['return_logistics']['shipment_id']);
            $this->assertSame('return_to_shop', $payload['return_logistics']['leg_type']);
            $this->assertSame("/api/logistics/proofs/{$proof->id}/file", $payload['return_logistics']['proofs'][0]['file_url']);
            $this->assertArrayNotHasKey('file_path', $payload['return_logistics']['proofs'][0]);
        }

        $this->actingAs($finance, 'user')
            ->getJson('/api/finance/refunds')
            ->assertOk()
            ->assertJsonPath('data.0.payoutAmountValue', 2499)
            ->assertJsonPath('data.0.shippingFee', 108);
    }

    public function test_legacy_normal_refund_execution_excludes_original_shipping_fee(): void
    {
        [, , , $order, $refund] = $this->refundFixture();
        $gateway = $this->mock(PaymongoRefundService::class);
        $gateway->shouldReceive('createRefund')
            ->once()
            ->withArgs(fn ($key, $paymentId, $amount) => $amount === 249900)
            ->andReturn(['success' => true, 'status' => 'succeeded', 'refund_id' => 'refund-normal']);

        $result = app(OrderRefundService::class)->executeApprovedRefund($refund->fresh());

        $this->assertSame('refunded', $result['result']);
        $this->assertSame(2499.0, round((float) $refund->fresh()->amount, 2));
        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    private function refundFixture(): array
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'retail',
            'paymongo_secret_key' => 'sk_test_refund_payload',
        ]);
        $customer = User::factory()->create();
        $staff = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        $finance = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'Finance']);
        Permission::findOrCreate('access-staff-job-orders', 'user');
        Permission::findOrCreate('access-refund-approval', 'user');
        $staff->givePermissionTo('access-staff-job-orders');
        $finance->givePermissionTo('access-refund-approval');

        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'total_amount' => 2231.25,
            'vat_amount' => 267.75,
            'shipping_fee' => 108,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'payment_method' => 'paymongo_card',
            'paymongo_payment_id' => 'pay_refund_payload',
        ]);
        $refund = OrderRefund::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $shop->id,
            'status' => 'approved',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'received',
            'amount' => 2607,
            'reason_code' => 'product_defective_or_damaged',
            'evidence_media' => ['/storage/refunds/customer-evidence.jpg'],
        ]);

        return [$shop, $staff, $finance, $order, $refund];
    }
}
