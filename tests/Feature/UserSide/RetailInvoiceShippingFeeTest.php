<?php

namespace Tests\Feature\UserSide;

use App\Http\Controllers\UserSide\CheckoutController;
use App\Models\Finance\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class RetailInvoiceShippingFeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_generated_invoice_includes_vat_and_shipping_as_separate_amounts(): void
    {
        $order = Order::factory()->create([
            'total_amount' => 100,
            'shipping_fee' => 20,
            'vat_amount' => 12,
            'vat_rate' => 12,
            'payment_method' => 'paymongo',
            'payment_status' => 'paid',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_name' => 'Test Runner',
            'product_slug' => 'test-runner',
            'price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
        ]);
        $order->load('items');

        $method = new ReflectionMethod(CheckoutController::class, 'autoGenerateInvoice');
        $method->setAccessible(true);
        $method->invoke(app(CheckoutController::class), $order);

        $invoice = Invoice::query()->where('job_order_id', $order->id)->firstOrFail();

        $this->assertSame('132.00', $invoice->total);
        $this->assertSame('12.00', $invoice->tax_amount);
        $this->assertSame(20.0, (float) data_get($invoice->meta, 'shipping_fee'));
        $this->assertSame(132.0, (float) data_get($invoice->meta, 'grand_total'));
        $this->assertDatabaseHas('finance_invoice_items', [
            'invoice_id' => $invoice->id,
            'description' => 'Shipping Fee',
            'amount' => 20,
        ]);
    }
}
