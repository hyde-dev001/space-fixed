<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OrderRefundApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_shop_staff_can_approve_then_finance_can_authorize(): void
    {
        [$shop, $staff, $refund] = $this->fixture();

        $this->actingAs($staff, 'user')
            ->postJson("/api/staff/orders/{$refund->order_id}/refund/approve")
            ->assertOk();

        $this->assertDatabaseHas('order_refunds', [
            'id' => $refund->id,
            'shop_owner_status' => 'approved',
            'shop_owner_approved_by' => $staff->id,
            'finance_status' => 'pending',
        ]);

        $finance = User::factory()->create();
        $result = app(\App\Services\OrderRefundService::class)
            ->approveRequestedRefund($refund->fresh(), 'finance', $finance->id);

        $this->assertSame('approved', $result['result']);
        $this->assertSame('approved', $refund->fresh()->finance_status);
        $this->assertSame('pending_customer_shipment', $refund->fresh()->return_status);
    }

    public function test_staff_can_reject_a_pending_refund(): void
    {
        [, $staff, $refund] = $this->fixture();

        $this->actingAs($staff, 'user')
            ->postJson("/api/staff/orders/{$refund->order_id}/refund/reject", [
                'rejection_reason' => 'Evidence does not qualify.',
            ])->assertOk();

        $this->assertDatabaseHas('order_refunds', [
            'id' => $refund->id,
            'status' => 'rejected',
            'shop_owner_status' => 'rejected',
        ]);
    }

    public function test_staff_cannot_review_another_shops_refund_or_repeat_review(): void
    {
        [, $staff, $refund] = $this->fixture();
        $otherShop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $otherOrder = Order::factory()->create(['shop_owner_id' => $otherShop->id]);
        OrderRefund::factory()->create([
            'order_id' => $otherOrder->id,
            'shop_owner_id' => $otherShop->id,
            'flow_type' => 'request_approval',
        ]);

        $this->actingAs($staff, 'user')
            ->postJson("/api/staff/orders/{$otherOrder->id}/refund/approve")
            ->assertNotFound();

        $this->postJson("/api/staff/orders/{$refund->order_id}/refund/approve")->assertOk();
        $this->postJson("/api/staff/orders/{$refund->order_id}/refund/approve")->assertStatus(422);
    }

    public function test_user_without_staff_order_permission_is_forbidden(): void
    {
        [$shop, , $refund] = $this->fixture();
        $user = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'CUSTOMER']);

        $this->actingAs($user, 'user')
            ->postJson("/api/staff/orders/{$refund->order_id}/refund/approve")
            ->assertForbidden();
    }

    private function fixture(): array
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $staff = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        Permission::findOrCreate('access-staff-job-orders', 'user');
        $staff->givePermissionTo('access-staff-job-orders');
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => User::factory()->create()->id,
            'payment_status' => 'paid',
        ]);
        $refund = OrderRefund::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'shop_owner_id' => $shop->id,
            'flow_type' => 'request_approval',
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'pending',
            'return_status' => 'awaiting_approval',
        ]);

        return [$shop, $staff, $refund];
    }
}
