<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairPosRefundFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cancel_refund_uses_aggregate_paid_amount_not_latest_transaction_only(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'status' => 'for_release',
            'payment_status' => 'paid',
            'payment_policy_snapshot' => 'deposit_50',
            'total_paid_amount' => 1120,
        ]);

        \App\Models\PosTransaction::create([
            'transaction_no' => 'POS-TDD-RFD-AGG-001',
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

        $latest = \App\Models\PosTransaction::create([
            'transaction_no' => 'POS-TDD-RFD-AGG-002',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'balance',
            'subtotal' => 500,
            'tax_amount' => 60,
            'discount_amount' => 0,
            'total_amount' => 560,
            'paid_amount' => 560,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $repair->update(['latest_pos_transaction_id' => $latest->id]);

        $this->actingAs($customer, 'user')
            ->postJson('/api/customer/repairs/' . $repair->id . '/cancel')
            ->assertOk();

        $this->assertDatabaseHas('pos_refunds', [
            'source_transaction_id' => $latest->id,
            'module_reference_id' => $repair->id,
            'requested_amount' => 1120,
            'status' => 'requested',
        ]);
    }

    private function createRepairRequest(
        \App\Models\ShopOwner $shopOwner,
        ?\App\Models\User $customer = null,
        array $overrides = []
    ): \App\Models\RepairRequest {
        $customer ??= \App\Models\User::factory()->create();

        return \App\Models\RepairRequest::create(array_merge([
            'request_id' => 'REP-TDD-RFD-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000123',
            'shoe_type' => 'Sneakers',
            'description' => 'Refund lifecycle test repair',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'images' => json_encode([]),
            'total' => 1000,
            'final_total' => 1000,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
        ], $overrides));
    }

    #[Test]
    public function cancelling_a_paid_repair_auto_creates_pending_refund_request(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'status' => 'pending',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status_derived' => 'partially_paid',
            'total_paid_amount' => 500,
        ]);

        $source = \App\Models\PosTransaction::create([
            'transaction_no' => 'POS-TDD-RFD-CANCEL-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'deposit',
            'subtotal' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $repair->update(['latest_pos_transaction_id' => $source->id]);

        $response = $this->actingAs($customer, 'user')->postJson("/api/customer/repairs/{$repair->id}/cancel");

        $response->assertOk()->assertJsonPath('success', true);

        $repair->refresh();
        $this->assertSame('cancelled', (string) $repair->status);

        $this->assertDatabaseHas('pos_refunds', [
            'source_transaction_id' => $source->id,
            'module_reference_id' => $repair->id,
            'status' => 'requested',
            'request_type' => 'full',
            'reason_code' => 'customer_cancelled_repair',
        ]);
    }

    #[Test]
    public function partial_refund_must_not_exceed_remaining_refundable_amount(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $repair = $this->createRepairRequest($shopOwner, null, [
            'shop_owner_id' => $shopOwner->id,
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status_derived' => 'paid',
            'final_total' => 1000,
            'total_paid_amount' => 1000,
        ]);

        $source = \App\Models\PosTransaction::create([
            'transaction_no' => 'POS-TDD-RFD-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Walk-in Customer',
            'walk_in_phone' => '09170000003',
            'walk_in_email' => 'walkin@example.com',
            'due_type' => 'full',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        \App\Models\PosRefund::create([
            'refund_no' => 'RFD-TDD-001',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'request_type' => 'partial',
            'requested_amount' => 300,
            'approved_amount' => 300,
            'reason_code' => 'initial_partial',
            'status' => 'succeeded',
            'requested_at' => now(),
            'approved_at' => now(),
            'executed_at' => now(),
        ]);

        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $response = $this->actingAs($actor, 'user')->postJson('/api/repair-pos/refunds', [
            'source_transaction_id' => $source->id,
            'request_type' => 'partial',
            'requested_amount' => 800,
            'reason_code' => 'over_refund_attempt',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['requested_amount']);
    }

    #[Test]
    public function refund_queue_endpoint_returns_pending_refund_requests_for_repair_module(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $repair = $this->createRepairRequest($shopOwner, null, [
            'shop_owner_id' => $shopOwner->id,
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status_derived' => 'paid',
            'total_paid_amount' => 500,
        ]);

        $source = \App\Models\PosTransaction::create([
            'transaction_no' => 'POS-TDD-RFD-QUEUE-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Queue Customer',
            'walk_in_phone' => '09170000088',
            'due_type' => 'deposit',
            'subtotal' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $refund = \App\Models\PosRefund::create([
            'refund_no' => 'RFD-TDD-QUEUE-001',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'queue_test',
            'status' => 'requested',
            'requested_at' => now(),
        ]);

        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $response = $this->actingAs($actor, 'user')->getJson('/api/repair-pos/refunds/queue');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'id' => $refund->id,
                'status' => 'requested',
            ]);
    }

    #[Test]
    public function partial_refund_transitions_to_partially_refunded_and_keeps_remaining_balance(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status_derived' => 'paid',
            'total_paid_amount' => 1000,
            'final_total' => 1000,
        ]);

        $source = \App\Models\PosTransaction::create([
            'transaction_no' => 'POS-TDD-RFD-LIFE-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $refund = \App\Models\PosRefund::create([
            'refund_no' => 'RFD-TDD-LIFE-001',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'request_type' => 'partial',
            'requested_amount' => 300,
            'approved_amount' => 300,
            'reason_code' => 'lifecycle_partial',
            'status' => 'approved',
            'requested_by' => $actor->id,
            'approved_by' => $actor->id,
            'requested_at' => now(),
            'approved_at' => now(),
        ]);

        app(\App\Services\RepairPosRefundService::class)->execute($refund, $actor->id);

        $source->refresh();
        $repair->refresh();
        $refund->refresh();

        $this->assertSame('succeeded', (string) $refund->status);
        $this->assertSame('partially_refunded', (string) $source->status);
        $this->assertSame('partially_refunded', (string) $repair->payment_status_derived);
        $this->assertSame('300.00', number_format((float) $repair->total_refunded_amount, 2, '.', ''));
    }

    #[Test]
    public function full_refund_transitions_to_refunded(): void
    {
        $shopOwner = \App\Models\ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var \App\Models\User $customer */
        $customer = \App\Models\User::factory()->create();
        /** @var \App\Models\User $actor */
        $actor = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = $this->createRepairRequest($shopOwner, $customer, [
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status_derived' => 'paid',
            'total_paid_amount' => 1000,
            'final_total' => 1000,
        ]);

        $source = \App\Models\PosTransaction::create([
            'transaction_no' => 'POS-TDD-RFD-LIFE-002',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $refund = \App\Models\PosRefund::create([
            'refund_no' => 'RFD-TDD-LIFE-002',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'request_type' => 'full',
            'requested_amount' => 1000,
            'approved_amount' => 1000,
            'reason_code' => 'lifecycle_full',
            'status' => 'approved',
            'requested_by' => $actor->id,
            'approved_by' => $actor->id,
            'requested_at' => now(),
            'approved_at' => now(),
        ]);

        app(\App\Services\RepairPosRefundService::class)->execute($refund, $actor->id);

        $source->refresh();
        $repair->refresh();
        $refund->refresh();

        $this->assertSame('succeeded', (string) $refund->status);
        $this->assertSame('refunded', (string) $source->status);
        $this->assertSame('refunded', (string) $repair->payment_status_derived);
        $this->assertSame('1000.00', number_format((float) $repair->total_refunded_amount, 2, '.', ''));
    }
}
