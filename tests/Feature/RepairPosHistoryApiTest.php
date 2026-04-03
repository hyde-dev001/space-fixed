<?php

namespace Tests\Feature;

use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairPosHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repairer_can_view_repair_pos_history_but_cannot_execute_refund(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $customer = User::factory()->create();

        $repair = RepairRequest::create([
            'request_id' => 'REP-TDD-HIST-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09176666666',
            'shoe_type' => 'Sneakers',
            'description' => 'History API test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'ready_for_pickup',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'paid',
        ]);

        $transaction = PosTransaction::create([
            'transaction_no' => 'POS-TDD-HIST-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'deposit',
            'subtotal' => 500,
            'tax_amount' => 60,
            'discount_amount' => 0,
            'total_amount' => 560,
            'paid_amount' => 560,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->actingAs($repairer, 'user')
            ->getJson('/api/repair-pos/transactions?repair_request_id=' . $repair->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $refund = PosRefund::create([
            'refund_no' => 'RFD-TDD-HIST-001',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $transaction->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'request_type' => 'full',
            'requested_amount' => 560,
            'approved_amount' => 560,
            'reason_code' => 'history_test',
            'status' => 'approved',
            'requested_by' => $repairer->id,
            'requested_at' => now(),
            'approved_at' => now(),
        ]);

        $this->actingAs($repairer, 'user')
            ->postJson('/api/repair-pos/refunds/' . $refund->id . '/execute', ['execution_mode' => 'manual'])
            ->assertStatus(403);
    }
}
