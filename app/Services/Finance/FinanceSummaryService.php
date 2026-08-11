<?php

namespace App\Services\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinanceSummaryService
{
    /**
     * @return array{period: array<string,mixed>, primary: array<string,string>, supporting: array<string,string>, trend: array<int,array<string,string>>, definitions: array<string,string>, integrity_warnings: array<int,array<string,mixed>>}
     */
    public function forCurrentPeriod(int $shopId, CarbonImmutable $now): array
    {
        $yearStart = $now->startOfYear();
        $yearEnd = $yearStart->addYear();
        $trendStart = $now->startOfMonth()->subMonths(5);
        $totals = [
            'gross_revenue' => 0,
            'executed_refunds' => 0,
            'customer_cash_receipts' => 0,
            'incurred_expenses' => 0,
            'paid_expenses' => 0,
        ];
        $trend = $this->emptyTrend($trendStart);
        $warnings = [];

        $this->addOrders($shopId, $trendStart, $yearEnd, $yearStart, $yearEnd, $totals, $trend, $warnings);
        $this->addPosTransactions($shopId, $trendStart, $yearEnd, $yearStart, $yearEnd, $totals, $trend, $warnings);
        $this->addStandaloneInvoices($shopId, $trendStart, $yearEnd, $yearStart, $yearEnd, $totals, $trend, $warnings);
        $this->addRefunds($shopId, $trendStart, $yearEnd, $yearStart, $yearEnd, $totals, $trend, $warnings);
        $this->addExpenses($shopId, $trendStart, $yearEnd, $yearStart, $yearEnd, $totals, $trend, $warnings);

        $netRevenue = $totals['gross_revenue'] - $totals['executed_refunds'];
        $netOperatingResult = $netRevenue - $totals['incurred_expenses'];
        // customer_cash_receipts is net of executed cash refunds; revenue
        // refunds remain a separate supporting metric.
        $netCashMovement = $totals['customer_cash_receipts'] - $totals['paid_expenses'];

        return [
            'period' => [
                'type' => 'current_year',
                'start' => $yearStart->toDateString(),
                'end' => $yearEnd->subDay()->toDateString(),
                'timezone' => $now->getTimezone()->getName(),
            ],
            'primary' => [
                'net_revenue' => $this->money($netRevenue),
                'incurred_expenses' => $this->money($totals['incurred_expenses']),
                'net_operating_result' => $this->money($netOperatingResult),
                'net_cash_movement' => $this->money($netCashMovement),
            ],
            'supporting' => [
                'gross_revenue' => $this->money($totals['gross_revenue']),
                'executed_refunds' => $this->money($totals['executed_refunds']),
                'paid_expenses' => $this->money($totals['paid_expenses']),
            ],
            'trend' => array_values(array_map(function (array $month): array {
                $month['gross_revenue'] = $this->money($month['gross_revenue']);
                $month['executed_refunds'] = $this->money($month['executed_refunds']);
                $month['incurred_expenses'] = $this->money($month['incurred_expenses']);
                $month['net_revenue'] = $this->money($month['gross_revenue_cents'] - $month['executed_refunds_cents']);
                $month['net_cash_movement'] = $this->money($month['cash_receipts_cents'] - $month['paid_expenses_cents']);
                unset($month['gross_revenue_cents'], $month['executed_refunds_cents'], $month['cash_receipts_cents'], $month['paid_expenses_cents']);
                return $month;
            }, $trend)),
            'definitions' => [
                'net_revenue' => 'Gross revenue less executed refunds, excluding VAT and non-retained delivery.',
                'incurred_expenses' => 'Approved manual, valid procurement, and completed payroll expenses.',
                'net_operating_result' => 'Net revenue less incurred expenses.',
                'net_cash_movement' => 'Customer cash receipts less executed refunds and valid expense settlements.',
            ],
            'integrity_warnings' => array_values($warnings),
        ];
    }

