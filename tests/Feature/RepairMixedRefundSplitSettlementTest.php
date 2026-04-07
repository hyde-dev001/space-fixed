<?php

namespace Tests\Feature;

use App\Models\PosPaymentLine;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
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
