<?php

namespace App\Console\Commands;

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Models\Finance\InvoicePayment;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BackfillFinanceMoneyHistory extends Command
{
    protected $signature = 'finance:backfill-money-history
        {--dry-run : Report planned rows without writing}
        {--chunk=100 : Number of source rows to process per chunk}';

    protected $description = 'Backfill Finance money history from authoritative legacy records without guessing';

    /** @var array<string,int> */
    private array $stats = [
        'invoice_payments' => 0,
        'payroll_settlements' => 0,
        'procurement_due_dates' => 0,
        'warnings' => 0,
    ];

    /** @var array<int,string> */
    private array $warnings = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, min(1000, (int) $this->option('chunk')));

        $this->backfillInvoices($chunk, $dryRun);
        $this->backfillPayrolls($chunk, $dryRun);
        $this->backfillProcurementDueDates($chunk, $dryRun);

        $this->line(sprintf(
            'dry_run=%s invoice_payments=%d payroll_settlements=%d procurement_due_dates=%d warnings=%d',
            $dryRun ? 'yes' : 'no',
            $this->stats['invoice_payments'],
            $this->stats['payroll_settlements'],
            $this->stats['procurement_due_dates'],
            $this->stats['warnings'],
        ));

        foreach ($this->warnings as $warning) {
            $this->line($warning);
        }

        return self::SUCCESS;
    }

    private function backfillInvoices(int $chunk, bool $dryRun): void
    {
        if (! Schema::hasTable('finance_invoices') || ! Schema::hasTable('finance_invoice_payments')) {
            return;
        }

        DB::table('finance_invoices')
            ->where('status', 'paid')
            ->orderBy('id')
            ->chunkById($chunk, function ($invoices) use ($dryRun): void {
                foreach ($invoices as $invoice) {
                    $this->backfillInvoice($invoice, $dryRun);
                }
            });
    }

    private function backfillInvoice(object $invoice, bool $dryRun): void
    {
        $shopId = (int) ($invoice->shop_id ?? 0);
        if ($shopId <= 0) {
            $this->warnFor('legacy_invoice_missing_shop', 'invoice', (int) $invoice->id);

            return;
        }

        $sourceReference = 'invoice:'.(int) $invoice->id.':legacy-payment';
        $idempotencyKey = 'legacy:invoice:'.(int) $invoice->id.':payment';
        $alreadyBackfilled = DB::table('finance_invoice_payments')
            ->where('shop_owner_id', $shopId)
            ->where('invoice_id', $invoice->id)
            ->where('source', InvoicePayment::SOURCE_LEGACY_MIGRATION)
            ->where('idempotency_key', $idempotencyKey)
            ->exists();
        if ($alreadyBackfilled) {
            return;
        }

        $amount = $this->decimal((string) ($invoice->total ?? '0'));
        if ($this->cents($amount) <= 0) {
            $this->warnFor('legacy_invoice_invalid_amount', 'invoice', (int) $invoice->id);

            return;
        }

        $linkedOrder = null;
        if ($invoice->job_order_id !== null && Schema::hasTable('orders')) {
            $linkedOrder = DB::table('orders')
                ->where('id', $invoice->job_order_id)
                ->first(['id', 'payment_status', 'paid_at']);
        }

        if ($invoice->job_order_id !== null
            && $linkedOrder
            && in_array((string) $linkedOrder->payment_status, ['paid', 'refunded'], true)
            && $linkedOrder->paid_at !== null) {
            // The operational order is the authoritative source. Do not
            // create a second Finance payment for the same customer cash.
            return;
        }

        if ($invoice->job_order_id !== null) {
            $this->warnFor('legacy_source_missing', 'invoice', (int) $invoice->id);
        }

        $receivedAt = $invoice->payment_date ?: $invoice->updated_at ?: $invoice->date;
        if (! $invoice->payment_date) {
            $this->warnFor('legacy_payment_date_fallback', 'invoice', (int) $invoice->id);
        }

        $payload = [
            'shop_owner_id' => $shopId,
            'invoice_id' => $invoice->id,
            'entry_type' => InvoicePayment::ENTRY_PAYMENT,
            'amount' => $amount,
            'payment_method' => $this->legacyMethod($invoice->payment_method ?? null),
            'reference' => 'LEGACY-INVOICE-'.(int) $invoice->id,
            'received_at' => CarbonImmutable::parse($receivedAt)->toDateTimeString(),
            'recorded_by_user_id' => null,
            'idempotency_key' => $idempotencyKey,
            'source' => InvoicePayment::SOURCE_LEGACY_MIGRATION,
        ];

        if (! $dryRun) {
            DB::table('finance_invoice_payments')->insert([
                ...$payload,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->stats['invoice_payments']++;
    }

    private function backfillPayrolls(int $chunk, bool $dryRun): void
    {
        if (! Schema::hasTable('payrolls') || ! Schema::hasTable('finance_expenses') || ! Schema::hasTable('finance_expense_settlements')) {
            return;
        }

        DB::table('payrolls')
            ->where('status', 'paid')
            ->orderBy('id')
            ->chunkById($chunk, function ($payrolls) use ($dryRun): void {
                foreach ($payrolls as $payroll) {
                    $this->backfillPayroll($payroll, $dryRun);
                }
            });
    }

    private function backfillPayroll(object $payroll, bool $dryRun): void
    {
        $shopId = (int) ($payroll->shop_owner_id ?? 0);
        $amount = $this->decimal((string) ($payroll->net_salary ?? '0'));
        if ($shopId <= 0 || $this->cents($amount) <= 0) {
            $this->warnFor('invalid_paid_payroll', 'payroll', (int) $payroll->id);

            return;
        }

        $sourceReference = 'payroll:'.(int) $payroll->id;
        $exists = DB::table('finance_expense_settlements')
            ->where('source', ExpenseSettlement::SOURCE_PAYROLL)
            ->where('source_reference', $sourceReference)
            ->exists();
        if ($exists) {
            return;
        }

        $reference = 'PAY-EXP-'.(int) $payroll->id;
        $expense = Expense::query()->where('reference', $reference)->first();
        if ($expense && $this->cents((string) $expense->amount) !== $this->cents($amount)) {
            $this->warnFor('payroll_expense_amount_mismatch', 'payroll', (int) $payroll->id);

            return;
        }

        $paymentDate = $payroll->payment_date ?: $payroll->updated_at;
        if (! $payroll->payment_date) {
            $this->warnFor('legacy_payroll_date_fallback', 'payroll', (int) $payroll->id);
        }

        if (! $dryRun) {
            DB::transaction(function () use ($payroll, $shopId, $amount, $reference, $paymentDate, $sourceReference): void {
                $expense = Expense::query()->firstOrCreate(
                    ['reference' => $reference],
                    [
                        'date' => CarbonImmutable::parse($paymentDate)->toDateString(),
                        'category' => 'Payroll',
                        'vendor' => 'Payroll #'.(int) $payroll->id,
                        'description' => 'Historical payroll disbursement '.(int) $payroll->id,
                        'amount' => $amount,
                        'tax_amount' => '0.00',
                        'status' => 'approved',
                        'shop_id' => $shopId,
                        'meta' => [
                            'source' => 'payroll',
                            'payroll_id' => (int) $payroll->id,
                            'legacy_migration' => true,
                        ],
                    ],
                );

                DB::table('finance_expense_settlements')->insert([
                    'shop_owner_id' => $shopId,
                    'expense_id' => $expense->id,
                    'entry_type' => ExpenseSettlement::ENTRY_SETTLEMENT,
                    'amount' => $amount,
                    'payment_method' => $this->legacyMethod($payroll->payment_method ?? null),
                    'reference' => (string) ($payroll->payout_reference ?: $reference),
                    'paid_at' => CarbonImmutable::parse($paymentDate)->toDateTimeString(),
                    'recorded_by_user_id' => null,
                    'idempotency_key' => $sourceReference,
                    'source' => ExpenseSettlement::SOURCE_PAYROLL,
                    'source_reference' => $sourceReference,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }
        $this->stats['payroll_settlements']++;
    }

    private function backfillProcurementDueDates(int $chunk, bool $dryRun): void
    {
        if (! Schema::hasTable('finance_expenses') || ! Schema::hasTable('purchase_order_receipts') || ! Schema::hasTable('purchase_orders')) {
            return;
        }

        DB::table('finance_expenses as expenses')
            ->join('purchase_order_receipts as receipts', 'receipts.id', '=', 'expenses.procurement_receipt_id')
            ->join('purchase_orders as purchase_orders', 'purchase_orders.id', '=', 'receipts.purchase_order_id')
            ->whereNull('expenses.due_date')
            ->whereNotNull('receipts.received_at')
            ->orderBy('expenses.id')
            ->select([
                'expenses.id as id',
                'receipts.received_at',
                'purchase_orders.payment_terms',
            ])
            ->chunkById($chunk, function ($expenses) use ($dryRun): void {
                foreach ($expenses as $expense) {
                    $terms = trim((string) $expense->payment_terms);
                    if (! preg_match('/^Net\s+([1-9]\d{0,2})$/i', $terms, $matches)) {
                        continue;
                    }

                    $dueDate = CarbonImmutable::parse($expense->received_at)
                        ->addDays((int) $matches[1])
                        ->toDateString();
                    if (! $dryRun) {
                        DB::table('finance_expenses')
                            ->where('id', $expense->id)
                            ->whereNull('due_date')
                            ->update(['due_date' => $dueDate]);
                    }
                    $this->stats['procurement_due_dates']++;
                }
            }, 'expenses.id', 'id');
    }

    private function warnFor(string $type, string $entity, int $id): void
    {
        $this->stats['warnings']++;
        $this->warnings[] = sprintf('warning=%s entity=%s id=%d', $type, $entity, $id);
    }

    private function legacyMethod(?string $method): string
    {
        $method = strtolower(trim((string) $method));

        return $method !== '' ? substr($method, 0, 64) : 'legacy_unknown';
    }

    private function decimal(string $value): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', trim($value))) {
            return '0.00';
        }

        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '0');

        return ((int) $whole).'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function cents(string $value): int
    {
        $value = $this->decimal($value);
        [$whole, $fraction] = explode('.', $value);

        return ((int) $whole * 100) + (int) $fraction;
    }
}
