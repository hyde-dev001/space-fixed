<?php

namespace Tests\Feature\Logistics;

use App\Enums\Logistics\ShipmentLegStatus;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeliveryExecutionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_execution_state_is_persisted(): void
    {
        $this->assertTrue(Schema::hasColumns('shipment_legs', ['attempt_number', 'out_for_delivery_at', 'resolution_type', 'resolution_reason']));
        $this->assertTrue(Schema::hasColumns('delivery_attempts', [
            'attempt_number', 'delivery_assignment_id', 'delivery_batch_id', 'idempotency_key',
        ]));
        $this->assertTrue(Schema::hasColumn('order_items', 'product_variant_id'));
        $this->assertSame('needs_resolution', ShipmentLegStatus::NEEDS_RESOLUTION->value);
    }

    public function test_attempt_idempotency_key_is_unique(): void
    {
        $leg = ShipmentLeg::factory()->create();
        $attributes = [
            'shipment_leg_id' => $leg->id,
            'idempotency_key' => '3aa7c6c2-0459-48be-ab0a-32090fe414cd',
            'attempt_type' => 'delivery',
            'status' => 'failed',
            'attempt_number' => 1,
        ];

        DeliveryAttempt::create($attributes);

        $this->expectException(QueryException::class);
        DeliveryAttempt::create($attributes);
    }

    public function test_only_one_return_leg_can_reference_a_failed_outbound_leg(): void
    {
        $outbound = ShipmentLeg::factory()->create();
        $attributes = [
            'shipment_id' => $outbound->shipment_id,
            'sequence' => 2,
            'leg_type' => 'return',
            'status' => 'pending',
            'origin_snapshot' => $outbound->destination_snapshot,
            'destination_snapshot' => $outbound->origin_snapshot,
            'return_for_leg_id' => $outbound->id,
        ];

        ShipmentLeg::create($attributes);

        $this->expectException(QueryException::class);
        ShipmentLeg::create($attributes);
    }
}
