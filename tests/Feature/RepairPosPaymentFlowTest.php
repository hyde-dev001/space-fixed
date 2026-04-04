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
    public function walk_in_checkout_without_repair_request_creates_manual_repair_reference_and_receipt(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Manual Walk-in Customer',
            'walk_in_phone' => '09171234567',
            'idempotency_key' => 'manual-walkin-deposit-001',
            'manual_repair_subtotal' => 599,
            'manual_service_summary' => 'Starter Clean Package (2 services)',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 335.44],
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $transactionId = (int) $response->json('transaction_id');
        $this->assertGreaterThan(0, $transactionId);

        $transaction = \App\Models\PosTransaction::query()->findOrFail($transactionId);
        $this->assertSame('repair', (string) $transaction->module_type);
        $this->assertSame('walk_in', (string) $transaction->customer_type);
        $this->assertSame('deposit', (string) $transaction->due_type);
        $this->assertSame('335.44', number_format((float) $transaction->total_amount, 2, '.', ''));

        $repair = \App\Models\RepairRequest::query()->findOrFail((int) $transaction->module_reference_id);
        $this->assertSame((int) $shopOwner->id, (int) $repair->shop_owner_id);
        $this->assertSame('Manual Walk-in Customer', (string) $repair->customer_name);
        $this->assertSame('deposit_50', (string) $repair->payment_policy_snapshot);
        $this->assertSame('paid', (string) $repair->payment_status_derived);

        $receipt = \App\Models\PosReceipt::query()->where('pos_transaction_id', $transaction->id)->first();
        $this->assertNotNull($receipt);
    }

    #[Test]
    public function checkout_replay_returns_existing_transaction_and_does_not_duplicate_phase_charge(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-IDEM-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000111',
            'shoe_type' => 'Sneakers',
            'description' => 'Idempotency replay test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'ready_for_pickup',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        $payload = [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Replay Test',
            'idempotency_key' => 'idem-phase-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 560],
            ],
        ];

        $first = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', $payload);
        $second = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', $payload);

        $first->assertStatus(200);
        $second->assertStatus(200)->assertJsonPath('meta.idempotency_replay', true);

        $this->assertSame(1, \App\Models\PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->where('due_type', 'deposit')
            ->count());
    }

    #[Test]
    public function receipt_includes_registered_customer_identity_fields(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create([
            'first_name' => 'Jamie',
            'last_name' => 'Santos',
            'email' => 'jamie@example.com',
            'shop_owner_id' => null,
        ]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-RCPT-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09175555555',
            'shoe_type' => 'Sneakers',
            'description' => 'Receipt customer identity test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'ready_for_pickup',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'pending',
        ]);

        $transaction = \App\Models\PosTransaction::create([
            'transaction_no' => 'POS-TDD-RCPT-001',
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

        app(\App\Services\RepairPosReceiptService::class)->issue($transaction->fresh('paymentLines'));

        $receipt = \App\Models\PosReceipt::query()->where('pos_transaction_id', $transaction->id)->firstOrFail();
        $payload = $receipt->print_payload;

        $this->assertSame('Jamie Santos', data_get($payload, 'customer.name'));
        $this->assertSame('jamie@example.com', data_get($payload, 'customer.email'));
    }

    #[Test]
    public function payment_and_refund_transitions_keep_payment_status_canonical_and_in_sync(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-CANON-001',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09174444444',
            'shoe_type' => 'Sneakers',
            'description' => 'Canonical status sync test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'ready_for_pickup',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'unpaid',
            'payment_status_derived' => 'unpaid',
        ]);

        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'canon-sync-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 560],
            ],
        ])->assertOk();

        $repair->refresh();

        $this->assertSame((string) $repair->payment_status_derived, (string) $repair->payment_status);
        $this->assertContains((string) $repair->payment_status, ['unpaid', 'partially_paid', 'paid', 'partially_refunded', 'refunded']);
    }

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
            'idempotency_key' => 'tdd-deposit-derived-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 560],
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $repair->refresh();
        $this->assertSame('paid', (string) $repair->payment_status_derived);
        $this->assertSame('560.00', number_format((float) $repair->total_paid_amount, 2, '.', ''));
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
            'idempotency_key' => 'tdd-receipt-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 560],
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
            'idempotency_key' => 'tdd-deposit-balance-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 560],
            ],
        ])->assertOk();

        $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'balance',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-deposit-balance-002',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 560],
            ],
        ])->assertOk();

        $repair->refresh();
        $this->assertSame('completed', (string) $repair->payment_status_derived);
        $this->assertSame('1120.00', number_format((float) $repair->total_paid_amount, 2, '.', ''));
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
            'idempotency_key' => 'tdd-full-upfront-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 1344],
            ],
        ])->assertOk();

        $repair->refresh();
        $this->assertSame('completed', (string) $repair->payment_status_derived);
        $this->assertSame('1344.00', number_format((float) $repair->total_paid_amount, 2, '.', ''));
    }

    #[Test]
    public function non_cash_checkout_requires_provider_reference(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-006',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000023',
            'shoe_type' => 'Sneakers',
            'description' => 'Non-cash ref required test',
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
            'idempotency_key' => 'tdd-noncash-ref-required-001',
            'payment_lines' => [
                ['tender_type' => 'paymongo_wallet', 'amount' => 560, 'provider_reference' => null],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_lines.0.provider_reference']);
    }

    #[Test]
    public function non_cash_checkout_accepts_provider_reference_and_persists_it(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-007',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000024',
            'shoe_type' => 'Sneakers',
            'description' => 'Non-cash ref success test',
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
            'idempotency_key' => 'tdd-noncash-ref-success-001',
            'payment_lines' => [
                ['tender_type' => 'paymongo_card', 'amount' => 560, 'provider_reference' => 'AUTH-REF-12345'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('pos_payment_lines', [
            'tender_type' => 'paymongo_card',
            'provider_reference' => 'AUTH-REF-12345',
            'status' => 'paid',
        ]);
    }

    #[Test]
    public function full_upfront_policy_rejects_non_full_due_type(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-008',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000025',
            'shoe_type' => 'Sneakers',
            'description' => 'Policy/due type guard test (full_upfront)',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'pending',
        ]);

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'deposit',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-policy-fullupfront-invalid-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 1120],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['due_type']);
    }

    #[Test]
    public function deposit_policy_rejects_full_due_type(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-009',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000026',
            'shoe_type' => 'Sneakers',
            'description' => 'Policy/due type guard test (deposit_50)',
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
            'due_type' => 'full',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-policy-deposit-invalid-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 560],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['due_type']);
    }

    #[Test]
    public function deposit_checkout_updates_payment_status_to_paid(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-010',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000027',
            'shoe_type' => 'Sneakers',
            'description' => 'Payment status sync (deposit)',
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
            'idempotency_key' => 'tdd-status-deposit-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 560],
            ],
        ])->assertOk();

        $repair->refresh();
        $this->assertSame('paid', (string) $repair->payment_status);
    }

    #[Test]
    public function full_checkout_updates_payment_status_to_paid_for_full_upfront_policy(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-011',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000028',
            'shoe_type' => 'Sneakers',
            'description' => 'Payment status sync (full upfront)',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
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
            'idempotency_key' => 'tdd-status-fullupfront-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 1120],
            ],
        ])->assertOk();

        $repair->refresh();
        $this->assertSame('completed', (string) $repair->payment_status);
    }

    #[Test]
    public function shop_actor_can_approve_and_execute_manual_repair_refund(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $shopActor */
        $shopActor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $managerRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'user']);
        $shopActor->assignRole($managerRole);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-012',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000029',
            'shoe_type' => 'Sneakers',
            'description' => 'Refund lifecycle test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'picked_up',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'paid',
            'payment_status_derived' => 'paid',
        ]);

        $checkout = $this->actingAs($shopActor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'full',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-refund-lifecycle-001',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 1120],
            ],
        ])->assertOk();

        $transactionId = (int) $checkout->json('transaction_id');

        $refundRequest = $this->actingAs($customer, 'user')->postJson('/api/repair-pos/refunds', [
            'source_transaction_id' => $transactionId,
            'request_type' => 'full',
            'requested_amount' => 1120,
            'reason_code' => 'customer_refund_request',
            'reason_notes' => 'Refund requested from test',
        ])->assertOk();

        $refundId = (int) $refundRequest->json('refund_id');

        $this->actingAs($shopActor, 'user')
            ->postJson("/api/repair-pos/refunds/{$refundId}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->actingAs($shopActor, 'user')
            ->postJson("/api/repair-pos/refunds/{$refundId}/execute", ['execution_mode' => 'manual'])
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded');

        $this->assertDatabaseHas('pos_refunds', [
            'id' => $refundId,
            'status' => 'succeeded',
            'execution_mode' => 'manual',
        ]);

        $repair->refresh();
        $this->assertSame('1120.00', number_format((float) $repair->total_refunded_amount, 2, '.', ''));
        $this->assertSame('refunded', (string) $repair->payment_status_derived);
    }

    #[Test]
    public function customer_can_view_own_repair_refunds(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $otherCustomer */
        $otherCustomer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $shopActor */
        $shopActor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-013',
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000030',
            'shoe_type' => 'Sneakers',
            'description' => 'Refund visibility test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'picked_up',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'paid',
        ]);

        $otherRepair = \App\Models\RepairRequest::create([
            'request_id' => 'REP-TDD-014',
            'customer_name' => $otherCustomer->name,
            'email' => $otherCustomer->email,
            'phone' => '09170000031',
            'shoe_type' => 'Sneakers',
            'description' => 'Other refund visibility test',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $otherCustomer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'picked_up',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'paid',
        ]);

        $tx1 = $this->actingAs($shopActor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $repair->id,
            'due_type' => 'full',
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'idempotency_key' => 'tdd-refund-visibility-001',
            'payment_lines' => [['tender_type' => 'cash', 'amount' => 1120]],
        ])->assertOk();

        $tx2 = $this->actingAs($shopActor, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => $otherRepair->id,
            'due_type' => 'full',
            'customer_type' => 'registered',
            'customer_id' => $otherCustomer->id,
            'idempotency_key' => 'tdd-refund-visibility-002',
            'payment_lines' => [['tender_type' => 'cash', 'amount' => 1120]],
        ])->assertOk();

        $this->actingAs($customer, 'user')->postJson('/api/repair-pos/refunds', [
            'source_transaction_id' => (int) $tx1->json('transaction_id'),
            'request_type' => 'full',
            'requested_amount' => 1120,
            'reason_code' => 'customer_refund_request',
        ])->assertOk();

        $this->actingAs($otherCustomer, 'user')->postJson('/api/repair-pos/refunds', [
            'source_transaction_id' => (int) $tx2->json('transaction_id'),
            'request_type' => 'full',
            'requested_amount' => 1120,
            'reason_code' => 'customer_refund_request',
        ])->assertOk();

        $response = $this->actingAs($customer, 'user')->getJson('/api/repair-pos/refunds/mine');
        $response->assertOk()->assertJsonPath('success', true);

        $refunds = $response->json('data');
        $this->assertIsArray($refunds);
        $this->assertCount(1, $refunds);
        $this->assertSame((int) $repair->id, (int) ($refunds[0]['module_reference_id'] ?? 0));
    }
}
