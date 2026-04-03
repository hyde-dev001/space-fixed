<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairPosPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function pos_ledger_tables_exist_for_repair_module(): void
    {
        $this->assertTrue(Schema::hasTable('pos_transactions'));
        $this->assertTrue(Schema::hasTable('pos_payment_lines'));
        $this->assertTrue(Schema::hasTable('pos_receipts'));
        $this->assertTrue(Schema::hasTable('pos_refunds'));
        $this->assertTrue(Schema::hasTable('pos_refund_lines'));

        $this->assertTrue(Schema::hasColumn('repair_requests', 'payment_policy_snapshot'));
        $this->assertTrue(Schema::hasColumn('repair_requests', 'payment_status_derived'));
        $this->assertTrue(Schema::hasColumn('repair_requests', 'total_paid_amount'));
        $this->assertTrue(Schema::hasColumn('repair_requests', 'total_refunded_amount'));
        $this->assertTrue(Schema::hasColumn('repair_requests', 'latest_pos_transaction_id'));
    }

    #[Test]
    public function repair_request_exposes_pos_relationships(): void
    {
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-REL-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000009',
            'shoe_type' => 'Sneakers',
            'description' => 'POS relation test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
        ]);

        $this->assertTrue(method_exists($repair, 'posTransactions'));
        $this->assertTrue(method_exists($repair, 'latestPosTransaction'));
    }

    #[Test]
    public function pos_payment_records_deposit_and_updates_repair_derived_status(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000001',
            'shoe_type' => 'Sneakers',
            'description' => 'POS payment test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $repair->refresh();
        $this->assertSame('partially_paid', (string) $repair->payment_status_derived);
        $this->assertSame('500.00', number_format((float) $repair->total_paid_amount, 2, '.', ''));
    }

    #[Test]
    public function manual_mark_paid_endpoint_is_blocked_and_returns_pos_instruction(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-002',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000002',
            'shoe_type' => 'Sneakers',
            'description' => 'Manual payment block test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1500,
            'final_total' => 1500,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
        ]);

        $response = $this->actingAs($actor, 'user')
            ->postJson("/api/repairer/repairs/{$repair->id}/mark-paid-in-shop");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'POS_REQUIRED');
    }

    #[Test]
    public function successful_checkout_creates_receipt_record_with_print_and_digital_payloads(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-003',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000011',
            'shoe_type' => 'Sneakers',
            'description' => 'Receipt generation test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseCount('pos_receipts', 1);
        $receipt = \App\Models\PosReceipt::query()->firstOrFail();
        $this->assertNotEmpty($receipt->receipt_no);
        $this->assertIsArray($receipt->print_payload);
        $this->assertIsArray($receipt->digital_payload);
    }

    #[Test]
    public function deposit_then_balance_transitions_to_paid(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-004',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000021',
            'shoe_type' => 'Sneakers',
            'description' => 'Deposit then balance lifecycle test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ])->assertOk();

        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'balance',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 500],
            ],
        ])->assertOk();

        $repair->refresh();
        $this->assertSame('paid', (string) $repair->payment_status_derived);
        $this->assertSame('1000.00', number_format((float) $repair->total_paid_amount, 2, '.', ''));
    }

    #[Test]
    public function full_upfront_transitions_to_paid_after_single_checkout(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-005',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000022',
            'shoe_type' => 'Sneakers',
            'description' => 'Full upfront lifecycle test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1200,
            'final_total' => 1200,
            'status' => 'pending',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'pending',
        ]);

        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'full',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 1200],
            ],
        ])->assertOk();

        $repair->refresh();
        $this->assertSame('paid', (string) $repair->payment_status_derived);
        $this->assertSame('1200.00', number_format((float) $repair->total_paid_amount, 2, '.', ''));
    }
}
