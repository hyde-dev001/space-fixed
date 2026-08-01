<?php

namespace Tests\Feature\Repair\Warranty;

use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\ShipmentLegService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairWarrantyLogisticsRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_warranty_pickup_records_one_customer_recovery_without_refund(): void
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        LogisticsSetting::create([
            'shop_owner_id' => $shop->id,
            'max_delivery_attempts' => 1,
        ]);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'pending',
            'is_warranty_job' => true,
            'billing_mode' => 'warranty_no_charge',
            'logistics_payment_reconciliation' => ['status' => 'resolved', 'entries' => []],
        ]);
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_pickup',
            'status' => 'active',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'leg_type' => 'inbound',
            'status' => 'assigned',
        ]);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);
        $assignment = $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $profile->id,
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);
        $leg->events()->create([
            'shipment_id' => $shipment->id,
            'event_type' => 'pickup_arrived',
            'visibility' => 'internal',
            'message' => 'Rider arrived for pickup.',
            'metadata' => ['delivery_assignment_id' => $assignment->id],
        ]);

        $payload = [
            'attempt_type' => 'pickup',
            'delivery_assignment_id' => $assignment->id,
            'idempotency_key' => 'warranty-terminal-pickup-recovery',
            'reason_code' => 'customer_unavailable',
            'file_path' => 'delivery-attempts/warranty-terminal-pickup.jpg',
        ];
        $service = app(ShipmentLegService::class);
        $attempt = $service->recordFailedAttempt($leg, $payload);
        $replayed = $service->recordFailedAttempt($leg->fresh(), $payload);

        $repair->refresh();
        $entries = collect(data_get($repair->logistics_payment_reconciliation, 'entries', []));
        $recovery = $entries->firstWhere('type', 'pickup_recovery');

        $this->assertSame($attempt->id, $replayed->id);
        $this->assertSame('cancelled', $repair->status);
        $this->assertSame('awaiting_arrangement', $recovery['status']);
        $this->assertSame($shipment->id, $recovery['shipment_id']);
        $this->assertSame($leg->id, $recovery['failed_leg_id']);
        $this->assertSame(1, $entries->where('type', 'pickup_recovery')->count());
        $this->assertSame(1, $leg->attempts()->count());
        $this->assertSame('cancelled', $shipment->fresh()->status->value);
    }
}
