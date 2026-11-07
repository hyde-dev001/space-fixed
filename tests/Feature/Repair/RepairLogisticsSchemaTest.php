<?php

namespace Tests\Feature\Repair;

use App\Models\Logistics\Shipment;
use App\Models\RepairPaymentSession;
use App\Models\RepairRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairLogisticsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_request_persists_versioned_delivery_state(): void
    {
        $repair = RepairRequest::factory()->create();

        $repair->update([
            'intake_delivery_method' => 'shop_pickup',
            'intake_delivery_fee' => 128.00,
            'return_delivery_fee' => 142.00,
            'same_as_intake_address' => true,
            'return_address_confirmed_at' => now(),
            'return_address_confirmed_version' => 'return-v1',
            'intake_logistics_locked_at' => now(),
            'return_logistics_locked_at' => now(),
            'intake_logistics_quote' => ['version' => 'intake-v1', 'fee' => 128.00],
            'return_logistics_quote' => ['version' => 'return-v1', 'fee' => 142.00],
            'logistics_payment_reconciliation' => ['status' => 'pending'],
        ]);

        $repair = $repair->fresh();

        $this->assertSame('shop_pickup', $repair->intake_delivery_method);
        $this->assertSame('128.00', $repair->intake_delivery_fee);
        $this->assertSame('142.00', $repair->return_delivery_fee);
        $this->assertTrue($repair->same_as_intake_address);
        $this->assertSame('return-v1', $repair->return_address_confirmed_version);
        $this->assertSame('intake-v1', $repair->intake_logistics_quote['version']);
        $this->assertSame('pending', $repair->logistics_payment_reconciliation['status']);
        $this->assertNotNull($repair->return_address_confirmed_at);
        $this->assertNotNull($repair->intake_logistics_locked_at);
        $this->assertNotNull($repair->return_logistics_locked_at);
    }

    public function test_repair_payment_session_keeps_phase_components_and_versions(): void
    {
        $repair = RepairRequest::factory()->create();

        $session = RepairPaymentSession::create([
            'repair_request_id' => $repair->id,
            'provider' => 'paymongo',
            'provider_link_id' => 'link_test_123',
            'phase' => 'initial',
            'status' => 'pending',
            'snapshot_version' => 'intake-v1',
            'delivery_method' => 'shop_pickup',
            'service_amount' => 500.00,
            'delivery_amount' => 128.00,
            'quote' => ['distance_km' => 4.2],
            'invalidated_at' => now(),
            'resolved_at' => now(),
        ]);

        $this->assertSame($repair->id, $session->repairRequest->id);
        $this->assertSame('500.00', $session->service_amount);
        $this->assertSame('128.00', $session->delivery_amount);
        $this->assertSame(4.2, $session->quote['distance_km']);
        $this->assertNotNull($session->invalidated_at);
        $this->assertNotNull($session->resolved_at);
        $this->assertCount(1, $repair->paymentSessions);
    }

    public function test_source_purpose_shipment_is_unique(): void
    {
        $shipment = Shipment::factory()->create([
            'source_type' => 'repair_request',
            'source_id' => 123,
            'purpose' => 'repair_pickup',
        ]);

        $this->expectException(QueryException::class);

        Shipment::factory()->create([
            'shop_owner_id' => $shipment->shop_owner_id,
            'source_type' => 'repair_request',
            'source_id' => 123,
            'purpose' => 'repair_pickup',
        ]);
    }
}
