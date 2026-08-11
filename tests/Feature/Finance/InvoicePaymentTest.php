<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Invoice;
use App\Models\Finance\InvoicePayment;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_schema_has_tenant_history_and_reversal_constraints(): void
    {
        $this->assertTrue(Schema::hasColumns('finance_invoice_payments', [
            'shop_owner_id', 'invoice_id', 'entry_type', 'amount', 'payment_method',
            'reference', 'received_at', 'recorded_by_user_id', 'idempotency_key',
            'reverses_payment_id', 'reversal_reason', 'source',
        ]));

        $indexes = collect(Schema::getIndexes('finance_invoice_payments'));
        $this->assertTrue($indexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['shop_owner_id', 'idempotency_key']));
        $this->assertTrue($indexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['reverses_payment_id']));
        $this->assertTrue($indexes->contains(fn (array $index): bool => $index['columns'] === ['shop_owner_id', 'received_at']));
        $this->assertTrue($indexes->contains(fn (array $index): bool => $index['columns'] === ['invoice_id', 'entry_type']));
    }

    public function test_payment_model_casts_amount_and_blocks_mutation_after_creation(): void
    {
        [$shop, $invoice, $user] = $this->makeInvoiceContext();

        $payment = InvoicePayment::create([
            'shop_owner_id' => $shop->id,
            'invoice_id' => $invoice->id,
            'entry_type' => InvoicePayment::ENTRY_PAYMENT,
            'amount' => '12.30',
            'payment_method' => 'cash',
            'received_at' => now(),
            'recorded_by_user_id' => $user->id,
            'idempotency_key' => 'invoice-test-1',
            'source' => InvoicePayment::SOURCE_MANUAL,
        ]);

        $this->assertSame('12.30', $payment->amount);
        $this->assertTrue($payment->invoice->is($invoice));
        $this->assertTrue($payment->shopOwner->is($shop));
        $this->assertTrue($payment->recordedBy->is($user));
        $this->assertSame('12.30', $invoice->validPaidAmount());

        $this->expectException(\LogicException::class);
        $payment->update(['reference' => 'cannot-edit-history']);
    }

    public function test_reversal_is_subtracted_and_duplicate_request_key_is_unique_per_shop(): void
    {
        [$shop, $invoice, $user] = $this->makeInvoiceContext();

        $payment = InvoicePayment::create([
            'shop_owner_id' => $shop->id,
            'invoice_id' => $invoice->id,
            'entry_type' => InvoicePayment::ENTRY_PAYMENT,
            'amount' => '20.00',
            'payment_method' => 'bank_transfer',
            'received_at' => now(),
            'recorded_by_user_id' => $user->id,
            'idempotency_key' => 'invoice-test-unique',
            'source' => InvoicePayment::SOURCE_MANUAL,
        ]);

        InvoicePayment::create([
            'shop_owner_id' => $shop->id,
            'invoice_id' => $invoice->id,
            'entry_type' => InvoicePayment::ENTRY_REVERSAL,
            'amount' => '20.00',
            'payment_method' => 'bank_transfer',
            'received_at' => now(),
            'recorded_by_user_id' => $user->id,
            'reverses_payment_id' => $payment->id,
            'reversal_reason' => 'Correction',
            'source' => InvoicePayment::SOURCE_MANUAL,
        ]);

        $this->assertSame('0.00', $invoice->validPaidAmount());

        $this->expectException(QueryException::class);
        InvoicePayment::create([
            'shop_owner_id' => $shop->id,
            'invoice_id' => $invoice->id,
            'entry_type' => InvoicePayment::ENTRY_PAYMENT,
            'amount' => '1.00',
            'payment_method' => 'cash',
            'received_at' => now(),
            'recorded_by_user_id' => $user->id,
            'idempotency_key' => 'invoice-test-unique',
            'source' => InvoicePayment::SOURCE_MANUAL,
        ]);
    }

    private function makeInvoiceContext(): array
    {
        $shop = ShopOwner::factory()->create();
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $invoice = Invoice::create([
            'reference' => 'INV-' . uniqid(),
            'customer_name' => 'Test Customer',
            'date' => now()->toDateString(),
            'total' => '100.00',
            'tax_amount' => '0.00',
            'status' => 'sent',
            'shop_id' => $shop->id,
        ]);

        return [$shop, $invoice, $user];
    }
}
