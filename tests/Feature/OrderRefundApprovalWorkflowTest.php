<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ProcurementSettings;
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

    public function test_finance_index_marks_only_eligible_refunds_as_executable(): void
    {
        [$shop, , $refund] = $this->fixture();
        $refund->update([
            'status' => 'pending_approval',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'received',
        ]);

        $waitingRefund = OrderRefund::factory()->create([
            'order_id' => $refund->order_id,
            'customer_id' => $refund->customer_id,
            'shop_owner_id' => $shop->id,
            'flow_type' => 'request_approval',
            'status' => 'pending_approval',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'in_transit',
        ]);

        $finance = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'Finance']);
        $permission = Permission::findOrCreate('access-refund-approval', 'user');
        $finance->givePermissionTo($permission);

        $response = $this->actingAs($finance, 'user')
            ->getJson('/api/finance/refunds?status=All');

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertTrue((bool) data_get($rows->get($refund->id), 'canExecutePayout'));
        $this->assertFalse((bool) data_get($rows->get($waitingRefund->id), 'canExecutePayout'));
    }

    public function test_new_order_refunds_snapshot_the_setting_at_reservation(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual']);
        $customer = User::factory()->create();

        $this->setRefundApproval($shop, false);
        $offRefund = $this->reserveRefund($shop, $customer, 'off');
        $this->assertSame(false, $offRefund->requires_owner_approval);

        $this->setRefundApproval($shop, true);
        $onRefund = $this->reserveRefund($shop, $customer, 'on');
        $this->assertSame(true, $onRefund->requires_owner_approval);
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

    private function setRefundApproval(ShopOwner $shop, bool $enabled): void
    {
        ProcurementSettings::query()->updateOrCreate(
            ['shop_owner_id' => $shop->id],
            [
                'settings_json' => [
                    'approval_pages' => [
                        'refund_approval' => ['enabled' => $enabled],
                    ],
                ],
            ],
        );
    }

    private function reserveRefund(ShopOwner $shop, User $customer, string $suffix): OrderRefund
    {
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'payment_status' => 'paid',
            'payment_method' => 'paymongo',
            'total_amount' => 1000,
        ]);

        $result = app(\App\Services\OrderRefundService::class)->reserveOrderRefund($order, [
            'customer_id' => $customer->id,
            'shop_owner_id' => $shop->id,
            'flow_type' => 'request_approval',
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'pending',
            'return_status' => 'awaiting_approval',
            'payment_gateway' => 'paymongo',
            'currency' => 'PHP',
            'reason_code' => 'defective_item',
            'requires_owner_approval' => true,
            'idempotency_key' => 'phase4-refund-snapshot-'.$suffix,
            'requested_at' => now(),
        ]);

        $this->assertSame('reserved', $result['result']);

        return $result['refund'];
    }
}
