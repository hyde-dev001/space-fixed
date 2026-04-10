<?php

namespace Tests\Feature\Notifications;

use App\Enums\SuspensionStatus;
use App\Models\Employee;
use App\Models\Order;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\SuspensionRequest;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\HR\SalaryChange;
use App\Models\User;
use App\Services\HR\SalaryChangeApprovalService;
use App\Services\RepairOnlineRefundWorkflowService;
use App\Services\RepairPosRefundService;
use App\Services\SupplierOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationCriticalFlowsTest extends TestCase
{
    use RefreshDatabase;

    private function createShopOwner(array $overrides = []): ShopOwner
    {
        return ShopOwner::factory()->approved()->create(array_merge([
            'business_type' => 'both',
            'registration_type' => 'individual',
        ], $overrides));
    }

    private function createRepairRefundFixture(string $workflowSource = 'pos'): array
    {
        $shopOwner = $this->createShopOwner();

        $customer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'email' => 'repair-customer@example.test',
        ]);

        $repair = RepairRequest::query()->create([
            'request_id' => 'RR-' . now()->format('YmdHis') . '-' . random_int(100, 999),
            'customer_name' => 'Repair Customer',
            'email' => $customer->email,
            'phone' => '09171234567',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'total' => 1200.00,
            'status' => 'completed',
        ]);

        $source = PosTransaction::query()->create([
            'transaction_no' => 'TXN-' . now()->format('YmdHis') . '-' . random_int(100, 999),
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 1200.00,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 1200.00,
            'paid_amount' => 1200.00,
            'status' => 'paid',
        ]);

        $refund = PosRefund::query()->create([
            'refund_no' => 'RFD-' . now()->format('YmdHis') . '-' . random_int(100, 999),
            'shop_owner_id' => $shopOwner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'workflow_source' => $workflowSource,
            'request_type' => 'partial',
            'requested_amount' => 300.00,
            'reason_code' => 'service_issue',
            'status' => 'requested',
            'finance_status' => 'pending',
            'shop_owner_status' => 'pending',
            'repairer_status' => 'pending',
            'requested_by' => $customer->id,
            'requested_at' => now(),
        ]);

        return [
            'shop_owner' => $shopOwner,
            'customer' => $customer,
            'repair' => $repair,
            'source' => $source,
            'refund' => $refund,
        ];
    }

    #[Test]
    public function order_status_transition_dispatches_via_notification_service_not_direct_model_write(): void
    {
        $shopOwner = $this->createShopOwner();
        $customer = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $order = Order::query()->create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-TEST-1001',
            'total_amount' => 1999.00,
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);

        $order->update(['status' => 'shipped']);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $customer->id,
            'type' => 'order_status_update',
            'title' => 'Order Status Updated',
        ]);
    }

    #[Test]
    public function shop_owner_order_status_update_emits_customer_notification(): void
    {
        $shopOwner = $this->createShopOwner(['business_type' => 'retail']);
        $customer = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $order = Order::query()->create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-TEST-2001',
            'total_amount' => 1499.00,
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->patchJson("/api/shop-owner/orders/{$order->id}/status", [
                'status' => 'shipped',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $customer->id,
            'type' => 'order_status_update',
            'title' => 'Order Status Updated',
        ]);
    }

    #[Test]
    public function suspension_owner_review_emits_requester_and_employee_notification(): void
    {
        $shopOwner = $this->createShopOwner();
        $requester = User::factory()->create(['shop_owner_id' => $shopOwner->id]);

        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $shopOwner->id,
            'email' => 'employee@example.test',
        ]);

        $employeeUser = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'email' => 'employee@example.test',
        ]);

        $request = SuspensionRequest::factory()->create([
            'employee_id' => $employee->id,
            'requested_by' => $requester->id,
            'status' => SuspensionStatus::PENDING_OWNER,
            'manager_status' => 'approved',
            'owner_status' => 'pending',
        ]);

        $response = $this->actingAs($shopOwner, 'shop_owner')
            ->postJson("/api/shop-owner/suspension-requests/{$request->id}/review", [
                'action' => 'approve',
                'note' => 'Approved after review',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $requester->id,
            'title' => 'Suspension Request Reviewed',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employeeUser->id,
            'title' => 'Suspension Request Reviewed',
        ]);
    }

    #[Test]
    public function salary_change_rejection_notifies_proposer(): void
    {
        $shopOwner = $this->createShopOwner(['registration_type' => 'company']);

        $employee = Employee::factory()->active()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $proposer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $rejector = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $change = SalaryChange::query()->create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $shopOwner->id,
            'proposed_by' => $proposer->id,
            'previous_salary' => 20000,
            'new_salary' => 22000,
            'change_percent' => 10,
            'change_type' => SalaryChange::TYPE_MAJOR,
            'effective_date' => now()->toDateString(),
            'reason' => 'Market adjustment',
            'status' => SalaryChange::STATUS_PENDING,
        ]);

        $service = app(SalaryChangeApprovalService::class);
        $service->rejectSalaryChange($change, $rejector, 'Budget constraints');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $proposer->id,
            'title' => 'Salary Change Rejected',
            'type' => 'salary_change_approved',
        ]);
    }

    #[Test]
    public function repair_refund_finance_approve_emits_customer_and_owner_notifications(): void
    {
        $fixture = $this->createRepairRefundFixture('pos');

        $financeActor = User::factory()->create([
            'shop_owner_id' => $fixture['shop_owner']->id,
        ]);

        $service = app(RepairPosRefundService::class);
        $service->approve(
            refund: $fixture['refund'],
            actorId: (int) $financeActor->id,
            approvedAmount: 250.00,
            approvalNote: 'Finance approved',
            stage: 'finance',
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $fixture['customer']->id,
            'title' => 'Repair Refund Approved',
        ]);

        $this->assertDatabaseHas('notifications', [
            'shop_owner_id' => $fixture['shop_owner']->id,
            'title' => 'Repair Refund Approved',
        ]);
    }

    #[Test]
    public function repair_refund_repairer_reject_emits_finance_review_notification(): void
    {
        $fixture = $this->createRepairRefundFixture('online_myrepair');

        $financeUser = User::factory()->create([
            'shop_owner_id' => $fixture['shop_owner']->id,
        ]);

        $financeRole = Role::findOrCreate('Finance', 'user');
        $financeUser->assignRole($financeRole);

        $workflow = app(RepairOnlineRefundWorkflowService::class);
        $workflow->repairerReject(
            refund: $fixture['refund'],
            actorId: (int) $financeUser->id,
            assessmentNote: 'Cannot verify issue',
            reason: 'No defect found',
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $financeUser->id,
            'title' => 'Repair Refund Needs Finance Review',
        ]);
    }

    #[Test]
    public function overdue_supplier_orders_emit_notifications_to_inventory_recipients(): void
    {
        $shopOwner = $this->createShopOwner();

        $supplier = Supplier::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        SupplierOrder::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'supplier_id' => $supplier->id,
            'status' => 'sent',
            'expected_delivery_date' => now()->subDays(2)->toDateString(),
            'created_by' => null,
        ]);

        $inventoryUser = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $procurementRole = Role::findOrCreate('Procurement Manager', 'user');
        $inventoryUser->assignRole($procurementRole);

        $service = app(SupplierOrderService::class);
        $result = $service->notifyOverdueOrders();

        $this->assertGreaterThan(0, (int) ($result['notifications_sent'] ?? 0));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $inventoryUser->id,
            'title' => 'Supplier Order Overdue',
            'type' => 'purchase_request_submitted',
        ]);
    }

    #[Test]
    public function critical_flow_notifications_emit_without_cross_shop_leakage(): void
    {
        $shopOwnerA = $this->createShopOwner();
        $shopOwnerB = $this->createShopOwner();

        $supplierA = Supplier::factory()->create([
            'shop_owner_id' => $shopOwnerA->id,
        ]);

        SupplierOrder::factory()->create([
            'shop_owner_id' => $shopOwnerA->id,
            'supplier_id' => $supplierA->id,
            'status' => 'sent',
            'expected_delivery_date' => now()->subDays(3)->toDateString(),
            'created_by' => null,
        ]);

        $recipientA = User::factory()->create([
            'shop_owner_id' => $shopOwnerA->id,
        ]);

        $recipientB = User::factory()->create([
            'shop_owner_id' => $shopOwnerB->id,
        ]);

        $procurementRole = Role::findOrCreate('Procurement Manager', 'user');
        $recipientA->assignRole($procurementRole);
        $recipientB->assignRole($procurementRole);

        $service = app(SupplierOrderService::class);
        $service->notifyOverdueOrders();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $recipientA->id,
            'title' => 'Supplier Order Overdue',
            'type' => 'purchase_request_submitted',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $recipientB->id,
            'title' => 'Supplier Order Overdue',
            'type' => 'purchase_request_submitted',
        ]);
    }
}
