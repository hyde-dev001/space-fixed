<?php

namespace Tests\Feature\Notifications;

use App\Enums\SuspensionStatus;
use App\Models\Employee;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\SuspensionRequest;
use App\Models\HR\SalaryChange;
use App\Models\User;
use App\Services\HR\SalaryChangeApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
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
}
