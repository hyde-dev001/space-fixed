<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Invoice;
use App\Models\Finance\InvoicePayment;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-finance-invoices', 'user');
    }

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

    public function test_payment_endpoint_replays_exact_request_and_rejects_conflicting_key(): void
    {
        [$shop, $invoice, $user] = $this->makeInvoiceContext();
        $user->givePermissionTo('access-finance-invoices');

        $payload = [
            'amount' => '30.00',
            'payment_method' => 'cash',
            'received_at' => '2026-08-11 10:00:00',
            'reference' => 'CASH-30',
            'idempotency_key' => 'invoice-api-replay-1',
        ];

        $first = $this->actingAs($user, 'user')->postJson("/api/finance/invoices/{$invoice->id}/payments", $payload);
        $first->assertCreated()->assertJsonPath('replayed', false)->assertJsonPath('payment.amount', '30.00');

        $second = $this->actingAs($user, 'user')->postJson("/api/finance/invoices/{$invoice->id}/payments", $payload);
        $second->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('invoice.paid_amount', '30.00');

        $conflict = $this->actingAs($user, 'user')->postJson("/api/finance/invoices/{$invoice->id}/payments", [
            ...$payload,
            'amount' => '31.00',
        ]);
        $conflict->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_SUBMISSION');

        $this->assertDatabaseCount('finance_invoice_payments', 1);
    }

    public function test_payment_endpoint_rejects_excess_and_linked_operational_invoice(): void
    {
        [$shop, $invoice, $user] = $this->makeInvoiceContext();
        $user->givePermissionTo('access-finance-invoices');

        $response = $this->actingAs($user, 'user')->postJson("/api/finance/invoices/{$invoice->id}/payments", [
            'amount' => '100.01',
            'payment_method' => 'cash',
            'received_at' => now()->toDateTimeString(),
            'idempotency_key' => 'invoice-excess-1',
        ]);
        $response->assertStatus(422)->assertJsonPath('code', 'AMOUNT_EXCEEDS_BALANCE');

        $order = Order::factory()->create(['shop_owner_id' => $shop->id]);
        $invoice->update(['job_order_id' => $order->id]);
        $linked = $this->actingAs($user, 'user')->postJson("/api/finance/invoices/{$invoice->id}/payments", [
            'amount' => '10.00',
            'payment_method' => 'cash',
            'received_at' => now()->toDateTimeString(),
            'idempotency_key' => 'invoice-linked-1',
        ]);
        $linked->assertStatus(422)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_payment_can_be_fully_reversed_once_and_mark_paid_is_retired(): void
    {
        [$shop, $invoice, $user] = $this->makeInvoiceContext();
        $user->givePermissionTo('access-finance-invoices');
        $base = [
            'amount' => '100.00',
            'payment_method' => 'bank_transfer',
            'received_at' => '2026-08-11 11:00:00',
            'reference' => 'BANK-100',
            'idempotency_key' => 'invoice-reverse-1',
        ];
        $created = $this->actingAs($user, 'user')->postJson("/api/finance/invoices/{$invoice->id}/payments", $base);
        $created->assertCreated();
        $paymentId = (int) $created->json('payment.id');

        $reversed = $this->actingAs($user, 'user')->postJson("/api/finance/invoices/{$invoice->id}/payments/{$paymentId}/reverse", [
            'reason' => 'Bank transfer returned',
        ]);
        $reversed->assertCreated()->assertJsonPath('invoice.paid_amount', '0.00');

        $duplicate = $this->actingAs($user, 'user')->postJson("/api/finance/invoices/{$invoice->id}/payments/{$paymentId}/reverse", [
            'reason' => 'Second attempt',
        ]);
        $duplicate->assertStatus(409)->assertJsonPath('code', 'ALREADY_REVERSED');

        $legacy = $this->actingAs($user, 'user')->postJson("/api/finance/invoices/{$invoice->id}/mark-paid", [
            'payment_date' => '2026-08-11',
            'payment_method' => 'cash',
        ]);
        $legacy->assertStatus(410)->assertJsonPath('code', 'PAYMENT_ROUTE_MOVED');
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
