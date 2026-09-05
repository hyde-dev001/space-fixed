<?php

declare(strict_types=1);

namespace Tests\Feature\Repair;

use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RepairOwnerProjectionResponseTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repair_workflow_status_keeps_legacy_keys_and_adds_owner_projection(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $staff = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'STAFF',
        ]);
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'awaiting_parts',
            'is_high_value' => true,
            'requires_owner_approval' => true,
            'payment_status' => 'pending',
            'pickup_enabled' => true,
            'owner_decision' => 'pending',
            'manager_decision' => 'approved',
        ]);

        $response = $this->actingAs($staff, 'user')
            ->getJson("/api/workflow/repairs/{$repair->id}/status")
            ->assertOk();

        $response->assertJsonPath('data.request_id', $repair->request_id);
        $response->assertJsonPath('data.status', 'awaiting_parts');
        $response->assertJsonPath('data.owner_projection.group', 'awaiting_parts');
        $response->assertJsonPath('data.owner_projection.raw_status', 'awaiting_parts');
        $response->assertJsonPath('data.owner_projection.decision_flags.is_high_value', true);
        $response->assertJsonPath('data.owner_projection.decision_flags.requires_owner_approval', true);
        $response->assertJsonPath('data.owner_projection.decision_flags.owner_decision', 'pending');
        $response->assertJsonPath('data.owner_projection.decision_flags.manager_decision', 'approved');
    }

    #[Test]
    public function repair_refund_payload_keeps_legacy_keys_and_adds_refund_owner_projection(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();
        $transaction = PosTransaction::create([
            'transaction_no' => 'POS-PROJECTION-001',
            'shop_owner_id' => $shop->id,
            'module_type' => 'repair',
            'module_reference_id' => 1,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        PosRefund::create([
            'refund_no' => 'RFD-PROJECTION-001',
            'shop_owner_id' => $shop->id,
            'source_transaction_id' => $transaction->id,
            'module_type' => 'repair',
            'module_reference_id' => 1,
            'workflow_source' => 'online_myrepair',
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'service_defect',
            'status' => 'requested',
            'finance_status' => 'pending',
            'shop_owner_status' => 'pending',
            'repairer_status' => 'approved',
            'requested_by' => $customer->id,
            'requested_at' => now(),
        ]);

        $response = $this->actingAs($shop, 'shop_owner')
            ->getJson('/api/shop-owner/repair-refunds')
            ->assertOk();

        $response->assertJsonPath('data.0.status', 'Pending');
        $response->assertJsonPath('data.0.rawStatus', 'requested');
        $response->assertJsonPath('data.0.owner_projection.case_state', 'requested');
        $response->assertJsonPath('data.0.owner_projection.waiting_on', 'owner');
        $response->assertJsonPath('data.0.owner_projection.owner_action_required', true);
    }
}
