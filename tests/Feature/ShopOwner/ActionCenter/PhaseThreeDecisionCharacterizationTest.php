<?php

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\Finance\Expense;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\ProcurementSettings;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseRequest;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThreeDecisionCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_refund_queue_keeps_current_tenant_and_stage_predicates(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);
        $otherShopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'retail',
        ]);
        $this->enableRefundOwnerApproval($shopOwner);

        $actionable = $this->createOrderRefund($shopOwner, [
            'amount' => 1000,
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
        ]);
        $otherShopRefund = $this->createOrderRefund($otherShopOwner, [
            'amount' => 1000,
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
        ]);

        $this->assertOrderRefundQueueContainsOnly($shopOwner, $actionable->id);
        $this->assertNotSame($actionable->id, $otherShopRefund->id);

        $actionable->update(['finance_status' => 'pending']);
        $this->assertOrderRefundQueueContainsOnly($shopOwner);

        $actionable->update(['finance_status' => 'approved_initial', 'shop_owner_status' => 'approved']);
        $this->assertOrderRefundQueueContainsOnly($shopOwner);

        $actionable->update(['shop_owner_status' => 'pending', 'status' => 'rejected']);
        $this->assertOrderRefundQueueContainsOnly($shopOwner);

        $actionable->update(['status' => 'requested', 'flow_type' => 'cancel_auto']);
        $this->assertOrderRefundQueueContainsOnly($shopOwner);

        $this->assertSame('/shop-owner/refund-approvals', route('shop-owner.refund-approvals', [], false));
    }

    public function test_individual_order_refund_keeps_pending_finance_stage_in_the_existing_queue(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);
        $this->enableRefundOwnerApproval($shopOwner);

        $refund = $this->createOrderRefund($shopOwner, [
            'amount' => 1000,
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'pending',
        ]);

        $this->assertOrderRefundQueueContainsOnly($shopOwner, $refund->id);
    }

    public function test_repair_refund_queue_keeps_tenant_scope_and_exposes_the_current_finance_stage(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'repair',
        ]);
        $otherShopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'repair',
        ]);
        $this->enableRefundOwnerApproval($shopOwner);

        $actionable = $this->createRepairRefund($shopOwner, [
            'finance_status' => 'approved_initial',
            'shop_owner_status' => 'pending',
        ]);
        $financePending = $this->createRepairRefund($shopOwner, [
            'finance_status' => 'pending',
            'shop_owner_status' => 'pending',
        ]);
        $otherShopRefund = $this->createRepairRefund($otherShopOwner, [
            'finance_status' => 'approved_initial',
            'shop_owner_status' => 'pending',
        ]);

        $items = $this->repairRefundQueue($shopOwner);
        $this->assertCount(2, $items);
        $this->assertSame('shop_owner', $this->itemById($items, $actionable->id)['approvalStage']);
        $this->assertSame('finance_initial', $this->itemById($items, $financePending->id)['approvalStage']);
        $this->assertNull($this->findItemById($items, $otherShopRefund->id));

        $actionable->update(['shop_owner_status' => 'approved']);
        $items = $this->repairRefundQueue($shopOwner);
        $this->assertNotSame('shop_owner', $this->itemById($items, $actionable->id)['approvalStage']);

        $actionable->update(['shop_owner_status' => 'pending', 'status' => 'approved']);
        $items = $this->repairRefundQueue($shopOwner);
        $this->assertNull($this->findItemById($items, $actionable->id));

        $actionable->update(['status' => 'requested', 'module_type' => 'retail']);
        $items = $this->repairRefundQueue($shopOwner);
        $this->assertNull($this->findItemById($items, $actionable->id));

        $this->assertSame('/shop-owner/refund-approvals', route('shop-owner.refund-approvals', [], false));
    }

    public function test_expense_owner_workflow_requires_the_current_pending_shop_owner_approval_and_excludes_procurement_receipts(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $otherShopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $requester = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);
        $otherRequester = \App\Models\User::factory()->create(['shop_owner_id' => $otherShopOwner->id]);

        $actionable = $this->createExpenseWithApproval($shopOwner, $requester, [
            'approval_status' => ApprovalStatus::PENDING,
            'current_approver_role' => 'shop_owner',
        ]);
        $otherShopExpense = $this->createExpenseWithApproval($otherShopOwner, $otherRequester, [
            'approval_status' => ApprovalStatus::PENDING,
            'current_approver_role' => 'shop_owner',
        ]);
        $receipt = PurchaseOrderReceipt::factory()->create([
            'purchase_order_id' => PurchaseOrder::factory()->create([
                'shop_owner_id' => $shopOwner->id,
            ])->id,
            'shop_owner_id' => $shopOwner->id,
        ]);
        $procurementExpense = Expense::create([
            'reference' => 'EXP-PROC-'.uniqid(),
            'date' => now()->toDateString(),
            'category' => 'Procurement',
            'description' => 'Receipt-backed expense',
            'amount' => 9000,
            'tax_amount' => 0,
            'status' => 'submitted',
            'shop_id' => $shopOwner->id,
            'procurement_receipt_id' => $receipt->id,
        ]);

        $items = $this->expenseQueue($shopOwner);
        $this->assertCount(1, $items);
        $this->assertSame($actionable->id, (int) $items[0]['id']);
        $this->assertNotSame($actionable->id, $otherShopExpense->id);
        $this->assertNotSame($actionable->id, $procurementExpense->id);

        $actionable->approval()->update([
            'current_level' => 1,
            'current_approver_role' => 'finance',
        ]);
        $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/expenses/{$actionable->id}/approve")
            ->assertStatus(422);

        $actionable->approval()->update([
            'current_level' => 2,
            'current_approver_role' => 'shop_owner',
            'status' => ApprovalStatus::APPROVED,
        ]);
        $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/expenses/{$actionable->id}/approve")
            ->assertStatus(422);

        $actionable->approval()->update(['status' => ApprovalStatus::PENDING]);
        $actionable->update(['status' => 'approved']);
        $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/expenses/{$actionable->id}/approve")
            ->assertStatus(422);

        $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/expenses/{$otherShopExpense->id}/approve")
            ->assertNotFound();

        $this->assertSame('/shop-owner/expense-approvals', route('shop-owner.expense-approvals', [], false));
    }

    public function test_purchase_request_owner_queue_requires_current_shop_owner_state_and_tenant_binding(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $otherShopOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $requester = \App\Models\User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $actionable = PurchaseRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'requested_by' => $requester->id,
            'status' => 'pending_shop_owner',
        ]);
        $otherShopRequest = PurchaseRequest::factory()->create([
            'shop_owner_id' => $otherShopOwner->id,
            'status' => 'pending_shop_owner',
        ]);

        $this->assertPurchaseRequestQueueContainsOnly($shopOwner, $actionable->id);

        $actionable->update(['status' => 'pending_finance']);
        $this->assertPurchaseRequestQueueContainsOnly($shopOwner);

        $actionable->update(['status' => 'pending_shop_owner', 'shop_owner_id' => $otherShopOwner->id]);
        $this->assertPurchaseRequestQueueContainsOnly($shopOwner);

        $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/purchase-requests/{$otherShopRequest->id}/approve")
            ->assertNotFound();

        $this->assertSame('/shop-owner/purchase-request-approval', route('shop-owner.purchase-request-approval', [], false));
    }

    private function enableRefundOwnerApproval(ShopOwner $shopOwner): void
    {
        ProcurementSettings::query()->updateOrCreate(
            ['shop_owner_id' => $shopOwner->id],
            [
                'settings_json' => [
                    'approval_pages' => [
                        'refund_approval' => ['enabled' => true, 'limit' => 1],
                    ],
                ],
            ],
        );
    }

    private function createOrderRefund(ShopOwner $shopOwner, array $overrides = []): OrderRefund
    {
        $customer = \App\Models\User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'payment_status' => 'paid',
        ]);

        return OrderRefund::factory()->create(array_merge([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'flow_type' => 'request_approval',
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
            'return_status' => 'awaiting_approval',
            'amount' => 1000,
        ], $overrides));
    }

    private function createRepairRefund(ShopOwner $shopOwner, array $overrides = []): PosRefund
    {
        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'final_total' => 1000,
            'total_paid_amount' => 1000,
            'payment_status' => 'paid',
        ]);
        $transaction = PosTransaction::create([
            'transaction_no' => 'POS-CHAR-'.uniqid(),
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Characterization Customer',
            'due_type' => 'full',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return PosRefund::create(array_merge([
            'refund_no' => 'RFD-CHAR-'.uniqid(),
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $transaction->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'request_type' => 'full',
            'requested_amount' => 1000,
            'reason_code' => 'characterization',
            'status' => 'requested',
            'finance_status' => 'approved_initial',
            'shop_owner_status' => 'pending',
            'workflow_source' => 'shop_pos_repair',
            'requested_at' => now(),
        ], $overrides));
    }

    private function createExpenseWithApproval(ShopOwner $shopOwner, object $requester, array $overrides = []): Expense
    {
        $approvalStatus = $overrides['approval_status'] ?? ApprovalStatus::PENDING;
        $currentApproverRole = (string) ($overrides['current_approver_role'] ?? 'shop_owner');
        unset($overrides['approval_status'], $overrides['current_approver_role']);

        $expense = Expense::create(array_merge([
            'reference' => 'EXP-CHAR-'.uniqid(),
            'date' => now()->toDateString(),
            'category' => 'Operations',
            'description' => 'Characterization expense',
            'amount' => 6000,
            'tax_amount' => 0,
            'status' => 'submitted',
            'shop_id' => $shopOwner->id,
            'created_by' => $requester->id,
        ], $overrides));

        $approval = Approval::create([
            'shop_owner_id' => $shopOwner->id,
            'approvable_type' => Expense::class,
            'approvable_id' => $expense->id,
            'reference' => $expense->reference,
            'description' => $expense->description,
            'amount' => $expense->amount,
            'requested_by' => $requester->id,
            'current_level' => $currentApproverRole === 'shop_owner' ? 2 : 1,
            'total_levels' => 2,
            'status' => $approvalStatus,
            'approval_roles' => ['1' => 'finance', '2' => 'shop_owner'],
            'current_approver_role' => $currentApproverRole,
        ]);

        $expense->update([
            'approval_id' => $approval->id,
            'current_approval_level' => $approval->current_level,
        ]);

        return $expense->fresh();
    }

    private function assertOrderRefundQueueContainsOnly(ShopOwner $shopOwner, ?int $refundId = null): void
    {
        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/refunds?status=pending');

        $response->assertOk();
        $items = $response->json('data');

        if ($refundId === null) {
            $this->assertCount(0, $items);

            return;
        }

        $this->assertCount(1, $items);
        $this->assertSame($refundId, (int) $items[0]['id']);
    }

    /** @return array<int, array<string, mixed>> */
    private function repairRefundQueue(ShopOwner $shopOwner): array
    {
        return $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/repair-refunds?status=pending')
            ->assertOk()
            ->json('data');
    }

    /** @return array<int, array<string, mixed>> */
    private function expenseQueue(ShopOwner $shopOwner): array
    {
        return $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/expenses')
            ->assertOk()
            ->json('data');
    }

    private function assertPurchaseRequestQueueContainsOnly(ShopOwner $shopOwner, ?int $requestId = null): void
    {
        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->getJson('/api/shop-owner/purchase-requests?status=pending_shop_owner');

        $response->assertOk();
        $items = $response->json('data');

        if ($requestId === null) {
            $this->assertCount(0, $items);

            return;
        }

        $this->assertCount(1, $items);
        $this->assertSame($requestId, (int) $items[0]['id']);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function itemById(array $items, int $id): array
    {
        $item = $this->findItemById($items, $id);
        $this->assertIsArray($item);

        return $item;
    }

    /** @param array<int, array<string, mixed>> $items */
    private function findItemById(array $items, int $id): ?array
    {
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return $item;
            }
        }

        return null;
    }
}
