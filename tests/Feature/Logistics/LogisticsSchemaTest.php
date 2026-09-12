<?php

namespace Tests\Feature\Logistics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LogisticsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_logistics_core_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('shipments'));
        $this->assertTrue(Schema::hasTable('shipment_legs'));
        $this->assertTrue(Schema::hasTable('shipping_methods'));
        $this->assertTrue(Schema::hasTable('rider_profiles'));
        $this->assertTrue(Schema::hasTable('courier_providers'));
        $this->assertTrue(Schema::hasTable('delivery_assignments'));
        $this->assertTrue(Schema::hasTable('delivery_events'));
        $this->assertTrue(Schema::hasTable('delivery_attempts'));
        $this->assertTrue(Schema::hasTable('handoff_proofs'));
    }
}
