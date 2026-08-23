<?php

namespace Tests\Feature;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShippingMethod;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\OrderRefundItem;
use App\Models\DeliveryDispute;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\SourceShipmentService;
use App\Services\OrderRefundService;
use App\Services\PaymongoRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StaffOrderRefundPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

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
        $evidencePath = "delivery-dispute-evidence/order-{$order->id}/opening.jpg";
        Storage::disk('local')->put($evidencePath, 'customer-report-proof');
        $dispute = DeliveryDispute::create([
            'shop_owner_id' => $shop->id,
            'order_id' => $order->id,
            'order_refund_id' => $refund->id,
            'customer_id' => $order->customer_id,
            'status' => 'resolved',
            'reason' => 'damaged',
            'reported_at' => now(),
            'resolution' => 'refund_required',
            'evidence_media' => [[
                'id' => 'customer-proof-1',
                'path' => $evidencePath,
                'kind' => 'image',
                'mime_type' => 'image/jpeg',
                'original_name' => 'opening.jpg',
                'size' => 22,
            ]],
        ]);

        $shipment = app(SourceShipmentService::class)->ensureRefundReturnShipment($refund);
        $shipment->update(['status' => 'completed']);
        $leg = $shipment->legs()->firstOrFail();
        $method = ShippingMethod::factory()->create([
            'shop_owner_id' => $shop->id,
            'name' => 'Shop-owned logistics',
        ]);
        $rider = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'name' => 'Marco Santos',
            'phone' => '09171234567',
        ]);
        $leg->update([
            'status' => 'delivered',
            'shipping_method_id' => $method->id,
            'tracking_number' => 'RETURN-1001',
            'tracking_url' => 'https://example.test/returns/1001',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $rider->id,
            'status' => 'completed',
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
            $this->assertSame('customer-proof-1', $payload['customer_dispute_evidence'][0]['id']);
            $this->assertSame('image', $payload['customer_dispute_evidence'][0]['kind']);
            $this->assertSame(
                "/api/logistics/delivery-disputes/{$dispute->id}/evidence/customer-proof-1",
                parse_url($payload['customer_dispute_evidence'][0]['url'], PHP_URL_PATH),
            );
            $this->assertSame($shipment->id, $payload['return_logistics']['shipment_id']);
            $this->assertSame('return_to_shop', $payload['return_logistics']['leg_type']);
            $this->assertSame('Shop-owned logistics', $payload['return_logistics']['carrier']);
            $this->assertSame('Marco Santos', $payload['return_logistics']['rider_name']);
            $this->assertSame('09171234567', $payload['return_logistics']['rider_phone']);
            $this->assertSame("/api/logistics/proofs/{$proof->id}/file", $payload['return_logistics']['proofs'][0]['file_url']);
            $this->assertArrayNotHasKey('file_path', $payload['return_logistics']['proofs'][0]);
        }

        $this->actingAs($finance, 'user')
            ->getJson('/api/finance/refunds')
            ->assertOk()
            ->assertJsonPath('data.0.payoutAmountValue', 2499)
            ->assertJsonPath('data.0.shippingFee', 108);
    }

    public function test_staff_list_and_show_include_order_logistics(): void
    {
        [$shop, $staff, , $order] = $this->refundFixture();
        $shipment = app(SourceShipmentService::class)->ensureRetailOrderShipment($order);
        $shipment->update(['status' => 'active']);
        $leg = $shipment->legs()->firstOrFail();
        $leg->update([
            'status' => 'in_transit',
            'tracking_number' => 'DELIVERY-1001',
        ]);

        $list = $this->actingAs($staff, 'user')->getJson('/api/staff/orders')->assertOk();
        $show = $this->actingAs($staff, 'user')->getJson("/api/staff/orders/{$order->id}")->assertOk();

        foreach ([$list->json('0.logistics'), $show->json('logistics')] as $payload) {
            $this->assertSame($shipment->id, $payload['shipment_id']);
            $this->assertSame('active', $payload['shipment_status']);
            $this->assertSame('outbound', $payload['leg_type']);
            $this->assertSame('in_transit', $payload['leg_status']);
            $this->assertSame('DELIVERY-1001', $payload['tracking_number']);
        }
    }

    public function test_shipment_without_leg_keeps_summary_fields_nullable(): void
    {
        [$shop, $staff, , $order] = $this->refundFixture();
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
            'status' => 'requested',
        ]);

        $payload = $this->actingAs($staff, 'user')
            ->getJson("/api/staff/orders/{$order->id}")
            ->assertOk()
            ->json('logistics');

        $this->assertSame($shipment->id, $payload['shipment_id']);
        $this->assertNull($payload['leg_id']);
        $this->assertNull($payload['leg_status']);
        $this->assertSame([], $payload['proofs']);
    }

    public function test_non_delivered_return_leg_does_not_expose_proof(): void
    {
        [, $staff, , $order, $refund] = $this->refundFixture();
        $shipment = app(SourceShipmentService::class)->ensureRefundReturnShipment($refund);
        $leg = $shipment->legs()->firstOrFail();
        $leg->update(['status' => 'in_transit']);
        HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'file_path' => 'logistics-proof/not-delivered.jpg',
        ]);

        $this->actingAs($staff, 'user')
            ->getJson("/api/staff/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('latest_refund.return_logistics.proofs', []);
    }

    public function test_staff_payload_ignores_another_shops_shipment_for_same_order_id(): void
    {
        [, $staff, , $order] = $this->refundFixture();
        $otherShop = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        Shipment::factory()->create([
            'shop_owner_id' => $otherShop->id,
            'source_type' => 'order',
            'source_id' => $order->id,
            'purpose' => 'retail_delivery',
        ]);

        $this->actingAs($staff, 'user')
            ->getJson("/api/staff/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('logistics', null);
    }

    public function test_legacy_normal_refund_execution_excludes_original_shipping_fee(): void
    {
        [, , $finance, $order, $refund] = $this->refundFixture();
        $gateway = $this->mock(PaymongoRefundService::class);
        $gateway->shouldReceive('createRefund')
            ->once()
            ->withArgs(fn ($key, $paymentId, $amount) => $amount === 249900)
            ->andReturn(['success' => true, 'status' => 'succeeded', 'refund_id' => 'refund-normal']);

        $result = app(OrderRefundService::class)->executeApprovedRefund($refund->fresh());

        $this->assertSame('refunded', $result['result']);
        $this->assertSame(2499.0, round((float) $refund->fresh()->amount, 2));
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->actingAs($finance, 'user')
            ->getJson('/api/finance/refunds')
            ->assertOk()
            ->assertJsonPath('data.0.payoutAmountValue', 2499);
    }

    public function test_zero_amount_legacy_refund_fails_instead_of_refunding_the_order_total(): void
    {
        [, , , , $refund] = $this->refundFixture();
        $refund->update(['amount' => 0]);
        $gateway = $this->mock(PaymongoRefundService::class);
        $gateway->shouldNotReceive('createRefund');

        $result = app(OrderRefundService::class)->executeApprovedRefund($refund->fresh());

        $this->assertSame('failed', $result['result']);
        $this->assertSame(0.0, round((float) $refund->fresh()->amount, 2));
        $this->assertSame('Refund amount is invalid.', $refund->fresh()->failure_reason);
    }

    public function test_same_day_partial_rejection_does_not_retry_with_shipping_inclusive_capture(): void
    {
        [, , $finance, , $refund] = $this->refundFixture();
        $gateway = $this->mock(PaymongoRefundService::class);
        $gateway->shouldReceive('createRefund')
            ->once()
            ->withArgs(fn ($key, $paymentId, $amount) => $amount === 249900)
            ->andReturn([
                'success' => false,
                'message' => 'Cannot partially refund this payment on the same day.',
            ]);
        $gateway->shouldNotReceive('getPaymentAmountInCentavos');

        $result = app(OrderRefundService::class)->executeApprovedRefund($refund->fresh());

        $this->assertSame('failed', $result['result']);
        $this->assertSame(2499.0, round((float) $refund->fresh()->amount, 2));
        $this->actingAs($finance, 'user')
            ->getJson('/api/finance/refunds')
            ->assertOk()
            ->assertJsonPath('data.0.payoutAmountValue', 2499);
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