    /** @param array<string,int> $totals @param array<string,array<string,mixed>> $trend @param array<int,array<string,mixed>> $warnings */
    private function addOrders(int $shopId, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, array &$totals, array &$trend, array &$warnings): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        $rows = DB::table('orders')
            ->where('shop_owner_id', $shopId)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $to)
            ->whereIn('payment_status', ['paid', 'refunded'])
            ->get(['id', 'total_amount', 'shipping_fee', 'vat_amount', 'carrier_company', 'paid_at']);

        foreach ($rows as $row) {
            $total = $this->cents($row->total_amount);
            $shipping = $this->cents($row->shipping_fee);
            $vat = $this->cents($row->vat_amount);
            if ($total < 0 || $vat < 0) {
                $warnings[] = $this->warning('invalid_order_amounts', 'order', $row->id);
                continue;
            }
            $retainedShipping = strtolower(trim((string) $row->carrier_company)) === 'shop-owned logistics';
            $revenue = $total + ($retainedShipping ? $shipping : 0);
            $cash = $total + $shipping + $vat;
            $this->addRevenue($row->paid_at, $revenue, $cash, $periodStart, $periodEnd, $totals, $trend);
        }
    }

    /** @param array<string,int> $totals @param array<string,array<string,mixed>> $trend @param array<int,array<string,mixed>> $warnings */
    private function addPosTransactions(int $shopId, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, array &$totals, array &$trend, array &$warnings): void
    {
        if (! Schema::hasTable('pos_transactions')) {
            return;
        }

        $rows = DB::table('pos_transactions')
            ->where('shop_owner_id', $shopId)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $to)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->get(['id', 'module_type', 'subtotal', 'paid_amount', 'total_amount', 'paid_at', 'metadata']);

        foreach ($rows as $row) {
            $metadata = is_array($row->metadata) ? $row->metadata : json_decode((string) $row->metadata, true);
            $metadata = is_array($metadata) ? $metadata : [];
            $subtotal = $this->cents($row->subtotal);
            $cash = $this->cents($row->paid_amount);
            $revenue = $subtotal;

            if (strtolower((string) $row->module_type) === 'repair') {
                $delivery = $this->cents($metadata['delivery_amount'] ?? 0);
                $method = strtolower((string) ($metadata['delivery_method'] ?? ''));
                if (! in_array($method, ['shop_pickup', 'shop_delivery'], true)) {
                    $revenue = max(0, $subtotal - $delivery);
                }
            }

            if ($revenue < 0 || $cash < 0) {
                $warnings[] = $this->warning('invalid_pos_amounts', 'pos', $row->id);
                continue;
            }
            $this->addRevenue($row->paid_at, $revenue, $cash, $periodStart, $periodEnd, $totals, $trend);
        }
    }

    /** @param array<string,int> $totals @param array<string,array<string,mixed>> $trend @param array<int,array<string,mixed>> $warnings */
    private function addStandaloneInvoices(int $shopId, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, array &$totals, array &$trend, array &$warnings): void
    {
        if (! Schema::hasTable('finance_invoices')) {
            return;
        }

        $rows = DB::table('finance_invoices')
            ->where('shop_id', $shopId)
            ->whereNull('job_order_id')
            ->where('status', 'paid')
            ->whereNotNull('payment_date')
            ->where('payment_date', '>=', $from->toDateString())
            ->where('payment_date', '<', $to->toDateString())
            ->get(['id', 'total', 'tax_amount', 'payment_date']);

        foreach ($rows as $row) {
            $total = $this->cents($row->total);
            $tax = $this->cents($row->tax_amount);
            $basis = $total - $tax;
            if ($total <= 0 || $basis < 0 || $tax > $total) {
                $warnings[] = $this->warning('inconsistent_invoice_basis', 'invoice', $row->id);
                continue;
            }
            $this->addRevenue($row->payment_date, $basis, $total, $periodStart, $periodEnd, $totals, $trend);
        }
    }

    /** @param array<string,int> $totals @param array<string,array<string,mixed>> $trend @param array<int,array<string,mixed>> $warnings */
    private function addRefunds(int $shopId, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, array &$totals, array &$trend, array &$warnings): void
    {
        if (Schema::hasTable('order_refunds')) {
            $rows = DB::table('order_refunds as refunds')
                ->leftJoin('orders', 'orders.id', '=', 'refunds.order_id')
                ->where('refunds.shop_owner_id', $shopId)
                ->where('refunds.status', 'succeeded')
                ->whereNotNull('refunds.refunded_at')
                ->where('refunds.refunded_at', '>=', $from)
                ->where('refunds.refunded_at', '<', $to)
                ->get(['refunds.id', 'refunds.amount', 'refunds.refunded_at', 'refunds.reason_note', 'orders.total_amount', 'orders.vat_amount', 'orders.shipping_fee']);

            foreach ($rows as $row) {
                $payout = $this->cents($row->amount);
                $productCharge = $this->cents($row->total_amount) + $this->cents($row->vat_amount);
                if ($payout < 0 || $productCharge <= 0) {
                    $warnings[] = $this->warning('legacy_refund_allocation', 'order-refund', $row->id);
                    continue;
                }
                $shipping = $this->cents($row->shipping_fee);
                $includesShipping = str_contains(strtolower((string) $row->reason_note), 'finance shipping decision: included');
                if ($shipping > 0 && ! $includesShipping && ! str_contains(strtolower((string) $row->reason_note), 'finance shipping decision: retained')) {
                    $warnings[] = $this->warning('legacy_refund_allocation', 'order-refund', $row->id);
                }
                $revenueRefund = min($this->cents($row->total_amount), intdiv($payout * $this->cents($row->total_amount), $productCharge));
                if ($includesShipping) {
                    $revenueRefund = min($this->cents($row->total_amount) + $shipping, $revenueRefund + min($shipping, $payout));
                }
                $this->addRefund($row->refunded_at, $payout, $revenueRefund, $periodStart, $periodEnd, $totals, $trend);
            }
        }

        if (Schema::hasTable('pos_refunds')) {
            $rows = DB::table('pos_refunds as refunds')
                ->leftJoin('pos_transactions as transactions', 'transactions.id', '=', 'refunds.source_transaction_id')
                ->where('refunds.shop_owner_id', $shopId)
                ->where('refunds.status', 'succeeded')
                ->whereNotNull('refunds.executed_at')
                ->where('refunds.executed_at', '>=', $from)
                ->where('refunds.executed_at', '<', $to)
                ->get(['refunds.id', 'refunds.execution_amount', 'refunds.executed_at', 'transactions.subtotal', 'transactions.total_amount']);

            foreach ($rows as $row) {
                $payout = $this->cents($row->execution_amount);
                $charge = $this->cents($row->total_amount);
                $basis = $this->cents($row->subtotal);
                if ($payout < 0 || $charge <= 0 || $basis < 0) {
                    $warnings[] = $this->warning('legacy_refund_allocation', 'pos-refund', $row->id);
                    continue;
                }
                $this->addRefund($row->executed_at, $payout, min($basis, intdiv($payout * $basis, $charge)), $periodStart, $periodEnd, $totals, $trend);
            }
        }
    }

    /** @param array<string,int> $totals @param array<string,array<string,mixed>> $trend @param array<int,array<string,mixed>> $warnings */
    private function addExpenses(int $shopId, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, array &$totals, array &$trend, array &$warnings): void
    {
        if (! Schema::hasTable('finance_expenses')) {
            return;
        }

        $rows = DB::table('finance_expenses')
            ->where('shop_id', $shopId)
            ->whereNull('deleted_at')
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<', $to->toDateString())
            ->whereIn('status', ['approved', 'posted'])
            ->get(['id', 'amount', 'date']);

        foreach ($rows as $row) {
            $amount = $this->cents($row->amount);
            if ($amount < 0) {
                $warnings[] = $this->warning('invalid_expense_amount', 'expense', $row->id);
                continue;
            }
            $this->addIncurred($row->date, $amount, $periodStart, $periodEnd, $totals, $trend);
        }

        $settlements = DB::table('finance_expense_settlements as settlements')
            ->join('finance_expenses as expenses', 'expenses.id', '=', 'settlements.expense_id')
            ->where('settlements.shop_owner_id', $shopId)
            ->whereNotNull('settlements.paid_at')
            ->where('settlements.paid_at', '>=', $from)
            ->where('settlements.paid_at', '<', $to)
            ->get([
                'settlements.id',
                'settlements.expense_id',
                'settlements.entry_type',
                'settlements.amount',
                'settlements.paid_at',
                'expenses.amount as expense_amount',
                'expenses.status as expense_status',
            ]);
        $settledByExpense = [];
        foreach ($settlements as $settlement) {
            $amount = $this->cents($settlement->amount);
            if ($amount <= 0) {
                $warnings[] = $this->warning('invalid_settlement_amount', 'expense-settlement', $settlement->id);
                continue;
            }

            $signedAmount = (string) $settlement->entry_type === 'reversal' ? -$amount : $amount;
            if (! in_array((string) $settlement->entry_type, ['settlement', 'reversal'], true)) {
                $warnings[] = $this->warning('unknown_settlement_entry', 'expense-settlement', $settlement->id);
                continue;
            }

            $expenseId = (int) $settlement->expense_id;
            $settledByExpense[$expenseId] = ($settledByExpense[$expenseId] ?? 0) + $signedAmount;
            if ($settledByExpense[$expenseId] > $this->cents($settlement->expense_amount)) {
                $warnings[] = $this->warning('overpaid_expense_settlement', 'expense', $expenseId);
            }
            if ((string) $settlement->expense_status === 'rejected' && $settledByExpense[$expenseId] > 0) {
                $warnings[] = $this->warning('paid_rejected_expense', 'expense', $expenseId);
            }
            $this->addPaidExpense($settlement->paid_at, $signedAmount, $periodStart, $periodEnd, $totals, $trend);
        }
    }

    /** @param array<string,int> $totals @param array<string,array<string,mixed>> $trend */
    private function addRevenue($date, int $revenue, int $cash, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, array &$totals, array &$trend): void
    {
        $this->addTrend($date, $revenue, 0, $cash, 0, 0, $trend);
        if ($this->inPeriod($date, $periodStart, $periodEnd)) {
            $totals['gross_revenue'] += $revenue;
            $totals['customer_cash_receipts'] += $cash;
        }
    }

    /** @param array<string,int> $totals @param array<string,array<string,mixed>> $trend */
    private function addRefund($date, int $cash, int $revenue, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, array &$totals, array &$trend): void
    {
        $this->addTrend($date, 0, $revenue, 0, 0, 0, $trend);
        if ($this->inPeriod($date, $periodStart, $periodEnd)) {
            $totals['executed_refunds'] += $revenue;
            $totals['customer_cash_receipts'] -= 0;
        }
        $this->addTrend($date, 0, 0, -$cash, 0, 0, $trend);
        if ($this->inPeriod($date, $periodStart, $periodEnd)) {
            $totals['customer_cash_receipts'] -= $cash;
        }
    }

    /** @param array<string,int> $totals @param array<string,array<string,mixed>> $trend */
    private function addIncurred($date, int $amount, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, array &$totals, array &$trend): void
    {
        $this->addTrend($date, 0, 0, 0, $amount, 0, $trend);
        if ($this->inPeriod($date, $periodStart, $periodEnd)) {
            $totals['incurred_expenses'] += $amount;
        }
    }

    /** @param array<string,int> $totals @param array<string,array<string,mixed>> $trend */
    private function addPaidExpense($date, int $amount, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, array &$totals, array &$trend): void
    {
        $this->addTrend($date, 0, 0, 0, 0, $amount, $trend);
        if ($this->inPeriod($date, $periodStart, $periodEnd)) {
            $totals['paid_expenses'] += $amount;
        }
    }

    /** @param array<string,array<string,mixed>> $trend */
    private function addTrend($date, int $gross, int $refund, int $cash, int $incurred, int $paid, array &$trend): void
    {
        $key = CarbonImmutable::parse($date)->format('Y-m');
        if (! isset($trend[$key])) {
            return;
        }
        $trend[$key]['gross_revenue_cents'] += $gross;
        $trend[$key]['executed_refunds_cents'] += $refund;
        $trend[$key]['cash_receipts_cents'] += $cash;
        $trend[$key]['incurred_expenses_cents'] += $incurred;
        $trend[$key]['paid_expenses_cents'] += $paid;
        $trend[$key]['gross_revenue'] = $trend[$key]['gross_revenue_cents'];
        $trend[$key]['executed_refunds'] = $trend[$key]['executed_refunds_cents'];
        $trend[$key]['incurred_expenses'] = $trend[$key]['incurred_expenses_cents'];
    }

    /** @return array<string,array<string,mixed>> */
    private function emptyTrend(CarbonImmutable $start): array
    {
        $trend = [];
        for ($i = 0; $i < 6; $i++) {
            $key = $start->addMonths($i)->format('Y-m');
            $trend[$key] = [
                'month' => $key,
                'gross_revenue_cents' => 0,
                'executed_refunds_cents' => 0,
                'cash_receipts_cents' => 0,
                'incurred_expenses_cents' => 0,
                'paid_expenses_cents' => 0,
                'gross_revenue' => 0,
                'executed_refunds' => 0,
                'incurred_expenses' => 0,
            ];
        }
        return $trend;
    }

    private function inPeriod($date, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $value = CarbonImmutable::parse($date);
        return $value >= $start && $value < $end;
    }

    /** @return array{code:string,source:string,id:int} */
    private function warning(string $code, string $source, int $id): array
    {
        return ['code' => 'INTEGRITY_WARNING', 'source' => $source, 'id' => $id, 'reason' => $code];
    }

    private function cents($value): int
    {
        $text = trim((string) $value);
        if ($text === '' || ! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $text)) {
            return 0;
        }
        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '+-');
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        return $negative ? -$cents : $cents;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
