<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\PosReceipt;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RepairRefundSourceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repair_refund_service_rejects_a_non_repair_source_transaction(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();

        $source = PosTransaction::create([
            'transaction_no' => 'POS-REPAIR-SOURCE-BOUNDARY-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'retail',
            'module_reference_id' => 999,
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

        $this->expectException(ValidationException::class);

        app(\App\Services\RepairPosRefundService::class)->requestRefund(
            source: $source,
            payload: [
                'request_type' => 'full',
                'requested_amount' => 500,
                'reason_code' => 'wrong_source',
                'workflow_source' => 'pos',
            ],
            actorId: $customer->id,
        );

        $this->assertDatabaseMissing('pos_refunds', [
            'source_transaction_id' => $source->id,
        ]);
    }

    #[Test]
    public function repair_refund_service_rejects_a_source_linked_to_another_shop_repair(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $otherShopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();
        $repair = $this->createRepair($otherShopOwner, $customer, null, 'CROSS-SHOP-SOURCE');

        $source = PosTransaction::create([
            'transaction_no' => 'POS-CROSS-SHOP-SOURCE-001',
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

        $this->actingAs($customer, 'user')
            ->postJson('/api/repair-pos/refunds', [
                'source_transaction_id' => $source->id,
                'request_type' => 'full',
                'requested_amount' => 500,
                'reason_code' => 'cross_shop_source',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('pos_refunds', [
            'source_transaction_id' => $source->id,
        ]);
    }

    #[Test]
    public function finance_repair_refund_actions_reject_retail_and_cross_shop_refunds(): void
    {
        Permission::findOrCreate('access-refund-approval', 'user');

        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $otherShopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $finance = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $finance->givePermissionTo('access-refund-approval');
        $customer = User::factory()->create();

        $retailSource = PosTransaction::create([
            'transaction_no' => 'POS-RETAIL-REFUND-BOUNDARY-001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'retail',
            'module_reference_id' => 999,
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
        $retailRefund = PosRefund::create([
            'refund_no' => 'RFD-RETAIL-REFUND-BOUNDARY-001',
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $retailSource->id,
            'module_type' => 'retail',
            'module_reference_id' => 999,
            'workflow_source' => 'retail_order',
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'retail_return',
            'status' => 'requested',
            'finance_status' => 'pending',
            'shop_owner_status' => 'pending',
            'requires_owner_approval' => false,
        ]);

        $otherRepair = $this->createRepair($otherShopOwner, $customer, null, 'OTHER-ACTION');
        $otherRefund = $this->createRefund(
            $this->createSource($otherShopOwner, $customer, $otherRepair, 'OTHER-ACTION'),
            $otherRepair,
            $customer,
            'OTHER-ACTION',
            ['workflow_source' => 'pos'],
        );

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/repair-refunds/{$retailRefund->id}/approve", [])
            ->assertForbidden();

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/repair-refunds/{$otherRefund->id}/approve", [])
            ->assertForbidden();

        $this->assertSame('requested', (string) $retailRefund->fresh()->status);
        $this->assertSame('requested', (string) $otherRefund->fresh()->status);
    }

    #[Test]
    public function repairer_refund_queue_and_review_are_scoped_to_the_assigned_repairer_and_shop(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $otherShopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();

        $repairer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $peerRepairer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $otherShopRepairer = User::factory()->create([
            'shop_owner_id' => $otherShopOwner->id,
            'status' => 'active',
        ]);

        $assignedRepair = $this->createRepair($shopOwner, $customer, $repairer, 'ASSIGNED');
        $peerRepair = $this->createRepair($shopOwner, $customer, $peerRepairer, 'PEER');
        $otherShopRepair = $this->createRepair($otherShopOwner, $customer, $otherShopRepairer, 'OTHER');

        $assignedRefund = $this->createRefund(
            $this->createSource($shopOwner, $customer, $assignedRepair, 'ASSIGNED'),
            $assignedRepair,
            $customer,
            'ASSIGNED',
        );
        $peerRefund = $this->createRefund(
            $this->createSource($shopOwner, $customer, $peerRepair, 'PEER'),
            $peerRepair,
            $customer,
            'PEER',
        );
        $otherShopRefund = $this->createRefund(
            $this->createSource($otherShopOwner, $customer, $otherShopRepair, 'OTHER'),
            $otherShopRepair,
            $customer,
            'OTHER',
        );

        $queueResponse = $this->actingAs($repairer, 'user')
            ->getJson('/api/repairer/refunds');

        $queueResponse->assertOk();
        $queueIds = collect($queueResponse->json('data'))->pluck('id')->all();

        $this->assertSame([$assignedRefund->id], $queueIds);
        $this->assertNotContains($peerRefund->id, $queueIds);
        $this->assertNotContains($otherShopRefund->id, $queueIds);

        $this->actingAs($repairer, 'user')
            ->postJson("/api/repairer/refunds/{$peerRefund->id}/approve", [
                'assessment_note' => 'Not assigned to this repairer.',
                'approved_amount' => 500,
            ])
            ->assertForbidden();

        $this->assertSame('pending', (string) $peerRefund->fresh()->repairer_status);
    }

    #[Test]
    public function finance_repair_refund_payload_identifies_repair_source_without_retail_return_status(): void
    {
        Permission::findOrCreate('access-refund-approval', 'user');

        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $finance = User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $finance->givePermissionTo('access-refund-approval');
        $customer = User::factory()->create();
        $repair = $this->createRepair($shopOwner, $customer, null, 'SERIALIZER');
        $source = $this->createSource($shopOwner, $customer, $repair, 'SERIALIZER');

        PosReceipt::create([
            'pos_transaction_id' => $source->id,
            'receipt_no' => 'RCPT-REPAIR-SOURCE-001',
            'issued_at' => now(),
            'print_payload' => [],
            'digital_payload' => [],
        ]);

        $refund = $this->createRefund($source, $repair, $customer, 'SERIALIZER', [
            'workflow_source' => 'pos',
        ]);

        $response = $this->actingAs($finance, 'user')
            ->getJson('/api/finance/repair-refunds?status=all');

        $response->assertOk();
        $payload = collect($response->json('data'))->firstWhere('id', $refund->id);

        $this->assertNotNull($payload);
        $this->assertSame('repair', $payload['refundType']);
        $this->assertSame('repair', $payload['sourceType']);
        $this->assertSame($repair->request_id, $payload['repairNumber']);
        $this->assertSame('RCPT-REPAIR-SOURCE-001', $payload['receiptNumber']);
        $this->assertNull($payload['returnStatus']);
    }

    #[Test]
    public function customer_repair_refund_response_uses_the_canonical_shop_owner_status(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();
        $repair = $this->createRepair($shopOwner, $customer, null, 'CUSTOMER-STATUS');
        $source = $this->createSource($shopOwner, $customer, $repair, 'CUSTOMER-STATUS');
        $refund = $this->createRefund($source, $repair, $customer, 'CUSTOMER-STATUS', [
            'status' => 'approved',
            'finance_status' => 'approved',
            'shop_owner_status' => 'approved',
            'requires_owner_approval' => false,
        ]);

        $response = $this->actingAs($customer, 'user')
            ->getJson('/api/repair-pos/refunds/mine');

        $response->assertOk();
        $payload = collect($response->json('data'))->firstWhere('id', $refund->id);

        $this->assertNotNull($payload);
        $this->assertSame('approved', $payload['owner_status']);
        $this->assertSame('approved', $payload['shop_owner_status']);
    }

    #[Test]
    public function online_repair_refund_request_notifies_only_the_assigned_repairer_for_repairer_review(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();
        $repairer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $unassignedStaff = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'status' => 'active',
        ]);
        $repair = $this->createRepair($shopOwner, $customer, $repairer, 'NOTIFICATION');
        $source = $this->createSource($shopOwner, $customer, $repair, 'NOTIFICATION');

        $refund = app(\App\Services\RepairPosRefundService::class)->requestRefund(
            source: $source,
            payload: [
                'request_type' => 'full',
                'requested_amount' => 500,
                'reason_code' => 'service_defect',
                'workflow_source' => 'online_myrepair',
            ],
            actorId: $customer->id,
        );

        $repairerNotification = Notification::query()
            ->where('user_id', $repairer->id)
            ->where('type', NotificationType::REFUND_REQUEST->value)
            ->first();

        $this->assertNotNull($repairerNotification);
        $this->assertSame('/erp/staff/job-orders-repair', $repairerNotification->action_url);
        $this->assertSame($refund->id, data_get($repairerNotification->data, 'refund_id'));
        $this->assertFalse(Notification::query()
            ->where('user_id', $unassignedStaff->id)
            ->where('type', NotificationType::REFUND_REQUEST->value)
            ->exists());
    }

    private function createRepair(
        ShopOwner $shopOwner,
        User $customer,
        ?User $repairer,
        string $suffix,
    ): RepairRequest {
        return RepairRequest::create([
            'request_id' => 'REP-REFUND-' . $suffix,
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09170000000',
            'shoe_type' => 'Sneakers',
            'description' => 'Repair refund source-boundary test.',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'assigned_repairer_id' => $repairer?->id,
            'assigned_at' => $repairer ? now() : null,
            'intake_delivery_method' => 'walk_in',
            'delivery_method' => 'walk_in',
            'images' => json_encode([]),
            'total' => 500,
            'final_total' => 500,
            'status' => 'pending',
            'payment_policy' => 'full_upfront',
            'payment_policy_snapshot' => 'full_upfront',
            'payment_status' => 'paid',
            'payment_status_derived' => 'paid',
            'total_paid_amount' => 500,
        ]);
    }

    private function createSource(
        ShopOwner $shopOwner,
        User $customer,
        RepairRequest $repair,
        string $suffix,
    ): PosTransaction {
        return PosTransaction::create([
            'transaction_no' => 'POS-REFUND-' . $suffix,
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
    }

    private function createRefund(
        PosTransaction $source,
        RepairRequest $repair,
        User $customer,
        string $suffix,
        array $overrides = [],
    ): PosRefund {
        return PosRefund::create(array_merge([
            'refund_no' => 'RFD-REFUND-' . $suffix,
            'shop_owner_id' => $repair->shop_owner_id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'workflow_source' => 'online_myrepair',
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'service_defect',
            'status' => 'requested',
            'finance_status' => 'pending',
            'shop_owner_status' => 'pending',
            'repairer_status' => 'pending',
            'requires_owner_approval' => true,
            'requested_by' => $customer->id,
            'requested_at' => now(),
        ], $overrides));
    }
}
