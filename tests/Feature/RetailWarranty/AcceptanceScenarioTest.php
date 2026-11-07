<?php

namespace Tests\Feature\RetailWarranty;

use App\Jobs\DeliverRetailWarranty;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\OrderReceiptService;
use App\Services\OrderRefundService;
use App\Services\PaymongoRefundService;
use App\Services\RetailWarrantyCertificateService;
use App\Services\RetailWarrantyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

class AcceptanceScenarioTest extends WarrantyTestCase
{
    public function test_required_three_product_order_single_certificate_email_and_one_of_two_units_refund(): void
    {
        Storage::fake('local');
        Http::fake();
        $order = $this->purchase();
        $shop = $order->shopOwner;
        $shop->update(['registration_type' => 'individual', 'paymongo_secret_key' => 'sk_test_warranty_fixture']);
        $order->update(['paymongo_payment_id' => 'pay_warranty_fixture']);
        $service = app(RetailWarrantyService::class);
        $this->assertDatabaseCount('retail_warranty_issuances', 0);
        $this->assertDatabaseCount('retail_warranties', 0);
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
        app(OrderReceiptService::class)->confirm($order);
        $issuance = $order->fresh()->retailWarrantyIssuance->load('warranties');
        (new DeliverRetailWarranty($issuance->id))->handle(app(RetailWarrantyCertificateService::class));
        $this->assertDatabaseCount('retail_warranty_issuances', 1);
        $this->assertDatabaseCount('retail_warranties', 3);
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
        $bytes = Storage::disk('local')->get($issuance->fresh()->certificate_path);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $buyer = User::findOrFail($order->customer_id);
        $this->actingAs($buyer, 'user')->get('/my-orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('UserSide/Orders/MyOrders')->has('orders.0.product_warranty.items', 3));
        $service->saveSettings($shop, ['enabled' => true, 'duration_value' => 5, 'duration_unit' => 'days', 'terms' => 'New future policy']);
        $future = Order::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'delivered']);
        OrderItem::create(['order_id' => $future->id, 'product_id' => $order->items->first()->product_id,
            'product_name' => 'Future Shoe', 'product_slug' => 'future-shoe', 'price' => 250, 'quantity' => 1, 'subtotal' => 250]);
        $this->assertSame('days', $service->captureFulfillment($future)->warranties->first()->policy_snapshot['duration_unit']);
        $this->assertSame('years', $issuance->fresh()->warranties->first()->policy_snapshot['duration_unit']);
        $this->travel(4)->months();
        $a = $issuance->warranties->first();
        $result = app(OrderRefundService::class)->reserveOrderRefund($order, ['request_basis' => 'warranty', 'flow_type' => 'request_approval',
            'status' => 'requested', 'shop_owner_status' => 'pending', 'finance_status' => 'pending', 'return_status' => 'awaiting_approval',
            'payment_gateway' => 'paymongo', 'paymongo_payment_id' => 'pay_warranty_fixture', 'amount' => 250,
            'requested_at' => now(), 'idempotency_key' => 'required-warranty-scenario'],
            [['order_item_id' => $a->order_item_id, 'product_id' => $a->orderItem->product_id, 'retail_warranty_id' => $a->id,
                'requested_qty' => 1, 'approved_qty' => 1, 'unit_price_snapshot' => 250, 'line_amount' => 250, 'inspection_disposition' => 'pending']]);
        $refund = $result['refund'];
        $refunds = app(OrderRefundService::class);
        $this->assertSame('invalid_state', $refunds->executeApprovedRefund($refund)['result']);
        $finance = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'FINANCE']);
        $refunds->approveRequestedRefund($refund->fresh(), 'finance', $finance->id);
        $refunds->approveRequestedRefund($refund->fresh(), 'shop_owner', null);
        $refunds->approveRequestedRefund($refund->fresh(), 'finance', $finance->id);
        $this->assertSame('approved', $refund->fresh()->finance_status);
        $this->assertNotSame('succeeded', $refund->fresh()->status);
        $this->assertSame('invalid_state', $refunds->executeApprovedRefund($refund->fresh(), $finance->id)['result']);
        $refunds->markCustomerReturnShipped($refund->fresh(), ['tracking_number' => 'WARRANTY-RETURN', 'carrier_company' => 'J&T']);
        $received = $refunds->confirmReturnReceived($refund->fresh(), null, 'Shop inspected the returned pair.', [
            ['order_item_id' => $a->order_item_id, 'approved_qty' => 1, 'inspection_disposition' => 'damaged'],
        ]);
        $this->assertSame('received', $received['result']);
        $this->mock(PaymongoRefundService::class, function ($mock) {
            $mock->shouldReceive('getPaymentAmountInCentavos')->andReturn(100000);
            $mock->shouldReceive('createRefund')->once()->withArgs(fn ($key, $payment, $amount) => $amount === 25000)
                ->andReturn(['success' => true, 'status' => 'succeeded', 'refund_id' => 'rf_warranty_fixture']);
        });
        $result = app(OrderRefundService::class)->executeApprovedRefund($refund->fresh(), $finance->id);
        $this->assertSame('refunded', $result['result']);
        $this->assertSame(1, $a->fresh()->refunded_quantity);
        $projection = $service->projectOrders(new \Illuminate\Database\Eloquent\Collection([$order->fresh()]))[$order->id];
        $this->assertSame([1, 1, 1], array_column($projection['items'], 'remaining_quantity'));
        $this->assertSame('partially_used', $projection['status']);
        (new DeliverRetailWarranty($issuance->id))->handle(app(RetailWarrantyCertificateService::class));
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
        $this->assertSame($bytes, Storage::disk('local')->get($issuance->fresh()->certificate_path));
        $this->expectException(ValidationException::class);
        $service->validateAssessment($order, [['order_item_id' => $a->order_item_id, 'retail_warranty_id' => $a->id, 'requested_qty' => 2]]);
    }
}
