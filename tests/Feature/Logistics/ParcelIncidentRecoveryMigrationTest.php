<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ParcelIncidentRecoveryMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_is_nullable_and_no_owner_participant_schema_is_added(): void
    {
        $incident = DeliveryIncident::factory()->create();
        $this->assertNull($incident->fresh()->recovery_context);
        $this->assertTrue(Schema::hasColumns('shipment_custody_transfers', ['requested_by_user_id', 'released_by_user_id', 'received_by_user_id']));
        $this->assertFalse(Schema::hasColumn('shipment_custody_transfers', 'released_by_shop_owner_id'));
        $this->assertFalse(Schema::hasColumn('shipment_custody_transfers', 'received_by_shop_owner_id'));
        $migration = require database_path('migrations/2026_10_27_020923_add_recovery_context_to_delivery_incidents.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('delivery_incidents', 'recovery_context'));
        $migration->up();
        $this->assertNull($incident->fresh()->recovery_context);
    }

    public function test_rollback_refuses_to_erase_populated_recovery_history(): void
    {
        $incident = DeliveryIncident::factory()->create();
        $incident->forceFill(['recovery_context' => ['receipt' => ['proof_id' => 123, 'notes' => 'Retained evidence reference']]])->save();
        $migration = require database_path('migrations/2026_10_27_020923_add_recovery_context_to_delivery_incidents.php');
        try {
            $migration->down();
            $this->fail('Rollback erased recovery context.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Retain incident recovery evidence', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('delivery_incidents', 'recovery_context'));
        $this->assertSame('Retained evidence reference', $incident->fresh()->recovery_context['receipt']['notes']);
    }
}
