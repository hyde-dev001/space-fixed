<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\OrderRefund;
use App\Models\PosRefund;
use App\Services\RefundLineCalculatorService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RefundLineCalculatorServiceTest extends TestCase
{
    #[Test]
    public function refund_headers_expose_line_relations(): void
    {
        $this->assertTrue(method_exists(new OrderRefund(), 'items'));
        $this->assertTrue(method_exists(new PosRefund(), 'items'));
    }

    #[Test]
    public function it_computes_remaining_qty_and_line_amounts(): void
    {
        $service = app(RefundLineCalculatorService::class);

        $result = $service->computeLineAmount(unitPrice: 1200.00, qty: 2);

        $this->assertSame(2400.00, $result);
    }
}
