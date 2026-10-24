<?php

namespace Tests\Feature\Logistics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeliveryIncidentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_state_is_persisted(): void
    {
        $this->assertTrue(Schema::hasColumns('delivery_incidents', [
            'shop_owner_id', 'shipment_leg_id', 'reporting_rider_profile_id', 'type', 'status',
            'photo_paths', 'notes', 'resolution', 'responsible_party', 'resolved_at',
        ]));
    }
}
