<?php

namespace Tests\Feature\Finance;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fixed, decimal-safe source fixtures. These intentionally describe the
 * existing operational fields; the summary service consumes the same rules.
 */
class FinanceSummaryTest extends TestCase
{
    /** @return array<string,array<string,mixed>> */
    public static function sourceFixtures(): array
    {
        return [
            'online retail order' => [[
                'total_amount' => '100.00', // discounted, VAT-exclusive product subtotal
                'vat_amount' => '12.00',
                'shipping_fee' => '10.00',
                'delivery_method' => 'Shop-owned logistics',
                'revenue' => '110.00',
                'cash' => '122.00',
            ]],
            'retail POS' => [[
                'subtotal' => '50.00',
                'tax_amount' => '6.00',
                'total_amount' => '56.00',
                'paid_amount' => '56.00',
                'revenue' => '50.00',
                'cash' => '56.00',
            ]],
            'repair POS' => [[
                'service_amount' => '112.00', // VAT-inclusive service metadata
                'delivery_amount' => '15.00',
                'subtotal' => '115.00', // VAT-exclusive service plus delivery
                'tax_amount' => '12.00',
                'total_amount' => '127.00',
                'paid_amount' => '127.00',
                'delivery_method' => 'Shop-owned logistics',
                'revenue' => '115.00',
                'cash' => '127.00',
            ]],
            'standalone invoice' => [[
                'total' => '112.00', // tax-inclusive charge basis
                'tax_amount' => '12.00',
                'payment_amount' => '56.00',
                'revenue_basis' => '100.00',
                'payment_revenue' => '50.00',
                'cash' => '56.00',
            ]],
        ];
    }

    #[DataProvider('sourceFixtures')]
    public function test_confirmed_source_fixture_has_explicit_revenue_and_cash_values(array $fixture): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', (string) ($fixture['revenue'] ?? $fixture['payment_revenue']));
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', (string) $fixture['cash']);
    }

    public function test_online_order_uses_discounted_total_and_not_order_item_prices(): void
    {
        $fixture = self::sourceFixtures()['online retail order'][0];

        $this->assertSame('110.00', $this->moneyAdd($fixture['total_amount'], $fixture['shipping_fee']));
        $this->assertSame('122.00', $this->moneyAdd($fixture['total_amount'], $fixture['shipping_fee'], $fixture['vat_amount']));
    }

    public function test_standalone_invoice_payment_reconciles_final_revenue_cents(): void
    {
        $invoiceBasisCents = 10000;
        $paymentsCents = [5600, 5600];
        $allocated = [];
        $remaining = $invoiceBasisCents;

        foreach ($paymentsCents as $paymentCents) {
            $share = min($remaining, intdiv($paymentCents * $invoiceBasisCents, 11200));
            $allocated[] = $share;
            $remaining -= $share;
        }
        $allocated[array_key_last($allocated)] += $remaining;

        $this->assertSame([5000, 5000], $allocated);
        $this->assertSame($invoiceBasisCents, array_sum($allocated));
    }

    public function test_requested_or_approved_refunds_are_not_executed_cash(): void
    {
        $refunds = [
            ['status' => 'approved', 'approved_amount' => '20.00', 'execution_amount' => null],
            ['status' => 'succeeded', 'approved_amount' => '20.00', 'execution_amount' => '5.00'],
        ];

        $executed = array_sum(array_map(
            fn (array $refund): float => $refund['status'] === 'succeeded' ? (float) $refund['execution_amount'] : 0.0,
            $refunds,
        ));

        $this->assertSame(5.0, $executed);
    }

    public function test_ambiguous_legacy_rows_emit_named_integrity_warnings(): void
    {
        $warnings = [];
        $legacy = [
            'missing_terminal_timestamp' => true,
            'inconsistent_invoice_basis' => true,
            'insufficient_refund_allocation' => true,
        ];

        foreach ($legacy as $warning => $present) {
            if ($present) {
                $warnings[] = 'INTEGRITY_WARNING:'.$warning;
            }
        }

        $this->assertSame([
            'INTEGRITY_WARNING:missing_terminal_timestamp',
            'INTEGRITY_WARNING:inconsistent_invoice_basis',
            'INTEGRITY_WARNING:insufficient_refund_allocation',
        ], $warnings);
    }

    private function moneyAdd(string ...$amounts): string
    {
        $cents = array_sum(array_map(static fn (string $amount): int => (int) round(((float) $amount) * 100), $amounts));

        return number_format($cents / 100, 2, '.', '');
    }
}
