<?php

namespace Tests\Feature\Repair;

use App\Models\Finance\Invoice;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairReturnHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_courier_tracking_handoff_and_receipt_are_customer_owned_and_idempotent(): void
    {
        [$repair, $customer, $repairer] = $this->repairFixture('customer_pickup');

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/external-tracking", [
                'leg' => 'return',
                'carrier' => 'Lalamove',
                'tracking_number' => 'RETURN-123',
                'tracking_url' => 'https://tracker.example/RETURN-123',
            ])
            ->assertOk();

        $this->assertSame('RETURN-123', data_get($repair->fresh()->return_address, 'external_tracking.tracking_number'));
        $this->assertDatabaseMissing('shipments', [
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_return',
        ]);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/confirm-pickup")
            ->assertUnprocessable();

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/activate-pickup")
            ->assertOk()
            ->assertJsonPath('repair.status', 'shipped');

        $repair->refresh();
        $this->assertTrue((bool) $repair->pickup_enabled);
        $this->assertNotNull($repair->return_logistics_locked_at);
        $this->assertNotNull($repair->shipped_at);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/external-tracking", [
                'leg' => 'return',
                'carrier' => 'Grab Express',
                'tracking_number' => 'CHANGED',
            ])
            ->assertUnprocessable();

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/confirm-pickup")
            ->assertOk();

        $this->assertSame('picked_up', $repair->fresh()->status);
        $this->assertDatabaseCount('finance_invoices', 1);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/confirm-pickup")
            ->assertUnprocessable();

        $this->assertDatabaseCount('finance_invoices', 1);
    }

    public function test_walk_in_requires_staff_release_then_customer_confirmation(): void
    {
        [$repair, $customer, $repairer] = $this->repairFixture('walk_in');

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/confirm-pickup")
            ->assertUnprocessable();

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/activate-pickup")
            ->assertOk();

        $repair->refresh();
        $this->assertSame('ready_for_pickup', $repair->status);
        $this->assertNull($repair->picked_up_at);
        $this->assertTrue((bool) $repair->pickup_enabled);
        $this->assertNotNull($repair->return_logistics_locked_at);
        $this->assertDatabaseCount('finance_invoices', 0);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/confirm-pickup")
            ->assertOk();

        $this->assertSame('picked_up', $repair->fresh()->status);
        $this->assertDatabaseCount('finance_invoices', 1);
    }

    public function test_shop_delivery_requires_dispatcher_approved_proof_before_staff_handoff_and_customer_receipt(): void
    {
        [$repair, $customer, $repairer] = $this->repairFixture('shop_delivery', 'shipped');
        [$shipment, $leg] = $this->returnShipment($repair, 'active', 'delivered');
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'review_status' => 'pending',
        ]);

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/activate-pickup")
            ->assertUnprocessable();

        $proof->update(['review_status' => 'approved', 'reviewed_at' => now()]);
        $shipment->update(['status' => 'completed', 'completed_at' => now()]);

        $this->assertSame('shipped', $repair->fresh()->status);
        $this->assertFalse((bool) $repair->fresh()->pickup_enabled);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/confirm-pickup")
            ->assertUnprocessable();

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/activate-pickup")
            ->assertOk();

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/confirm-pickup")
            ->assertOk();

        $this->assertSame('picked_up', $repair->fresh()->status);
    }

    public function test_picked_up_repair_invoice_separates_locked_shop_owned_delivery_fees(): void
    {
        [$repair, $customer, $repairer] = $this->repairFixture('shop_delivery', 'shipped');
        $repair->update([
            'intake_delivery_method' => 'shop_pickup',
            'intake_delivery_fee' => 50,
            'intake_logistics_locked_at' => now(),
            'return_delivery_fee' => 70,
            'total_paid_amount' => 1120,
        ]);
        [$shipment, $leg] = $this->returnShipment($repair, 'completed', 'delivered');
        HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'handoff_type' => 'delivery',
            'review_status' => 'approved',
            'reviewed_at' => now(),
        ]);
        $shipment->update(['completed_at' => now()]);

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/activate-pickup")
            ->assertOk();
        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/confirm-pickup")
            ->assertOk();

        $invoice = Invoice::query()
            ->where('job_reference', $repair->request_id)
            ->firstOrFail();

        $this->assertSame('1120.00', $invoice->total);
        $this->assertSame(50.0, (float) data_get($invoice->meta, 'intake_delivery_fee'));
        $this->assertSame(70.0, (float) data_get($invoice->meta, 'return_delivery_fee'));
        $this->assertSame(120.0, (float) data_get($invoice->meta, 'shipping_fee'));
        $this->assertDatabaseHas('finance_invoice_items', [
            'invoice_id' => $invoice->id,
            'description' => 'Shop-owned intake pickup',
            'amount' => 50,
        ]);
        $this->assertDatabaseHas('finance_invoice_items', [
            'invoice_id' => $invoice->id,
            'description' => 'Shop-owned return delivery',
            'amount' => 70,
        ]);
    }

    public function test_wrong_customer_staff_shop_state_and_replay_do_not_mutate_the_repair(): void
    {
        [$repair, $customer, $repairer, $shop] = $this->repairFixture('customer_pickup');
        $otherCustomer = User::factory()->create();
        $otherRepairer = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'REPAIRER',
            'status' => 'active',
        ]);
        $otherShop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $this->actingAs($otherCustomer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/confirm-pickup")
            ->assertNotFound();
        $this->actingAs($otherRepairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/activate-pickup")
            ->assertForbidden();
        $this->actingAs($otherShop, 'shop_owner')
            ->postJson("/api/shop-owner/repairs/{$repair->id}/activate-pickup")
            ->assertForbidden();

        $repair->update(['status' => 'in_progress']);
        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/activate-pickup")
            ->assertUnprocessable();

        $repair->refresh();
        $this->assertSame('in_progress', $repair->status);
        $this->assertFalse((bool) $repair->pickup_enabled);
        $this->assertNull($repair->return_logistics_locked_at);
    }

    public function test_customer_can_save_intake_tracking_only_for_customer_delivery_before_lock(): void
    {
        [$repair, $customer] = $this->repairFixture('walk_in', 'pending');
        $repair->update([
            'intake_delivery_method' => 'customer_delivery',
            'intake_address' => ['version' => 'intake-v1', 'address_line' => '1 Customer Street'],
        ]);

        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/external-tracking", [
                'leg' => 'intake',
                'carrier' => 'J&T',
                'tracking_number' => 'INTAKE-123',
            ])
            ->assertOk();

        $repair->refresh();
        $this->assertSame('intake-v1', data_get($repair->intake_address, 'version'));
        $this->assertSame('INTAKE-123', data_get($repair->intake_address, 'external_tracking.tracking_number'));
        $this->assertDatabaseMissing('shipments', [
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_pickup',
        ]);

        $repair->update(['intake_logistics_locked_at' => now()]);
        $this->actingAs($customer, 'user')
            ->postJson("/api/customer/repairs/{$repair->id}/external-tracking", [
                'leg' => 'intake',
                'carrier' => 'J&T',
                'tracking_number' => 'CHANGED',
            ])
            ->assertUnprocessable();
    }

    public function test_job_order_payload_exposes_the_authoritative_return_handoff_gate(): void
    {
        [$repair, , $repairer] = $this->repairFixture('customer_pickup');

        $this->actingAs($repairer, 'user')
            ->getJson('/api/repairer/repairs')
            ->assertOk()
            ->assertJsonPath('data.0.return_handoff.method', 'customer_pickup')
            ->assertJsonPath('data.0.return_handoff.can_release', true)
            ->assertJsonPath('data.0.return_handoff.action_label', 'Confirm courier handoff');
    }

    private function repairFixture(string $method, string $status = 'ready_for_pickup'): array
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
            'status' => $status,
            'payment_status' => 'completed',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_completed_at' => now(),
            'delivery_method' => $method === 'walk_in' ? 'walk_in' : 'pickup',
            'intake_delivery_method' => 'walk_in',
            'return_delivery_method' => $method,
            'return_address' => $method === 'walk_in'
                ? null
                : ['version' => 'return-v1', 'address_line' => '1 Return Street'],
            'return_logistics_locked_at' => null,
            'pickup_enabled' => false,
            'total' => 1000,
            'final_total' => 1000,
            'pricing_breakdown' => ['base_total' => 1000, 'final_total' => 1000],
        ]);

        return [$repair, $customer, $repairer, $shop];
    }

    private function returnShipment(RepairRequest $repair, string $shipmentStatus, string $legStatus): array
    {
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $repair->shop_owner_id,
            'source_type' => 'repair_request',
            'source_id' => $repair->id,
            'purpose' => 'repair_return',
            'status' => $shipmentStatus,
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'sequence' => 1,
            'leg_type' => 'outbound',
            'status' => $legStatus,
        ]);

        return [$shipment, $leg];
    }
}
