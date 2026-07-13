<?php

namespace Tests\Feature\Logistics;

use App\Enums\Logistics\ShipmentLegStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeliveryExecutionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_execution_state_is_persisted(): void
    {
        $this->assertTrue(Schema::hasColumns('shipment_legs', ['attempt_number', 'out_for_delivery_at', 'resolution_type', 'resolution_reason']));
        $this->assertSame('needs_resolution', ShipmentLegStatus::NEEDS_RESOLUTION->value);
    }
}
