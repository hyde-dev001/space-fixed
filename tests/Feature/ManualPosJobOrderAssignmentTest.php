<?php

namespace Tests\Feature;

use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManualPosJobOrderAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function seedRepairerRole(): void
    {
        Role::findOrCreate('Repairer', 'user');
    }

    private function createActiveAssignedRepair(int $shopOwnerId, int $repairerId, string $requestId): void
    {
        RepairRequest::create([
            'request_id' => $requestId,
            'customer_name' => 'Load Test',
            'email' => strtolower($requestId) . '@example.test',
            'phone' => '09170000123',
            'shoe_type' => 'Sneakers',
            'description' => 'Load fixture',
            'shop_owner_id' => $shopOwnerId,
            'assigned_repairer_id' => $repairerId,
            'status' => 'in_progress',
            'images' => [],
            'total' => 500,
            'final_total' => 500,
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'unpaid',
            'payment_status_derived' => 'unpaid',
            'total_paid_amount' => 0,
            'total_refunded_amount' => 0,
        ]);
    }

    #[Test]
    public function repairer_actor_self_assigns_manual_pos_checkout(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);

        $this->seedRepairerRole();
        $repairer->assignRole('Repairer');

        $response = $this->actingAs($repairer, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'idempotency_key' => 'manual-pos-self-assign-001',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Self Assign Test',
            'walk_in_phone' => '09170000011',
            'manual_repair_subtotal' => 600,
            'manual_service_summary' => 'Manual POS self assign',
            'manual_payment_policy' => 'deposit_50',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 300],
            ],
        ]);

        $response->assertOk();

        $tx = PosTransaction::findOrFail((int) $response->json('transaction_id'));
        $repair = RepairRequest::findOrFail((int) $tx->module_reference_id);

        $this->assertSame($repairer->id, (int) $repair->assigned_repairer_id);
        $this->assertSame('assigned_to_repairer', (string) $repair->status);
    }

    #[Test]
    public function non_repairer_actor_assigns_to_least_loaded_repairer(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $cashier = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $repairerA = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $repairerB = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);

        $this->seedRepairerRole();
        $repairerA->assignRole('Repairer');
        $repairerB->assignRole('Repairer');

        $this->createActiveAssignedRepair((int) $shopOwner->id, (int) $repairerA->id, 'REP-WL-0001');

        $response = $this->actingAs($cashier, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'idempotency_key' => 'manual-pos-auto-assign-001',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Auto Assign Test',
            'walk_in_phone' => '09170000022',
            'manual_repair_subtotal' => 700,
            'manual_service_summary' => 'Manual POS auto assign',
            'manual_payment_policy' => 'deposit_50',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 350],
            ],
        ]);

        $response->assertOk();

        $tx = PosTransaction::findOrFail((int) $response->json('transaction_id'));
        $repair = RepairRequest::findOrFail((int) $tx->module_reference_id);

        $this->assertSame((int) $repairerB->id, (int) $repair->assigned_repairer_id);
        $this->assertSame('assigned_to_repairer', (string) $repair->status);
    }

    #[Test]
    public function over_limit_fallback_still_assigns_least_loaded_repairer(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'repair_workload_limit' => 1,
        ]);

        $cashier = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $repairerA = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $repairerB = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);

        $this->seedRepairerRole();
        $repairerA->assignRole('Repairer');
        $repairerB->assignRole('Repairer');

        $this->createActiveAssignedRepair((int) $shopOwner->id, (int) $repairerA->id, 'REP-LIMIT-0001');
        $this->createActiveAssignedRepair((int) $shopOwner->id, (int) $repairerB->id, 'REP-LIMIT-0002');

        $response = $this->actingAs($cashier, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'idempotency_key' => 'manual-pos-overlimit-001',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Overlimit Test',
            'walk_in_phone' => '09170000033',
            'manual_repair_subtotal' => 800,
            'manual_service_summary' => 'Manual POS overlimit fallback',
            'manual_payment_policy' => 'deposit_50',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 400],
            ],
        ]);

        $response->assertOk();

        $tx = PosTransaction::findOrFail((int) $response->json('transaction_id'));
        $repair = RepairRequest::findOrFail((int) $tx->module_reference_id);

        $this->assertContains((int) $repair->assigned_repairer_id, [(int) $repairerA->id, (int) $repairerB->id]);
        $this->assertSame('assigned_to_repairer', (string) $repair->status);
    }

    #[Test]
    public function shop_owner_workload_includes_assigned_rep_pos_records(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);

        $this->seedRepairerRole();
        $repairer->assignRole('Repairer');

        $repair = RepairRequest::create([
            'request_id' => 'REP-POS-20260406-0100',
            'customer_name' => 'Workload Inclusion Test',
            'email' => 'workload@example.test',
            'phone' => '09170001000',
            'shoe_type' => 'Walk-in',
            'description' => 'workload inclusion',
            'shop_owner_id' => $shopOwner->id,
            'assigned_repairer_id' => $repairer->id,
            'manual_pos_queue_enabled' => true,
            'status' => 'assigned_to_repairer',
            'images' => [],
            'total' => 500,
            'final_total' => 500,
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'unpaid',
            'payment_status_derived' => 'unpaid',
            'total_paid_amount' => 0,
            'total_refunded_amount' => 0,
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')->getJson('/api/shop-owner/repairs');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $repair->id, $ids);
    }

    #[Test]
    public function shop_owner_actor_auto_assigns_manual_pos_checkout_to_repairer(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);

        $this->seedRepairerRole();
        $repairer->assignRole('Repairer');

        $response = $this->actingAs($shopOwner, 'shop_owner')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'idempotency_key' => 'manual-pos-shop-owner-assign-001',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Shop Owner Assign Test',
            'walk_in_phone' => '09170000044',
            'manual_repair_subtotal' => 900,
            'manual_service_summary' => 'Manual POS shop owner assign',
            'manual_payment_policy' => 'deposit_50',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 450],
            ],
        ]);

        $response->assertOk();

        $tx = PosTransaction::findOrFail((int) $response->json('transaction_id'));
        $repair = RepairRequest::findOrFail((int) $tx->module_reference_id);

        $this->assertSame((int) $repairer->id, (int) $repair->assigned_repairer_id);
        $this->assertSame('assigned_to_repairer', (string) $repair->status);
    }
}
