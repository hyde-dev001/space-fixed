<?php

namespace Tests\Feature;

use App\Models\PosRefund;
use App\Models\PosPaymentLine;
use App\Models\PosReceipt;
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
    public function repairer_can_request_larger_history_page_size(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $customer = User::factory()->create();

        $repair = RepairRequest::create([
            'request_id' => 'REP-TDD-HIST-PAGE-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09175555555',
            'shoe_type' => 'Sneakers',
            'description' => 'History page size test',
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

        foreach (range(1, 25) as $index) {
            PosTransaction::create([
                'transaction_no' => sprintf('POS-TDD-HIST-PAGE-%03d', $index),
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
        }

        $this->actingAs($repairer, 'user')
            ->getJson('/api/repair-pos/transactions?per_page=25')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.per_page', 25)
            ->assertJsonCount(25, 'data.data');
    }

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

    #[Test]
    public function history_endpoint_backfills_missing_receipt_for_existing_repair_transaction(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $customer = User::factory()->create();

        $repair = RepairRequest::create([
            'request_id' => 'REP-TDD-HIST-BKF-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09178888888',
            'shoe_type' => 'Sneakers',
            'description' => 'History receipt backfill test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1200,
            'final_total' => 1200,
            'status' => 'ready_for_pickup',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'paid',
        ]);

        $transaction = PosTransaction::create([
            'transaction_no' => 'POS-TDD-HIST-BKF-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 1071.43,
            'tax_amount' => 128.57,
            'discount_amount' => 0,
            'total_amount' => 1200,
            'paid_amount' => 1200,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $transaction->id,
            'tender_type' => 'cash',
            'amount' => 1200,
            'status' => 'paid',
            'verification_status' => 'verified',
            'paid_at' => now(),
        ]);

        $this->assertDatabaseMissing('pos_receipts', [
            'pos_transaction_id' => $transaction->id,
        ]);

        $this->actingAs($repairer, 'user')
            ->getJson('/api/repair-pos/transactions?repair_request_id=' . $repair->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.data.0.id', $transaction->id)
            ->assertJsonPath('data.data.0.receipt.pos_transaction_id', $transaction->id);

        $this->assertDatabaseHas('pos_receipts', [
            'pos_transaction_id' => $transaction->id,
        ]);
    }

    #[Test]
    public function receipt_endpoint_generates_missing_receipt_record_on_demand(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repairer = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $customer = User::factory()->create();

        $repair = RepairRequest::create([
            'request_id' => 'REP-TDD-HIST-BKF-002',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09179999999',
            'shoe_type' => 'Sneakers',
            'description' => 'Receipt endpoint backfill test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 700,
            'final_total' => 700,
            'status' => 'ready_for_pickup',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'paid',
        ]);

        $transaction = PosTransaction::create([
            'transaction_no' => 'POS-TDD-HIST-BKF-002',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'deposit',
            'subtotal' => 312.5,
            'tax_amount' => 37.5,
            'discount_amount' => 0,
            'total_amount' => 350,
            'paid_amount' => 350,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $transaction->id,
            'tender_type' => 'paymongo_wallet',
            'provider_reference' => 'pmw_test_123456',
            'amount' => 350,
            'status' => 'paid',
            'verification_status' => 'verified',
            'paid_at' => now(),
        ]);

        $this->assertDatabaseMissing('pos_receipts', [
            'pos_transaction_id' => $transaction->id,
        ]);

        $this->actingAs($repairer, 'user')
            ->getJson('/api/repair-pos/transactions/' . $transaction->id . '/receipt')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pos_transaction_id', $transaction->id);

        $this->assertDatabaseHas('pos_receipts', [
            'pos_transaction_id' => $transaction->id,
        ]);

        $this->assertSame(1, PosReceipt::query()->where('pos_transaction_id', $transaction->id)->count());
    }
}
