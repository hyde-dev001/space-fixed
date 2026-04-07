<?php

namespace Tests\Feature;

use App\Models\PosPaymentLine;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\PaymongoRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RepairMixedRefundSplitSettlementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function refund_schema_supports_split_legs_and_proof_fields(): void
    {
        $this->assertTrue(Schema::hasTable('pos_refund_legs'));

        $this->assertTrue(Schema::hasColumns('pos_refund_legs', [
            'pos_refund_id',
            'leg_type',
            'requested_amount',
            'approved_amount',
            'status',
            'source_transaction_id',
            'source_receipt_no',
        ]));

        $this->assertTrue(Schema::hasColumns('pos_refunds', [
            'preferred_return_channel',
            'preferred_return_account_name',
            'preferred_return_account_ref',
            'customer_payout_consent',
            'execution_channel',
            'execution_reference',
            'execution_amount',
            'execution_proof_urls',
        ]));
    }

    #[Test]
    public function myrepair_submit_creates_parent_refund_with_gateway_and_pos_legs_for_mixed_payment(): void
    {
        /** @var User $customer */
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'total_paid_amount' => 1000,
            'total_refunded_amount' => 0,
            'latest_pos_transaction_id' => null,
        ]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-MIX-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 400,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 400,
            'paid_amount' => 400,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'paymongo_wallet',
            'provider_reference' => 'pmw_123',
            'amount' => 150,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'cash',
            'amount' => 250,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($customer, 'user')->postJson("/api/customer/repairs/{$repair->id}/refunds", [
            'source_transaction_id' => $source->id,
            'request_type' => 'full',
            'requested_amount' => 400,
            'reason_code' => 'mixed_payment_refund',
            'reason_notes' => 'Customer requested mixed payment refund.',
            'evidence' => [['type' => 'photo', 'url' => 'https://evidence.local/a.jpg']],
            'preferred_return_channel' => 'gcash',
            'preferred_return_account_name' => 'Juan Dela Cruz',
            'preferred_return_account_ref' => '09171234567',
            'customer_payout_consent' => true,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $refund = PosRefund::query()->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing(['gateway', 'pos_manual'], $refund->legs()->pluck('leg_type')->all());
    }

    #[Test]
    public function myrepair_submit_accepts_string_customer_payout_consent_in_form_request(): void
    {
        /** @var User $customer */
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'total_paid_amount' => 1000,
            'total_refunded_amount' => 0,
            'latest_pos_transaction_id' => null,
        ]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-MIX-001-FORM',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 400,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 400,
            'paid_amount' => 400,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'paymongo_wallet',
            'provider_reference' => 'pmw_123_form',
            'amount' => 150,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'cash',
            'amount' => 250,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($customer, 'user')->post("/api/customer/repairs/{$repair->id}/refunds", [
            'source_transaction_id' => $source->id,
            'request_type' => 'full',
            'requested_amount' => 400,
            'reason_code' => 'mixed_payment_refund',
            'reason_notes' => 'Customer requested mixed payment refund (form submit).',
            'evidence' => [['type' => 'photo', 'url' => 'https://evidence.local/a-form.jpg']],
            'preferred_return_channel' => 'gcash',
            'preferred_return_account_name' => 'Juan Dela Cruz',
            'preferred_return_account_ref' => '09171234567',
            'customer_payout_consent' => 'true',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    #[Test]
    public function myrepair_submit_allows_repair_wide_refund_when_online_payment_not_in_pos_ledger(): void
    {
        /** @var User $customer */
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'total' => 2441.07,
            'final_total' => 2441.07,
            'pricing_breakdown' => [
                'tax_mode' => 'legacy_add_on',
            ],
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'completed',
            'total_paid_amount' => 874.50,
            'total_refunded_amount' => 0,
            'paymongo_payment_id' => 'pay_repair_balance_123',
            'latest_pos_transaction_id' => null,
        ]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-MIX-ONLINE-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'deposit',
            'subtotal' => 780.80,
            'tax_amount' => 93.70,
            'discount_amount' => 0,
            'total_amount' => 874.50,
            'paid_amount' => 874.50,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'cash',
            'amount' => 874.50,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($customer, 'user')->postJson("/api/customer/repairs/{$repair->id}/refunds", [
            'source_transaction_id' => $source->id,
            'request_type' => 'full',
            'requested_amount' => 2734,
            'reason_code' => 'service_issue_after_pickup',
            'reason_notes' => 'Customer requested full mixed refund after pickup.',
            'evidence' => [['type' => 'photo', 'url' => 'https://evidence.local/mixed-online.jpg']],
            'preferred_return_channel' => 'gcash',
            'preferred_return_account_name' => 'Juan Dela Cruz',
            'preferred_return_account_ref' => '09171234567',
            'customer_payout_consent' => true,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $refund = PosRefund::query()->latest('id')->firstOrFail()->load('legs');
        $this->assertSame('online_myrepair', (string) $refund->workflow_source);
        $this->assertSame('pay_repair_balance_123', (string) $refund->paymongo_payment_id);

        $gatewayLeg = $refund->legs->firstWhere('leg_type', 'gateway');
        $manualLeg = $refund->legs->firstWhere('leg_type', 'pos_manual');

        $this->assertNotNull($gatewayLeg);
        $this->assertNotNull($manualLeg);
        $this->assertEqualsWithDelta(1859.50, (float) $gatewayLeg->requested_amount, 0.01);
        $this->assertEqualsWithDelta(874.50, (float) $manualLeg->requested_amount, 0.01);
    }

    #[Test]
    public function myrepairs_payload_marks_pure_online_refund_as_original_method_only(): void
    {
        /** @var User $customer */
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'total' => 500,
            'final_total' => 500,
            'pricing_breakdown' => [
                'tax_mode' => 'vat_inclusive',
            ],
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'completed',
            'total_paid_amount' => 500,
            'total_refunded_amount' => 0,
        ]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-ONLINE-ONLY-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 446.43,
            'tax_amount' => 53.57,
            'discount_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'paymongo_wallet',
            'provider_reference' => 'pmw_online_only_001',
            'amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $repair->update(['latest_pos_transaction_id' => $source->id]);

        $response = $this->actingAs($customer, 'user')->getJson('/api/customer/repairs');
        $response->assertOk()->assertJsonPath('success', true);

        $data = collect($response->json('data'))->firstWhere('id', $repair->id);

        $this->assertNotNull($data);
        $this->assertSame('pure_online', (string) ($data['refund_payment_type'] ?? ''));
        $this->assertFalse((bool) ($data['refund_requires_payout_destination'] ?? true));
        $this->assertTrue((bool) ($data['refund_original_method_only'] ?? false));
    }

    #[Test]
    public function myrepair_submit_ignores_manual_payout_details_for_pure_online_payment(): void
    {
        /** @var User $customer */
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'total' => 500,
            'final_total' => 500,
            'pricing_breakdown' => [
                'tax_mode' => 'vat_inclusive',
            ],
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'completed',
            'total_paid_amount' => 500,
            'total_refunded_amount' => 0,
            'paymongo_payment_id' => 'pay_online_only_001',
        ]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-ONLINE-ONLY-002',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 446.43,
            'tax_amount' => 53.57,
            'discount_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'paymongo_card',
            'provider_reference' => 'pmc_online_only_002',
            'amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $repair->update(['latest_pos_transaction_id' => $source->id]);

        $response = $this->actingAs($customer, 'user')->postJson("/api/customer/repairs/{$repair->id}/refunds", [
            'source_transaction_id' => $source->id,
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'online_payment_refund_request',
            'reason_notes' => 'Pure online payment refund request.',
            'evidence' => [['type' => 'photo', 'url' => 'https://evidence.local/online-only.jpg']],
            'preferred_return_channel' => 'gcash',
            'preferred_return_account_name' => 'Manual Destination Name',
            'preferred_return_account_ref' => '09171234567',
            'customer_payout_consent' => true,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $refund = PosRefund::query()->latest('id')->firstOrFail()->load('legs');

        $this->assertNull($refund->preferred_return_channel);
        $this->assertNull($refund->preferred_return_account_name);
        $this->assertNull($refund->preferred_return_account_ref);
        $this->assertFalse((bool) $refund->customer_payout_consent);

        $this->assertTrue($refund->legs->contains(fn ($leg) => (string) $leg->leg_type === 'gateway'));
        $this->assertFalse($refund->legs->contains(fn ($leg) => (string) $leg->leg_type === 'pos_manual'));
    }

    #[Test]
    public function myrepair_submit_pure_online_refund_can_fallback_source_transaction_when_latest_pos_is_missing(): void
    {
        /** @var User $customer */
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'total' => 500,
            'final_total' => 500,
            'pricing_breakdown' => [
                'tax_mode' => 'vat_inclusive',
            ],
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'completed',
            'total_paid_amount' => 500,
            'total_refunded_amount' => 0,
            'paymongo_payment_id' => 'pay_online_only_fallback_001',
            'latest_pos_transaction_id' => null,
        ]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-ONLINE-FALLBACK-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 446.43,
            'tax_amount' => 53.57,
            'discount_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'paymongo_wallet',
            'provider_reference' => 'pmw_online_fallback_001',
            'amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($customer, 'user')->postJson("/api/customer/repairs/{$repair->id}/refunds", [
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'online_payment_refund_request',
            'reason_notes' => 'Pure online payment refund request without explicit source transaction id.',
            'evidence' => [['type' => 'photo', 'url' => 'https://evidence.local/online-fallback.jpg']],
            'preferred_return_channel' => 'gcash',
            'preferred_return_account_name' => 'Manual Destination Name',
            'preferred_return_account_ref' => '09171234567',
            'customer_payout_consent' => true,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $refund = PosRefund::query()->latest('id')->firstOrFail();

        $this->assertSame($source->id, (int) $refund->source_transaction_id);
        $this->assertSame('online_myrepair', (string) $refund->workflow_source);
    }

    #[Test]
    public function myrepair_submit_pure_online_refund_backfills_source_transaction_when_pos_ledger_is_empty(): void
    {
        /** @var User $customer */
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'total' => 950,
            'final_total' => 950,
            'pricing_breakdown' => [
                'tax_mode' => 'vat_inclusive',
            ],
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'completed',
            'total_paid_amount' => 950,
            'total_refunded_amount' => 0,
            'paymongo_payment_id' => 'pay_legacy_online_only_003',
            'latest_pos_transaction_id' => null,
        ]);

        $response = $this->actingAs($customer, 'user')->postJson("/api/customer/repairs/{$repair->id}/refunds", [
            'request_type' => 'full',
            'requested_amount' => 950,
            'reason_code' => 'online_payment_refund_request',
            'reason_notes' => 'Pure online payment refund request with no POS ledger rows.',
            'evidence' => [['type' => 'photo', 'url' => 'https://evidence.local/online-backfill-ledger.jpg']],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $refund = PosRefund::query()->latest('id')->firstOrFail();
        $source = PosTransaction::query()->findOrFail((int) $refund->source_transaction_id);

        $this->assertSame('repair', (string) $source->module_type);
        $this->assertSame($repair->id, (int) $source->module_reference_id);
        $this->assertSame('paid', (string) $source->status);

        $line = PosPaymentLine::query()->where('pos_transaction_id', $source->id)->latest('id')->first();
        $this->assertNotNull($line);
        $this->assertSame('pay_legacy_online_only_003', (string) ($line->provider_reference ?? ''));
    }

    #[Test]
    public function myrepairs_payload_marks_walk_in_only_payment_as_manual_only_refund_profile(): void
    {
        /** @var User $customer */
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'total' => 874.50,
            'final_total' => 874.50,
            'pricing_breakdown' => [
                'tax_mode' => 'vat_inclusive',
            ],
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'completed',
            'total_paid_amount' => 874.50,
            'total_refunded_amount' => 0,
        ]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-WALKIN-ONLY-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 780.80,
            'tax_amount' => 93.70,
            'discount_amount' => 0,
            'total_amount' => 874.50,
            'paid_amount' => 874.50,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'cash',
            'amount' => 874.50,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $repair->update(['latest_pos_transaction_id' => $source->id]);

        $response = $this->actingAs($customer, 'user')->getJson('/api/customer/repairs');
        $response->assertOk()->assertJsonPath('success', true);

        $data = collect($response->json('data'))->firstWhere('id', $repair->id);

        $this->assertNotNull($data);
        $this->assertSame('manual_only', (string) ($data['refund_payment_type'] ?? ''));
        $this->assertTrue((bool) ($data['refund_requires_payout_destination'] ?? false));
        $this->assertFalse((bool) ($data['refund_original_method_only'] ?? true));
    }

    #[Test]
    public function myrepairs_payload_treats_manual_gcash_reference_as_manual_only_refund_profile(): void
    {
        /** @var User $customer */
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'total' => 950,
            'final_total' => 950,
            'pricing_breakdown' => [
                'tax_mode' => 'vat_inclusive',
            ],
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'completed',
            'total_paid_amount' => 950,
            'total_refunded_amount' => 0,
            'paymongo_payment_id' => null,
        ]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-WALKIN-GCASH-MANUAL-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 848.21,
            'tax_amount' => 101.79,
            'discount_amount' => 0,
            'total_amount' => 950,
            'paid_amount' => 950,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'paymongo_wallet',
            'provider_reference' => '09123456789',
            'amount' => 950,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $repair->update(['latest_pos_transaction_id' => $source->id]);

        $response = $this->actingAs($customer, 'user')->getJson('/api/customer/repairs');
        $response->assertOk()->assertJsonPath('success', true);

        $data = collect($response->json('data'))->firstWhere('id', $repair->id);

        $this->assertNotNull($data);
        $this->assertSame('manual_only', (string) ($data['refund_payment_type'] ?? ''));
        $this->assertTrue((bool) ($data['refund_requires_payout_destination'] ?? false));
        $this->assertFalse((bool) ($data['refund_original_method_only'] ?? true));
    }

    #[Test]
    public function myrepair_submit_for_walk_in_only_payment_forces_gcash_destination_channel(): void
    {
        /** @var User $customer */
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'total' => 874.50,
            'final_total' => 874.50,
            'pricing_breakdown' => [
                'tax_mode' => 'vat_inclusive',
            ],
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'completed',
            'total_paid_amount' => 874.50,
            'total_refunded_amount' => 0,
        ]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-WALKIN-ONLY-002',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 780.80,
            'tax_amount' => 93.70,
            'discount_amount' => 0,
            'total_amount' => 874.50,
            'paid_amount' => 874.50,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'cash',
            'amount' => 874.50,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $repair->update(['latest_pos_transaction_id' => $source->id]);

        $response = $this->actingAs($customer, 'user')->postJson("/api/customer/repairs/{$repair->id}/refunds", [
            'source_transaction_id' => $source->id,
            'request_type' => 'full',
            'requested_amount' => 874.50,
            'reason_code' => 'walk_in_refund_request',
            'reason_notes' => 'Walk-in only payment refund request.',
            'evidence' => [['type' => 'photo', 'url' => 'https://evidence.local/walkin-only.jpg']],
            'preferred_return_channel' => 'gcash',
            'preferred_return_account_name' => 'Should be ignored',
            'preferred_return_account_ref' => '09170000000',
            'customer_payout_consent' => true,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $refund = PosRefund::query()->latest('id')->firstOrFail();
        $this->assertSame('gcash', (string) $refund->preferred_return_channel);
        $this->assertSame('Should be ignored', (string) $refund->preferred_return_account_name);
        $this->assertSame('09170000000', (string) $refund->preferred_return_account_ref);
        $this->assertTrue((bool) $refund->customer_payout_consent);
    }

    #[Test]
    public function finance_execute_rejects_pos_manual_leg_without_execution_proof(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var User $finance */
        $finance = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        Permission::findOrCreate('access-refund-approval', 'user');
        $finance->givePermissionTo('access-refund-approval');

        $source = PosTransaction::create([
            'transaction_no' => 'POS-MIX-EXEC-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => 999,
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Walk In',
            'walk_in_phone' => '09170000111',
            'due_type' => 'full',
            'subtotal' => 300,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 300,
            'paid_amount' => 300,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $refund = PosRefund::create([
            'refund_no' => 'RFD-MIX-EXEC-001',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => 999,
            'status' => 'approved',
            'finance_status' => 'approved',
            'shop_owner_status' => 'skipped',
            'request_type' => 'full',
            'requested_amount' => 300,
            'approved_amount' => 300,
            'reason_code' => 'mixed_payment_refund',
            'requested_at' => now(),
        ]);

        $refund->legs()->create([
            'leg_type' => 'pos_manual',
            'requested_amount' => 300,
            'approved_amount' => 300,
            'status' => 'approved',
        ]);

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/repair-refunds/{$refund->id}/execute", [
                'execution_mode' => 'manual',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['execution_channel', 'execution_reference', 'execution_proof_urls']);
    }

    #[Test]
    public function finance_execute_gateway_does_not_require_execution_proof_urls(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var User $finance */
        $finance = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        Permission::findOrCreate('access-refund-approval', 'user');
        $finance->givePermissionTo('access-refund-approval');

        $source = PosTransaction::create([
            'transaction_no' => 'POS-MIX-EXEC-GW-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => 1001,
            'customer_type' => 'registered',
            'customer_id' => $finance->id,
            'due_type' => 'full',
            'subtotal' => 300,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 300,
            'paid_amount' => 300,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'paymongo_card',
            'provider_reference' => 'pmc_exec_gateway_001',
            'amount' => 300,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $refund = PosRefund::create([
            'refund_no' => 'RFD-MIX-EXEC-GW-001',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => 1001,
            'status' => 'approved',
            'finance_status' => 'approved',
            'shop_owner_status' => 'skipped',
            'request_type' => 'full',
            'requested_amount' => 300,
            'approved_amount' => 300,
            'reason_code' => 'pure_online_refund',
            'requested_at' => now(),
        ]);

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/repair-refunds/{$refund->id}/execute", [
                'execution_mode' => 'gateway',
                'execution_proof_urls' => [],
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function finance_execute_gateway_clamps_amount_to_gateway_paid_cap(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'paymongo_secret_key' => 'sk_test_clamp_amount',
        ]);
        /** @var User $finance */
        $finance = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        Permission::findOrCreate('access-refund-approval', 'user');
        $finance->givePermissionTo('access-refund-approval');

        $source = PosTransaction::create([
            'transaction_no' => 'POS-MIX-EXEC-GW-CLAMP-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => 1002,
            'customer_type' => 'registered',
            'customer_id' => $finance->id,
            'due_type' => 'full',
            'subtotal' => 475,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 475,
            'paid_amount' => 475,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'paymongo_wallet',
            'provider_reference' => 'pmw_exec_gateway_clamp_001',
            'amount' => 475,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $refund = PosRefund::create([
            'refund_no' => 'RFD-MIX-EXEC-GW-CLAMP-001',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => 1002,
            'status' => 'approved',
            'finance_status' => 'approved',
            'shop_owner_status' => 'skipped',
            'request_type' => 'full',
            'requested_amount' => 950,
            'approved_amount' => 950,
            'reason_code' => 'pure_online_refund',
            'paymongo_payment_id' => 'pay_exec_gateway_clamp_001',
            'requested_at' => now(),
        ]);

        $capturedAmount = null;
        app()->instance(PaymongoRefundService::class, new class($capturedAmount) extends PaymongoRefundService {
            public ?int $captured = null;

            public function __construct(?int &$captured)
            {
                $this->captured = null;
            }

            public function getPaymentAmountInCentavos(string $secretKey, string $paymentId): ?int
            {
                return 47500;
            }

            public function createRefund(string $secretKey, string $paymentId, int $amountInCentavos, string $reason = 'requested_by_customer'): array
            {
                $this->captured = $amountInCentavos;
                return [
                    'success' => true,
                    'message' => 'ok',
                    'status' => 'succeeded',
                    'refund_id' => 're_mock_1',
                ];
            }
        });

        $response = $this->actingAs($finance, 'user')
            ->postJson("/api/finance/repair-refunds/{$refund->id}/execute", [
                'execution_mode' => 'gateway',
            ]);

        $response->assertStatus(200)->assertJsonPath('success', true);

        $updated = $refund->fresh();
        $this->assertSame('succeeded', (string) $updated->status);
        $this->assertEqualsWithDelta(475.00, (float) $updated->approved_amount, 0.01);
    }

    #[Test]
    public function customer_refund_list_returns_redacted_execution_reference_only(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var User $customer */
        $customer = User::factory()->create();

        $repair = $this->createRepairRequest($shopOwner, $customer);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-MIX-VIS-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
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
            'refund_no' => 'RFD-MIX-VIS-001',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'request_type' => 'full',
            'requested_amount' => 500,
            'approved_amount' => 500,
            'reason_code' => 'mixed_payment_refund',
            'status' => 'succeeded',
            'execution_channel' => 'gcash',
            'execution_reference' => 'AUTH-1234567890',
            'execution_proof_urls' => ['https://proof.local/rfd-1.png'],
            'requested_at' => now(),
            'approved_at' => now(),
            'executed_at' => now(),
        ]);

        $response = $this->actingAs($customer, 'user')->getJson('/api/repair-pos/refunds/mine');

        $response->assertOk()
            ->assertJsonMissing(['execution_reference' => 'AUTH-1234567890'])
            ->assertJsonPath('data.0.execution_reference_masked', 'AUTH-******7890');
    }

    #[Test]
    public function split_leg_creation_applies_only_when_feature_flag_enabled(): void
    {
        config()->set('orders.repair_split_refund_enabled', false);

        /** @var User $customer */
        $customer = User::factory()->create();
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'total_paid_amount' => 1000,
            'total_refunded_amount' => 0,
            'latest_pos_transaction_id' => null,
        ]);

        $source = PosTransaction::create([
            'transaction_no' => 'POS-MIX-FLAG-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 400,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 400,
            'paid_amount' => 400,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'paymongo_wallet',
            'provider_reference' => 'pmw_flag_123',
            'amount' => 150,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        PosPaymentLine::create([
            'pos_transaction_id' => $source->id,
            'tender_type' => 'cash',
            'amount' => 250,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($customer, 'user')->postJson("/api/customer/repairs/{$repair->id}/refunds", [
            'source_transaction_id' => $source->id,
            'request_type' => 'full',
            'requested_amount' => 400,
            'reason_code' => 'mixed_payment_refund',
            'reason_notes' => 'Customer requested mixed payment refund.',
            'evidence' => [['type' => 'photo', 'url' => 'https://evidence.local/a.jpg']],
            'preferred_return_channel' => 'gcash',
            'preferred_return_account_name' => 'Juan Dela Cruz',
            'preferred_return_account_ref' => '09171234567',
            'customer_payout_consent' => true,
        ]);

        $response->assertOk();

        $refund = PosRefund::query()->latest('id')->firstOrFail();
        $this->assertCount(0, $refund->legs);
    }

    private function createRepairRequest(ShopOwner $shopOwner, User $customer, array $overrides = []): RepairRequest
    {
        return RepairRequest::create(array_merge([
            'request_id' => 'REP-MIX-RFD-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000123',
            'shoe_type' => 'Sneakers',
            'description' => 'Mixed refund split test repair',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'for_release',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status_derived' => 'paid',
            'total_paid_amount' => 1000,
            'total_refunded_amount' => 0,
        ], $overrides));
    }
}
