<?php

namespace Tests\Feature\Finance;

use App\Models\Employee;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoicePayment;
use App\Models\HR\Payroll;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceipt;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_is_conservative_and_idempotent(): void
    {
        [$shop, $user] = $this->shopContext();

        $standalone = Invoice::create([
            'reference' => 'INV-BACKFILL-STANDALONE',
            'customer_name' => 'Legacy Customer',
            'date' => '2026-08-01',
            'total' => '110.00',
            'tax_amount' => '10.00',
            'status' => 'paid',
            'payment_method' => null,
            'payment_date' => null,
            'shop_id' => $shop->id,
        ]);

        $operationalOrder = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'payment_status' => 'paid',
            'paid_at' => '2026-08-02 09:00:00',
        ]);
        $operationalInvoice = Invoice::create([
            'reference' => 'INV-BACKFILL-OPERATIONAL',
            'job_order_id' => $operationalOrder->id,
            'customer_name' => 'Operational Customer',
            'date' => '2026-08-02',
            'total' => '220.00',
            'tax_amount' => '20.00',
            'status' => 'paid',
            'payment_date' => '2026-08-02',
            'payment_method' => 'paymongo',
            'shop_id' => $shop->id,
        ]);

        $orphanOrder = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);
        $orphanInvoice = Invoice::create([
            'reference' => 'INV-BACKFILL-ORPHAN',
            'job_order_id' => $orphanOrder->id,
            'customer_name' => 'Orphan Customer',
            'date' => '2026-08-03',
            'total' => '330.00',
            'tax_amount' => '30.00',
            'status' => 'paid',
            'payment_date' => '2026-08-03',
            'payment_method' => 'cash',
            'shop_id' => $shop->id,
        ]);

        $employee = Employee::factory()->create(['shop_owner_id' => $shop->id]);
        $payroll = Payroll::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shop->id,
            'payroll_period' => '2026-08',
            'pay_period_start' => '2026-08-01',
            'pay_period_end' => '2026-08-31',
            'basic_salary' => '500.00',
            'base_salary' => '500.00',
            'gross_salary' => '500.00',
            'net_salary' => '500.00',
            'status' => 'paid',
            'payment_date' => '2026-08-04',
            'payment_method' => 'bank_transfer',
        ]);
        Payroll::create([
            'employee_id' => Employee::factory()->create(['shop_owner_id' => $shop->id])->id,
            'shop_owner_id' => $shop->id,
            'payroll_period' => '2026-09',
            'pay_period_start' => '2026-09-01',
            'pay_period_end' => '2026-09-30',
            'basic_salary' => '500.00',
            'base_salary' => '500.00',
            'gross_salary' => '500.00',
            'net_salary' => '500.00',
            'status' => 'approved',
        ]);

        $supplier = Supplier::factory()->create();
        $purchaseOrder = PurchaseOrder::factory()->create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'ordered_by' => $user->id,
            'payment_terms' => 'Net 30',
        ]);
        $receipt = PurchaseOrderReceipt::create([
            'purchase_order_id' => $purchaseOrder->id,
            'shop_owner_id' => $shop->id,
            'source' => 'manual',
            'status' => 'posted',
            'idempotency_key' => 'backfill-receipt-1',
            'received_at' => '2026-08-05 10:00:00',
        ]);
        $procurementExpense = Expense::create([
            'reference' => 'EXP-BACKFILL-PROCUREMENT',
            'date' => '2026-08-05',
            'category' => 'Procurement',
            'amount' => '100.00',
            'tax_amount' => '0.00',
            'status' => 'approved',
            'shop_id' => $shop->id,
            'procurement_receipt_id' => $receipt->id,
        ]);
        Expense::create([
            'reference' => 'EXP-BACKFILL-MANUAL',
            'date' => '2026-08-05',
            'category' => 'Office',
            'amount' => '40.00',
            'tax_amount' => '0.00',
            'status' => 'approved',
            'shop_id' => $shop->id,
        ]);

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('finance:backfill-money-history', ['--chunk' => 2]));

        $this->assertDatabaseCount('finance_invoice_payments', 2);
        $this->assertDatabaseHas('finance_invoice_payments', [
            'invoice_id' => $standalone->id,
            'payment_method' => 'legacy_unknown',
            'source' => InvoicePayment::SOURCE_LEGACY_MIGRATION,
        ]);
        $this->assertDatabaseHas('finance_invoice_payments', [
            'invoice_id' => $orphanInvoice->id,
            'source' => InvoicePayment::SOURCE_LEGACY_MIGRATION,
        ]);
        $this->assertDatabaseMissing('finance_invoice_payments', ['invoice_id' => $operationalInvoice->id]);
        $this->assertDatabaseCount('finance_expense_settlements', 1);
        $this->assertDatabaseHas('finance_expense_settlements', [
            'source' => ExpenseSettlement::SOURCE_PAYROLL,
            'source_reference' => 'payroll:'.$payroll->id,
        ]);
        $this->assertSame('2026-09-04', $procurementExpense->fresh()->due_date?->toDateString());
        $this->assertDatabaseMissing('finance_expense_settlements', ['expense_id' => Expense::where('reference', 'EXP-BACKFILL-MANUAL')->value('id')]);

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('finance:backfill-money-history', ['--chunk' => 2]));
        $this->assertDatabaseCount('finance_invoice_payments', 2);
        $this->assertDatabaseCount('finance_expense_settlements', 1);
    }

    public function test_dry_run_does_not_write_history(): void
    {
        [$shop] = $this->shopContext();
        $invoice = Invoice::create([
            'reference' => 'INV-BACKFILL-DRY',
            'customer_name' => 'Dry Run',
            'date' => '2026-08-01',
            'total' => '50.00',
            'tax_amount' => '0.00',
            'status' => 'paid',
            'payment_date' => '2026-08-01',
            'shop_id' => $shop->id,
        ]);

        $this->artisan('finance:backfill-money-history', ['--dry-run' => true])
            ->expectsOutputToContain('dry_run=yes invoice_payments=1')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('finance_invoice_payments', ['invoice_id' => $invoice->id]);
    }

    public function test_integrity_audit_reports_excess_refunds_and_missing_receipts_without_mutation(): void
    {
        [$shop] = $this->shopContext();
        $invoice = Invoice::create([
            'reference' => 'INV-BACKFILL-OVERPAY',
            'customer_name' => 'Overpaid',
            'date' => '2026-08-01',
            'total' => '10.00',
            'tax_amount' => '0.00',
            'status' => 'paid',
            'payment_date' => '2026-08-01',
            'shop_id' => $shop->id,
        ]);
        InvoicePayment::create([
            'shop_owner_id' => $shop->id,
            'invoice_id' => $invoice->id,
            'entry_type' => InvoicePayment::ENTRY_PAYMENT,
            'amount' => '20.00',
            'payment_method' => 'cash',
            'received_at' => '2026-08-01',
            'idempotency_key' => 'backfill-overpay',
            'source' => InvoicePayment::SOURCE_MANUAL,
        ]);
        $expense = Expense::create([
            'reference' => 'EXP-MISSING-RECEIPT',
            'date' => '2026-08-01',
            'category' => 'Office',
            'amount' => '10.00',
            'tax_amount' => '0.00',
            'status' => 'approved',
            'receipt_path' => 'missing/backfill.pdf',
            'shop_id' => $shop->id,
        ]);

        $this->artisan('finance:audit-integrity', ['--section' => 'historical-excess'])
            ->expectsOutput('section=historical-excess count=1')
            ->expectsOutput('entity=invoice id='.$invoice->id)
            ->assertExitCode(1);
        $this->artisan('finance:audit-integrity', ['--section' => 'missing-receipts'])
            ->expectsOutput('section=missing-receipts count=1')
            ->expectsOutput('expense_id='.$expense->id)
            ->assertExitCode(1);
    }

    public function test_ambiguous_refund_audit_is_visible(): void
    {
        if (! Schema::hasTable('order_refunds')) {
            $this->markTestSkipped('Order refund table is not available.');
        }

        [$shop, $user] = $this->shopContext();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'shipping_fee' => '25.00',
            'total_amount' => '100.00',
        ]);
        DB::table('order_refunds')->insert([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'shop_owner_id' => $shop->id,
            'flow_type' => 'cancel_auto',
            'status' => 'succeeded',
            'payment_gateway' => 'paymongo',
            'amount' => '20.00',
            'currency' => 'PHP',
            'idempotency_key' => 'ambiguous-refund-1',
            'refunded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('finance:audit-integrity', ['--section' => 'ambiguous-refunds'])
            ->expectsOutput('section=ambiguous-refunds count=1')
            ->assertExitCode(1);
    }

    /** @return array{ShopOwner,User} */
    private function shopContext(): array
    {
        $shop = ShopOwner::factory()->create();
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);

        return [$shop, $user];
    }
}
