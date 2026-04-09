<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderItemBasedPartialRefundFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function refund_line_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('order_refund_items'));
        $this->assertTrue(Schema::hasTable('pos_refund_items'));
    }
}
