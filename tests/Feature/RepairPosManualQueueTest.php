<?php

namespace Tests\Feature;

use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairPosManualQueueTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repair_request_defaults_manual_pos_queue_to_false(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);

        $repair = $this->createRepairRequest([
            'shop_owner_id' => $shopOwner->id,
            'request_id' => 'REP-POS-DEFAULT-0001',
        ]);

        $this->assertFalse((bool) $repair->manual_pos_queue_enabled);
    }

    #[Test]
    public function manual_pos_checkout_marks_repair_request_as_queue_enabled(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var User $user */
        $user = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $response = $this->actingAs($user, 'user')->postJson('/api/repair-pos/checkout', [
            'repair_request_id' => null,
            'due_type' => 'deposit',
            'idempotency_key' => 'manual-pos-queue-001',
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Queue Test',
            'walk_in_phone' => '09170000001',
            'manual_repair_subtotal' => 500,
            'manual_service_summary' => 'Manual queue creation test',
            'manual_payment_policy' => 'deposit_50',
            'payment_lines' => [
                ['tender_type' => 'cash', 'amount' => 250],
            ],
        ]);

        $response->assertOk();

        $tx = PosTransaction::findOrFail((int) $response->json('transaction_id'));
        $repair = RepairRequest::findOrFail((int) $tx->module_reference_id);

        $this->assertTrue((bool) $repair->manual_pos_queue_enabled);
    }

    #[Test]
    public function manual_queue_list_returns_only_queue_enabled_records(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var User $user */
        $user = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $included = $this->createRepairRequest([
            'shop_owner_id' => $shopOwner->id,
            'request_id' => 'REP-POS-20260406-0001',
            'manual_pos_queue_enabled' => true,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'paid',
            'final_total' => 500,
            'total_paid_amount' => 250,
        ]);

        $this->createRepairRequest([
            'shop_owner_id' => $shopOwner->id,
            'request_id' => 'REP-POS-20260406-0002',
            'manual_pos_queue_enabled' => false,
            'status' => 'pending',
        ]);

        $otherShopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $this->createRepairRequest([
            'shop_owner_id' => $otherShopOwner->id,
            'request_id' => 'REP-POS-20260406-0003',
            'manual_pos_queue_enabled' => true,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user, 'user')->getJson('/api/repair-pos/manual-queue');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame((int) $included->id, (int) $response->json('data.0.id'));
    }

    #[Test]
    public function manual_queue_status_transition_is_restricted(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        /** @var User $user */
        $user = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $repair = $this->createRepairRequest([
            'shop_owner_id' => $shopOwner->id,
            'request_id' => 'REP-POS-20260406-0004',
            'manual_pos_queue_enabled' => true,
            'status' => 'pending',
        ]);

        $invalid = $this->actingAs($user, 'user')->patchJson("/api/repair-pos/manual-queue/{$repair->id}/status", [
            'status' => 'in_progress',
        ]);

        $invalid->assertStatus(422)
            ->assertJsonPath('success', false);

        $valid = $this->actingAs($user, 'user')->patchJson("/api/repair-pos/manual-queue/{$repair->id}/status", [
            'status' => 'received',
        ]);

        $valid->assertOk()
            ->assertJsonPath('success', true);

        $repair->refresh();
        $this->assertSame('received', (string) $repair->status);
        $this->assertNotNull($repair->received_at);
    }

    private function createRepairRequest(array $overrides = []): RepairRequest
    {
        $defaults = [
            'request_id' => 'REP-POS-TEST-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'customer_name' => 'Walk-in Test',
            'email' => 'walkin-' . random_int(1000, 9999) . '@example.test',
            'phone' => '09170000000',
            'shoe_type' => 'Sneakers',
            'description' => 'Manual queue test fixture',
            'shop_owner_id' => ShopOwner::factory()->approved()->create(['business_type' => 'repair'])->id,
            'images' => json_encode([]),
            'total' => 500,
            'final_total' => 500,
            'status' => 'pending',
            'payment_policy' => 'deposit_50',
            'payment_policy_snapshot' => 'deposit_50',
            'payment_status' => 'unpaid',
            'payment_status_derived' => 'unpaid',
            'total_paid_amount' => 0,
            'total_refunded_amount' => 0,
        ];

        return RepairRequest::create(array_merge($defaults, $overrides));
    }
}
