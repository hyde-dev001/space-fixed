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

        $result = app(\App\Services\OrderRefundService::class)
            ->approveRequestedRefund($refund, 'staff', (int) $staff->id);
        $this->assertSame('approved', $result['result']);

        $this->assertDatabaseHas('order_refunds', [
            'id' => $refund->id,
            'staff_approved_by' => $staff->id,
            'shop_owner_status' => 'pending',
            'shop_owner_approved_by' => null,
            'finance_status' => 'pending',
        ]);
        $this->assertDatabaseMissing('shipments', [
            'source_type' => 'order_refund',
            'source_id' => $refund->id,
            'purpose' => 'refund_return',
        ]);

        $finance = User::factory()->create();
        $result = app(\App\Services\OrderRefundService::class)
            ->approveRequestedRefund($refund->fresh(), 'finance', $finance->id);

        $this->assertSame('approved', $result['result']);
        $this->assertSame('approved', $refund->fresh()->finance_status);
        $this->assertSame('pending_customer_shipment', $refund->fresh()->return_status);
        $this->assertDatabaseMissing('shipments', [
            'source_type' => 'order_refund',
            'source_id' => $refund->id,
            'purpose' => 'refund_return',
        ]);
    }

    public function test_individual_owner_approval_needs_no_finance_employee_and_preserves_return_gate(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual', 'business_type' => 'retail']);
        config()->set('shop_modules.enforcement_enabled', true);
        $this->setRefundApproval($shop, true);
        $refund = $this->reserveRefund($shop, User::factory()->create(), 'owner-only');
        $this->actingAs($shop, 'shop_owner')->postJson("/api/shop-owner/refunds/{$refund->id}/approve")
            ->assertOk();
        $this->assertSame('pending_customer_shipment', $refund->fresh()->return_status);
        $service = app(\App\Services\OrderRefundService::class);
        $this->assertFalse($service->canExecuteApprovedRefund($refund->fresh()));
        $refund->update(['return_status' => 'received']);
        $this->assertTrue($service->canExecuteApprovedRefund($refund->fresh()));
        $this->assertNull($refund->fresh()->finance_approved_by);
    }

    public function test_already_owner_approved_individual_refund_is_executable_without_finance_backfill(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual', 'business_type' => 'retail']);
        $refund = $this->reserveRefund($shop, User::factory()->create(), 'owner-already-approved');
        $refund->update(['shop_owner_status' => 'approved', 'shop_owner_approved_at' => now(),
            'finance_status' => 'pending', 'return_status' => 'received']);
        $this->assertTrue(app(\App\Services\OrderRefundService::class)->canExecuteApprovedRefund($refund->fresh()));
        $this->assertSame('pending', $refund->fresh()->finance_status, 'A read must not fabricate a Finance decision');
        $this->assertNull($refund->fresh()->finance_approved_by);
        $this->actingAs($shop, 'shop_owner')->getJson('/api/shop-owner/orders')
            ->assertOk()->assertJsonPath('data.0.latest_refund.approvals_complete', true)
            ->assertJsonPath('data.0.latest_refund.finance_approval_required', false)
            ->assertJsonPath('data.0.latest_refund.can_execute_payout', true);
        $refund->update(['finance_status' => 'rejected']);
        $this->assertFalse(app(\App\Services\OrderRefundService::class)->canExecuteApprovedRefund($refund->fresh()));
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
            'shop_owner_status' => 'pending',
            'staff_approved_by' => null,
        ]);
        $this->assertDatabaseMissing('shipments', [
            'source_type' => 'order_refund',
            'source_id' => $refund->id,
            'purpose' => 'refund_return',
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
        [, , $refund] = $this->fixture();
        $user = User::factory()->create(['role' => 'CUSTOMER']);
        $this->assertFalse($user->isEmployeeAccount());

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
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual', 'business_type' => 'retail']);
        $customer = User::factory()->create();

        $this->setRefundApproval($shop, false);
        $offRefund = $this->reserveRefund($shop, $customer, 'off');
        $this->assertSame(false, $offRefund->requires_owner_approval);
        $this->assertSame('pending_approval', $offRefund->status);

        $this->setRefundApproval($shop, true);
        $onRefund = $this->reserveRefund($shop, $customer, 'on');
        $this->assertSame(true, $onRefund->requires_owner_approval);
        $this->assertSame('requested', $onRefund->status);
    }

    public function test_individual_paymongo_refund_setting_controls_only_the_owner_stage(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual', 'business_type' => 'retail']);
        $customer = User::factory()->create();
        $service = app(\App\Services\OrderRefundService::class);
        foreach ([false, true] as $enabled) {
            $this->setRefundApproval($shop, $enabled);
            $refund = $this->reserveRefund($shop, $customer, 'individual-policy-'.(int) $enabled);
            if ($enabled) {
                $this->assertFalse($refund->hasRequiredApprovals());
                $this->actingAs($shop, 'shop_owner')->postJson("/api/shop-owner/refunds/{$refund->id}/approve")
                    ->assertOk()->assertJsonPath('refund.approvalStage', 'approved');
            } else {
                $this->actingAs($shop, 'shop_owner')->postJson("/api/shop-owner/refunds/{$refund->id}/approve")
                    ->assertUnprocessable();
            }
            $refund->refresh();
            $this->assertTrue($refund->hasRequiredApprovals());
            $this->assertSame('pending_customer_shipment', $refund->effectiveReturnStatus());
            $this->assertFalse($service->canExecuteApprovedRefund($refund));
            $this->assertSame('pending', $refund->finance_status);
            $this->assertNull($refund->finance_approved_by);
        }
    }

    public function test_existing_individual_approval_allows_real_return_without_backfill(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual', 'business_type' => 'retail']);
        $refund = $this->reserveRefund($shop, User::factory()->create(), 'legacy-approved-return');
        $refund->update(['shop_owner_status' => 'approved']);
        $this->assertSame('pending_customer_shipment', $refund->effectiveReturnStatus());
        $this->assertSame('awaiting_approval', $refund->fresh()->return_status);
        $result = app(\App\Services\OrderRefundService::class)->markCustomerReturnShipped($refund, ['tracking_number' => 'REAL-RETURN']);
        $this->assertSame('in_transit', $result['refund']->return_status);
        $this->assertSame('pending', $refund->fresh()->finance_status);
    }

    public function test_individual_owner_executes_received_refund_once_without_finance(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual', 'business_type' => 'retail', 'paymongo_secret_key' => 'sk_test_fake']);
        $refund = $this->reserveRefund($shop, User::factory()->create(), 'owner-payout');
        config()->set('shop_modules.enforcement_enabled', true);
        $refund->order->update(['paymongo_payment_id' => 'pay_owner_only', 'shipping_fee' => 0]);
        $refund->update(['shop_owner_status' => 'approved', 'return_status' => 'received']);
        $gateway = $this->mock(\App\Services\PaymongoRefundService::class);
        $gateway->shouldReceive('getPaymentAmountInCentavos')->once()->andReturn(null);
        $gateway->shouldReceive('createRefund')->once()->andReturn(['success' => true, 'status' => 'succeeded', 'refund_id' => 're_owner_only']);
        $otherShop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual', 'business_type' => 'retail']);
        $this->actingAs($otherShop, 'shop_owner')->postJson("/api/shop-owner/refunds/{$refund->id}/execute-gateway-refund")->assertNotFound();
        $refund->update(['return_status' => 'pending_customer_shipment']);
        $this->actingAs($shop, 'shop_owner')->postJson("/api/shop-owner/refunds/{$refund->id}/execute-gateway-refund")->assertUnprocessable();
        $refund->update(['return_status' => 'received']);
        $this->actingAs($shop, 'shop_owner')->postJson("/api/shop-owner/refunds/{$refund->id}/execute-gateway-refund")
            ->assertOk()->assertJsonPath('refund.rawStatus', 'succeeded');
        $this->postJson("/api/shop-owner/refunds/{$refund->id}/execute-gateway-refund")->assertConflict();
        $this->assertSame('pending', $refund->fresh()->finance_status);
        $this->assertNull($refund->fresh()->finance_approved_by);
    }

    public function test_individual_cod_request_is_discoverable_without_finance_but_cash_guards_remain(): void
    {
        $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'individual', 'business_type' => 'retail']);
        $this->setRefundApproval($shop, true);
        $refund = $this->reserveRefund($shop, User::factory()->create(), 'individual-cod');
        $refund->order->update(['payment_method' => 'cod']);
        $this->actingAs($shop, 'shop_owner')->getJson('/api/shop-owner/refunds?status=pending')
            ->assertOk()->assertJsonPath('data.0.id', $refund->id)->assertJsonPath('data.0.approvalStage', 'shop_owner');
        $this->postJson("/api/shop-owner/refunds/{$refund->id}/approve")->assertOk();
        $refund->update(['return_status' => 'received']);
        $service = app(\App\Services\OrderRefundService::class);
        $this->assertTrue($refund->fresh()->hasRequiredApprovals());
        $this->assertFalse($service->canExecuteApprovedRefund($refund->fresh()));
        $this->assertFalse($service->canAcceptCodRefundDestination($refund->fresh()));
    }

    public function test_company_paymongo_refunds_keep_staff_finance_and_owner_stages_separate(): void
    {
        $service = app(\App\Services\OrderRefundService::class);

        foreach ([false, true] as $ownerApprovalEnabled) {
            $shop = ShopOwner::factory()->approved()->create(['registration_type' => 'company']);
            $customer = User::factory()->create();
            $staff = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
            $finance = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'Finance']);
            $this->setRefundApproval($shop, $ownerApprovalEnabled);
            $refund = $this->reserveRefund($shop, $customer, 'company-' . (int) $ownerApprovalEnabled);

            $this->assertSame('invalid_state', $service->approveRequestedRefund($refund, 'finance', $finance->id)['result']);
            $this->assertSame('approved', $service->approveRequestedRefund($refund, 'staff', $staff->id)['result']);
            $this->assertDatabaseHas('order_refunds', [
                'id' => $refund->id,
                'requires_owner_approval' => (int) $ownerApprovalEnabled,
                'staff_approved_by' => $staff->id,
                'shop_owner_status' => 'pending',
                'shop_owner_approved_by' => null,
                'status' => 'pending_approval',
            ]);

            $this->assertSame('approved', $service->approveRequestedRefund($refund->fresh(), 'finance', $finance->id)['result']);
            if ($ownerApprovalEnabled) {
                $this->assertSame('approved_initial', $refund->fresh()->finance_status);
                $this->assertSame('approved', $service->approveRequestedRefund($refund->fresh(), 'shop_owner')['result']);
                $this->assertSame('approved', $service->approveRequestedRefund($refund->fresh(), 'finance', $finance->id)['result']);
            } else {
                $this->assertSame('invalid_state', $service->approveRequestedRefund($refund->fresh(), 'shop_owner')['result']);
            }

            $this->assertDatabaseHas('order_refunds', [
                'id' => $refund->id,
                'finance_status' => 'approved',
                'shop_owner_status' => 'approved',
                'return_status' => 'pending_customer_shipment',
            ]);
            $this->assertFalse($service->canExecuteApprovedRefund($refund->fresh()));
        }
    }

    public function test_finance_projection_exposes_staff_approval_for_company_refunds_without_third_party_delivery(): void
    {
        foreach (['shop_owned', null] as $deliveryMethod) {
            foreach ([false, true] as $ownerApprovalEnabled) {
                [$shop, $staff, $refund] = $this->fixture();
                $refund->order->update(['delivery_method' => $deliveryMethod, 'carrier_company' => $deliveryMethod ? 'Shop-owned logistics' : null]);
                $refund->update(['requires_owner_approval' => $ownerApprovalEnabled]);
                $finance = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'Finance']);
                $finance->givePermissionTo(Permission::findOrCreate('access-refund-approval', 'user'));
                $this->clockInEmployee($finance);
                $this->actingAs($finance, 'user')->postJson("/api/finance/refunds/{$refund->id}/approve")->assertUnprocessable();
                $result = app(\App\Services\OrderRefundService::class)->approveRequestedRefund($refund->fresh(), 'staff', $staff->id);
                $this->assertSame('approved', $result['result']);
                $row = collect($this->getJson('/api/finance/refunds?status=Pending')->assertOk()->json('data'))->firstWhere('id', $refund->id);
                $this->assertNotNull($row);
                $this->assertTrue($row['requiresStaffApproval']);
                $this->assertSame('approved', $row['staffApprovalStatus']);
                $this->assertSame('finance_initial', $row['approvalStage']);
                $this->assertSame('pending', $row['shopOwnerStatus']);
                $this->assertFalse($row['canExecutePayout']);
                $this->postJson("/api/finance/refunds/{$refund->id}/approve")->assertOk()
                    ->assertJsonPath('refund.financeStatus', $ownerApprovalEnabled ? 'approved_initial' : 'approved')
                    ->assertJsonPath('refund.canExecutePayout', false);
                $this->assertDatabaseMissing('order_refunds', ['id' => $refund->id, 'status' => 'succeeded']);
            }
        }
    }

    public function test_finance_projection_preserves_existing_company_staff_acceptance_audit_fields(): void
    {
        foreach (['staff_approved_at', 'shop_owner_approved_by'] as $auditField) {
            [$shop, $staff, $refund] = $this->fixture();
            $audit = $auditField === 'staff_approved_at'
                ? ['staff_approved_at' => now()]
                : ['shop_owner_approved_by' => $staff->id, 'shop_owner_approved_at' => now()];
            $refund->order->update(['delivery_method' => 'shop_owned', 'carrier_company' => 'Shop-owned logistics']);
            $refund->forceFill($audit + ['status' => 'pending_approval', 'requires_owner_approval' => true])->save();
            $finance = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'Finance']);
            $finance->givePermissionTo(Permission::findOrCreate('access-refund-approval', 'user'));
            $this->clockInEmployee($finance);
            $row = collect($this->actingAs($finance, 'user')->getJson('/api/finance/refunds?status=Pending')->assertOk()->json('data'))->firstWhere('id', $refund->id);
            $this->assertTrue($row['requiresStaffApproval']);
            $this->assertSame('approved', $row['staffApprovalStatus']);
            $this->postJson("/api/finance/refunds/{$refund->id}/reject", ['rejection_reason' => 'Finance declined after Staff assessment.'])->assertOk()
                ->assertJsonPath('refund.rawStatus', 'rejected')->assertJsonPath('refund.financeStatus', 'rejected');
        }
    }

    public function test_company_confirmed_loss_enters_staff_review_without_a_fictional_return(): void
    {
        [$shop, $staff] = $this->fixture();
        $order = Order::factory()->create(['shop_owner_id' => $shop->id, 'payment_status' => 'paid', 'payment_method' => 'paymongo', 'paymongo_payment_id' => 'synthetic-loss-payment']);
        $shipment = \App\Models\Logistics\Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order', 'source_id' => $order->id]);
        $leg = \App\Models\Logistics\ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'cancelled', 'resolution_type' => 'loss_confirmed']);
        $service = app(\App\Services\OrderRefundService::class);
        $refund = $service->reserveConfirmedLossRefund($order, $leg, 'Investigated synthetic parcel loss')['refund'];
        $this->assertSame('pending', $refund->shop_owner_status);
        $this->assertSame('not_required', $refund->return_status);
        $this->assertSame('invalid_state', $service->approveRequestedRefund($refund, 'finance', $staff->id)['result']);
        $this->assertSame('approved', $service->approveRequestedRefund($refund->fresh(), 'staff', $staff->id)['result']);
        $this->assertSame($staff->id, $refund->fresh()->staff_approved_by);
        $this->assertSame('not_required', $refund->fresh()->return_status);
        $this->assertSame('approved', $service->approveRequestedRefund($refund->fresh(), 'finance', $staff->id)['result']);
        if ($refund->fresh()->requires_owner_approval) {
            $this->assertSame('approved_initial', $refund->fresh()->finance_status);
            $this->assertFalse($service->canExecuteApprovedRefund($refund->fresh()));
            $this->assertSame('approved', $service->approveRequestedRefund($refund->fresh(), 'shop_owner')['result']);
            $this->assertSame('approved', $service->approveRequestedRefund($refund->fresh(), 'finance', $staff->id)['result']);
        }
        $this->assertSame('not_required', $refund->fresh()->return_status);
        $this->assertTrue($service->canExecuteApprovedRefund($refund->fresh()));
    }

    public function test_existing_system_loss_refund_can_be_reviewed_without_inventing_a_prior_staff_approval(): void
    {
        [$shop, $staff, $refund] = $this->fixture();
        $shipment = \App\Models\Logistics\Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order', 'source_id' => $refund->order_id]);
        $leg = \App\Models\Logistics\ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'cancelled', 'resolution_type' => 'loss_confirmed']);
        $refund->update(['reason_code' => 'delivery_loss_confirmed', 'return_status' => 'not_required', 'shop_owner_status' => 'approved', 'shop_owner_approved_at' => now(), 'shop_owner_approved_by' => null,
            'staff_approved_at' => null, 'staff_approved_by' => null, 'idempotency_key' => "delivery-loss-confirmed:{$refund->order_id}:{$leg->id}"]);
        $this->actingAs($staff, 'user')->getJson("/api/staff/orders/{$refund->order_id}")
            ->assertOk()->assertJsonPath('latest_refund.can_staff_review', true);
        $this->actingAs($staff, 'user')->postJson("/api/staff/orders/{$refund->order_id}/refund/approve", ['approval_note' => 'Staff reviewed the loss claim.'])->assertOk();
        $this->assertSame($staff->id, $refund->fresh()->staff_approved_by);
        $this->assertSame('pending', $refund->fresh()->finance_status);
        $this->assertSame('pending', $refund->fresh()->shop_owner_status);
        $this->assertSame('not_required', $refund->fresh()->return_status);
        $this->postJson("/api/staff/orders/{$refund->order_id}/refund/approve")->assertUnprocessable();
    }

    public function test_legacy_loss_review_exception_rejects_unverified_loss_identity(): void
    {
        [, $staff, $refund] = $this->fixture();
        $refund->update(['reason_code' => 'delivery_loss_confirmed', 'return_status' => 'not_required', 'shop_owner_status' => 'approved',
            'idempotency_key' => "delivery-loss-confirmed:{$refund->order_id}:999999"]);
        $this->actingAs($staff, 'user')->postJson("/api/staff/orders/{$refund->order_id}/refund/approve")->assertUnprocessable();
        $this->assertNull($refund->fresh()->staff_approved_by);
        $this->assertSame('pending', $refund->fresh()->finance_status);
    }

    public function test_staff_cannot_activate_customer_receipt_for_a_confirmed_lost_parcel(): void
    {
        [$shop, $staff, $refund] = $this->fixture();
        $refund->order->update(['status' => 'shipped']);
        $shipment = \App\Models\Logistics\Shipment::factory()->create(['shop_owner_id' => $shop->id, 'source_type' => 'order', 'source_id' => $refund->order_id]);
        \App\Models\Logistics\ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'cancelled', 'resolution_type' => 'loss_confirmed']);
        $this->actingAs($staff, 'user')->postJson("/api/staff/orders/{$refund->order_id}/activate-pickup")
            ->assertUnprocessable();
        $this->assertFalse((bool) $refund->order->fresh()->pickup_enabled);
    }

    private function fixture(): array
    {
        $shop = ShopOwner::factory()->create(['registration_type' => 'company']);
        $staff = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        $this->clockInEmployee($staff);
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
