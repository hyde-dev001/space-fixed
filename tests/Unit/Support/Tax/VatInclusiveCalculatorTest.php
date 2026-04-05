<?php

namespace Tests\Unit\Support\Tax;

use App\Support\Tax\VatInclusiveCalculator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VatInclusiveCalculatorTest extends TestCase
{
    #[Test]
    public function it_extracts_vat_and_net_from_inclusive_total(): void
    {
        $breakdown = VatInclusiveCalculator::extract(112.00, 12.0);

        $this->assertSame('112.00', number_format($breakdown['total'], 2, '.', ''));
        $this->assertSame('12.00', number_format($breakdown['vat'], 2, '.', ''));
        $this->assertSame('100.00', number_format($breakdown['net'], 2, '.', ''));
    }

    #[Test]
    public function it_handles_rounding_consistently_for_non_integer_totals(): void
    {
        $breakdown = VatInclusiveCalculator::extract(500.00, 12.0);

        $this->assertSame('500.00', number_format($breakdown['total'], 2, '.', ''));
        $this->assertSame('53.57', number_format($breakdown['vat'], 2, '.', ''));
        $this->assertSame('446.43', number_format($breakdown['net'], 2, '.', ''));
    }
}
