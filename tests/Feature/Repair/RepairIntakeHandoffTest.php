<?php

namespace Tests\Feature\Repair;

use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\RepairDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairIntakeHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_pickup_requires_the_latest_delivered_leg_and_approved_delivery_proof(): void
    {
        [$repair, $repairer] = $this->repairFixture('shop_pickup');

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/mark-received")
            ->assertUnprocessable();

        [$shipment, $leg] = $this->pickupShipment($repair, 'completed', 'delivered');
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'pickup',
            'review_status' => 'approved',
        ]);

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/mark-received")
            ->assertUnprocessable();

        $proof->update(['handoff_type' => 'delivery', 'review_status' => 'pending']);

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/mark-received")
            ->assertUnprocessable();

        $this->assertSame('pending', $repair->fresh()->status);
        $this->assertSame('completed', $shipment->fresh()->status->value);
    }

    public function test_assigned_repairer_confirms_shop_pickup_once_after_dispatcher_approval(): void
    {
        [$repair, $repairer] = $this->repairFixture('shop_pickup');
        [, $leg] = $this->pickupShipment($repair, 'completed', 'delivered');
        HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'review_status' => 'approved',
        ]);

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/mark-received")
            ->assertOk()
            ->assertJsonPath('success', true);

        $receivedAt = $repair->fresh()->received_at;
        $this->assertSame('received', $repair->fresh()->status);
        $this->assertNotNull($receivedAt);

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/mark-received")
            ->assertUnprocessable();

        $this->assertTrue($receivedAt->equalTo($repair->fresh()->received_at));
    }

    public function test_paid_accepted_repair_can_recover_after_approved_pickup_delivery(): void
    {
        [$repair, $repairer] = $this->repairFixture('shop_pickup');
        $repair->update(['status' => 'repairer_accepted']);
        [, $leg] = $this->pickupShipment($repair, 'completed', 'delivered');
        HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'review_status' => 'approved',
        ]);

        $this->actingAs($repairer, 'user')
            ->getJson('/api/repairer/repairs')
            ->assertOk()
            ->assertJsonPath('data.0.intake_handoff.can_confirm_receipt', true);

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/mark-received")
            ->assertOk();

        $this->assertSame('received', $repair->fresh()->status);
    }

    public function test_non_dispatcher_intake_methods_lock_when_staff_physically_receives_them(): void
    {
        foreach (['walk_in', 'customer_delivery'] as $method) {
            [$repair, $repairer] = $this->repairFixture($method);

            $this->actingAs($repairer, 'user')
                ->postJson("/api/repairer/repairs/{$repair->id}/mark-received")
                ->assertOk();

            $repair->refresh();
            $this->assertSame('received', $repair->status);
            $this->assertNotNull($repair->intake_logistics_locked_at);
            $this->assertDatabaseMissing('shipments', [
                'source_type' => 'repair_request',
                'source_id' => $repair->id,
                'purpose' => 'repair_pickup',
            ]);
        }
    }

    public function test_non_dispatcher_intake_handoff_does_not_query_logistics(): void
    {
        [$repair] = $this->repairFixture('walk_in');

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(RepairDeliveryService::class)->intakeHandoff($repair, true);

        $shipmentQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'shipments'));

        $this->assertCount(0, $shipmentQueries);
    }

    public function test_unassigned_or_unauthorized_user_cannot_confirm_receipt(): void
    {
        [$repair, , $shop] = $this->repairFixture('walk_in');
        $unassigned = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'REPAIRER',
            'status' => 'active',
        ]);

        $this->actingAs($unassigned, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/mark-received")
            ->assertForbidden();

        $unauthorized = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'CUSTOMER',
            'status' => 'active',
        ]);
        $repair->update(['assigned_repairer_id' => $unauthorized->id]);

        $this->actingAs($unauthorized, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/mark-received")
            ->assertForbidden();

        $this->assertSame('pending', $repair->fresh()->status);
    }

    public function test_unpaid_or_premature_repair_cannot_be_received(): void
    {
        [$unpaid, $repairer] = $this->repairFixture('walk_in');
        $unpaid->update(['payment_status' => 'pending']);

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$unpaid->id}/mark-received")
            ->assertUnprocessable();

        [$premature, $otherRepairer] = $this->repairFixture('walk_in');
        $premature->update(['status' => 'assigned_to_repairer']);

        $this->actingAs($otherRepairer, 'user')
            ->postJson("/api/repairer/repairs/{$premature->id}/mark-received")
            ->assertUnprocessable();

        $this->assertNull($unpaid->fresh()->received_at);
        $this->assertNull($premature->fresh()->received_at);
    }

    public function test_owning_shop_owner_can_confirm_but_another_shop_cannot(): void
    {
        [$repair, , $shop] = $this->repairFixture('walk_in');
        $otherShop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $this->actingAs($otherShop, 'shop_owner')
            ->postJson("/api/shop-owner/repairs/{$repair->id}/mark-received")
            ->assertForbidden();

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/shop-owner/repairs/{$repair->id}/mark-received")
            ->assertOk();

        $this->assertSame('received', $repair->fresh()->status);
    }

    public function test_locked_intake_delivery_method_cannot_be_changed(): void
    {
        [$repair, $repairer] = $this->repairFixture('walk_in');
        $repair->update(['intake_logistics_locked_at' => now()]);

        $this->actingAs($repairer, 'user')
            ->patchJson("/api/repairer/repairs/{$repair->id}/delivery-method", [
                'delivery_method' => 'pickup',
            ])
            ->assertUnprocessable();

        $this->assertSame('walk_in', $repair->fresh()->intake_delivery_method);
    }

    public function test_job_order_payload_reports_the_intake_handoff_gate_and_timeline(): void
    {
        [$repair, $repairer] = $this->repairFixture('shop_pickup');
        [$shipment, $leg] = $this->pickupShipment($repair, 'active', 'awaiting_proof_approval');
        HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'review_status' => 'pending',
        ]);
        DeliveryEvent::factory()->create([
            'shipment_id' => $shipment->id,
            'shipment_leg_id' => $leg->id,
            'event_type' => 'proof_required',
            'message' => 'Delivery proof is awaiting approval.',
        ]);

        $this->actingAs($repairer, 'user')
            ->getJson('/api/repairer/repairs')
            ->assertOk()
            ->assertJsonPath('data.0.intake_handoff.shipment_id', $shipment->id)
            ->assertJsonPath('data.0.intake_handoff.leg_id', $leg->id)
            ->assertJsonPath('data.0.intake_handoff.proof_status', 'pending')
            ->assertJsonPath('data.0.intake_handoff.can_confirm_receipt', false)
            ->assertJsonPath('data.0.intake_handoff.events.0.event_type', 'proof_required');
    }

    public function test_job_order_handoff_queries_do_not_grow_per_repair(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'REPAIRER',
            'status' => 'active',
        ]);

        $createRepair = function () use ($shop, $repairer): void {
            $repair = RepairRequest::factory()->create([
                'shop_owner_id' => $shop->id,
                'assigned_repairer_id' => $repairer->id,
                'status' => 'pending',
                'payment_status' => 'paid',
                'payment_policy' => 'deposit_50',
                'payment_policy_snapshot' => 'deposit_50',
                'delivery_method' => 'pickup',
                'intake_delivery_method' => 'shop_pickup',
                'intake_address' => ['address_line' => '1 Test Street'],
                'intake_logistics_locked_at' => now(),
                'return_delivery_method' => 'customer_pickup',
            ]);
            $this->pickupShipment($repair, 'active', 'awaiting_proof_approval');
        };

        $countLogisticsQueries = function () use ($repairer): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($repairer, 'user')
                ->getJson('/api/repairer/repairs')
                ->assertOk();

            $count = collect(DB::getQueryLog())
                ->filter(function (array $query): bool {
                    $sql = strtolower($query['query']);

                    return str_contains($sql, 'shipments')
                        || str_contains($sql, 'shipment_legs')
                        || str_contains($sql, 'handoff_proofs')
                        || str_contains($sql, 'delivery_events');
                })
                ->count();

            DB::disableQueryLog();

            return $count;
        };

        $createRepair();
        $oneRepairQueryCount = $countLogisticsQueries();

        $createRepair();
        $twoRepairQueryCount = $countLogisticsQueries();

        $this->assertGreaterThan(0, $oneRepairQueryCount);
        $this->assertSame(
            $oneRepairQueryCount,
            $twoRepairQueryCount,
            'Adding a repair must not add per-repair logistics queries.',
        );
    }

    private function repairFixture(string $method): array
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'REPAIRER',
            'status' => 'active',
        ]);
        $customer = User::factory()->create();
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'user_id' => $customer->id,
            'assigned_repairer_id' => $repairer->id,
            'status' => 'pending',
            'payment_status' => 'paid',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'delivery_method' => $method === 'walk_in' ? 'walk_in' : 'pickup',
            'intake_delivery_method' => $method,
            'intake_address' => $method === 'walk_in' ? null : ['address_line' => '1 Test Street'],
            'intake_logistics_locked_at' => $method === 'shop_pickup' ? now() : null,
        ]);

        return [$repair, $repairer, $shop];
    }

    private function pickupShipment(RepairRequest $repair, string $shipmentStatus, string $legStatus): array
    {
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $repair->shop_owner_id,
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_pickup',
            'status' => $shipmentStatus,
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'sequence' => 1,
            'leg_type' => 'inbound',
            'status' => $legStatus,
        ]);

        return [$shipment, $leg];
    }
}
