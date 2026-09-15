<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class AuditFinanceIntegrity extends Command
{
    protected $signature = 'finance:audit-integrity
        {--section=legacy-disbursers : Read-only audit section}';

    protected $description = 'Report Finance integrity exceptions without mutating data';

    public function handle(): int
    {
        return match ((string) $this->option('section')) {
            'legacy-disbursers' => $this->auditLegacyDisbursers(),
            'job-invoices' => $this->auditJobInvoices(),
            'historical-excess' => $this->auditHistoricalExcess(),
            'paid-rejected-expenses' => $this->auditPaidRejectedExpenses(),
            'linked-source-orphans' => $this->auditLinkedSourceOrphans(),
            'ambiguous-refunds' => $this->auditAmbiguousRefunds(),
            'missing-receipts' => $this->auditMissingReceipts(),
            default => $this->invalidSection(),
        };
    }

    private function auditLegacyDisbursers(): int
    {
        $rows = User::query()->orderBy('id')->get(['id', 'shop_owner_id']);
        $legacy = $rows->filter(function (User $user): bool {
            try {
                $isShopOwner = $user->hasRole('Shop Owner');
                $hasExplicitDisburser = $user->can('disburse-payroll');
                $hasLegacyAccess = $user->can('access-payslip-approval') || $user->can('access-approval-workflow');

                return ! $isShopOwner && ! $hasExplicitDisburser && $hasLegacyAccess;
            } catch (\Throwable) {
                return false;
            }
        })->values();

        $this->line('section=legacy-disbursers count='.$legacy->count());
        foreach ($legacy as $user) {
            $this->line(sprintf('user_id=%d shop_owner_id=%s', (int) $user->id, (string) ($user->shop_owner_id ?? 'null')));
        }

        return self::SUCCESS;
    }

    private function invalidSection(): int
    {
        $this->error('Unknown audit section. Supported sections: legacy-disbursers, job-invoices, historical-excess, paid-rejected-expenses, linked-source-orphans, ambiguous-refunds, missing-receipts.');

        return self::INVALID;
    }

    private function auditJobInvoices(): int
    {
        $duplicates = DB::table('finance_invoices')
            ->select('shop_id', 'job_order_id', DB::raw('COUNT(*) as duplicate_count'))
            ->whereNotNull('job_order_id')
            ->groupBy('shop_id', 'job_order_id')
            ->having('duplicate_count', '>', 1)
            ->orderBy('shop_id')
            ->orderBy('job_order_id')
            ->get();

        $this->line('section=job-invoices groups='.$duplicates->count());
        foreach ($duplicates as $duplicate) {
            $this->line(sprintf(
                'shop_id=%s job_order_id=%d count=%d',
                (string) ($duplicate->shop_id ?? 'null'),
                (int) $duplicate->job_order_id,
                (int) $duplicate->duplicate_count,
            ));
        }

        return $duplicates->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    private function auditHistoricalExcess(): int
    {
        $invoiceIds = [];
        if (Schema::hasTable('finance_invoice_payments') && Schema::hasTable('finance_invoices')) {
            $payments = DB::table('finance_invoice_payments')
                ->select('invoice_id', 'entry_type', 'amount')
                ->orderBy('invoice_id')
                ->get();
            $paid = [];
            foreach ($payments as $payment) {
                $invoiceId = (int) $payment->invoice_id;
                $signed = (float) $payment->amount * ($payment->entry_type === 'reversal' ? -1 : 1);
                $paid[$invoiceId] = ($paid[$invoiceId] ?? 0) + $signed;
            }
            $totals = DB::table('finance_invoices')->pluck('total', 'id');
            foreach ($paid as $invoiceId => $amount) {
                if ($amount > (float) ($totals[$invoiceId] ?? 0)) {
                    $invoiceIds[] = $invoiceId;
                }
            }
        }

        $expenseIds = [];
        if (Schema::hasTable('finance_expense_settlements') && Schema::hasTable('finance_expenses')) {
            $settlements = DB::table('finance_expense_settlements')
                ->join('finance_expenses', 'finance_expenses.id', '=', 'finance_expense_settlements.expense_id')
                ->select('finance_expense_settlements.expense_id', 'finance_expense_settlements.entry_type', 'finance_expense_settlements.amount', 'finance_expenses.amount as expense_amount')
                ->orderBy('expense_id')
                ->get();
            $paid = [];
            $limits = [];
            foreach ($settlements as $settlement) {
                $expenseId = (int) $settlement->expense_id;
                $signed = (float) $settlement->amount * ($settlement->entry_type === 'reversal' ? -1 : 1);
                $paid[$expenseId] = ($paid[$expenseId] ?? 0) + $signed;
                $limits[$expenseId] = (float) $settlement->expense_amount;
            }
            foreach ($paid as $expenseId => $amount) {
                if ($amount > ($limits[$expenseId] ?? 0)) {
                    $expenseIds[] = $expenseId;
                }
            }
        }

        $count = count($invoiceIds) + count($expenseIds);
        $this->line('section=historical-excess count='.$count);
        foreach ($invoiceIds as $id) {
            $this->line('entity=invoice id='.$id);
        }
        foreach ($expenseIds as $id) {
            $this->line('entity=expense id='.$id);
        }

        return $count === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function auditPaidRejectedExpenses(): int
    {
        if (! Schema::hasTable('finance_expense_settlements') || ! Schema::hasTable('finance_expenses')) {
            $this->line('section=paid-rejected-expenses count=0');

            return self::SUCCESS;
        }

        $ids = DB::table('finance_expense_settlements as settlements')
            ->join('finance_expenses as expenses', 'expenses.id', '=', 'settlements.expense_id')
            ->where('expenses.status', 'rejected')
            ->where('settlements.entry_type', 'settlement')
            ->distinct()
            ->orderBy('expenses.id')
            ->pluck('expenses.id');

        $this->line('section=paid-rejected-expenses count='.$ids->count());
        foreach ($ids as $id) {
            $this->line('expense_id='.(int) $id);
        }

        return $ids->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    private function auditLinkedSourceOrphans(): int
    {
        if (! Schema::hasTable('finance_invoices') || ! Schema::hasTable('orders')) {
            $this->line('section=linked-source-orphans count=0');

            return self::SUCCESS;
        }

        $ids = DB::table('finance_invoices as invoices')
            ->leftJoin('orders', 'orders.id', '=', 'invoices.job_order_id')
            ->whereNotNull('invoices.job_order_id')
            ->whereNull('orders.id')
            ->orderBy('invoices.id')
            ->pluck('invoices.id');

        $this->line('section=linked-source-orphans count='.$ids->count());
        foreach ($ids as $id) {
            $this->line('invoice_id='.(int) $id);
        }

        return $ids->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    private function auditAmbiguousRefunds(): int
    {
        $ids = collect();
        if (Schema::hasTable('order_refunds') && Schema::hasTable('orders')) {
            $ids = $ids->merge(DB::table('order_refunds as refunds')
                ->leftJoin('orders', 'orders.id', '=', 'refunds.order_id')
                ->where('refunds.status', 'succeeded')
                ->whereNotNull('refunds.amount')
                ->where('refunds.amount', '>', 0)
                ->where('orders.shipping_fee', '>', 0)
                ->where(function ($query): void {
                    $query->whereNull('refunds.reason_note')
                        ->orWhere(function ($nested): void {
                            $nested->where('refunds.reason_note', 'not like', '%finance shipping decision:%');
                        });
                })
                ->orderBy('refunds.id')
                ->pluck('refunds.id'));
        }
        if (Schema::hasTable('pos_refunds')) {
            $ids = $ids->merge(DB::table('pos_refunds')
                ->where('status', 'succeeded')
                ->where('execution_amount', '>', 0)
                ->orderBy('id')
                ->pluck('id'));
        }
        $ids = $ids->unique()->values();

        $this->line('section=ambiguous-refunds count='.$ids->count());
        foreach ($ids as $id) {
            $this->line('refund_id='.(int) $id);
        }

        return $ids->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    private function auditMissingReceipts(): int
    {
        if (! Schema::hasTable('finance_expenses')) {
            $this->line('section=missing-receipts count=0');

            return self::SUCCESS;
        }

        $ids = DB::table('finance_expenses')
            ->whereNotNull('receipt_path')
            ->orderBy('id')
            ->get(['id', 'receipt_path'])
            ->filter(fn (object $expense): bool => ! Storage::disk('public')->exists((string) $expense->receipt_path))
            ->pluck('id')
            ->values();

        $this->line('section=missing-receipts count='.$ids->count());
        foreach ($ids as $id) {
            $this->line('expense_id='.(int) $id);
        }

        return $ids->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
