<?php

namespace Tests\Feature\RetailWarranty;

use App\Models\OrderRefund;
use App\Models\PosTransaction;
use App\Models\User;
use App\Services\OrderRefundService;
use App\Services\RetailPosRefundService;
use App\Services\RetailWarrantyService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RefundIntegrationTest extends WarrantyTestCase
{
    public function test_my_orders_uses_latest_assessment_after_an_earlier_request_was_rejected(): void
    {
        $order = $this->purchase('delivered');
        $issuance = app(RetailWarrantyService::class)->captureFulfillment($order);
        $attributes = ['order_id' => $order->id, 'shop_owner_id' => $order->shop_owner_id, 'customer_id' => $order->customer_id, 'flow_type' => 'request_approval'];
        OrderRefund::factory()->create($attributes + ['status' => 'rejected']);
        $current = OrderRefund::factory()->create($attributes + ['status' => 'pending_approval', 'request_basis' => 'warranty']);
        $warranty = $issuance->warranties->first();
        $current->items()->create(['order_item_id' => $warranty->order_item_id, 'product_id' => $warranty->orderItem->product_id,
            'retail_warranty_id' => $warranty->id, 'requested_qty' => 1, 'unit_price_snapshot' => 250, 'line_amount' => 250]);
        $this->actingAs($order->customer, 'user')->get('/my-orders')->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('orders.0.refund_stage.id', $current->id)->where('orders.0.refund_stage.status', 'pending_approval')
            ->where('orders.0.product_warranty.items.0.reserved_quantity', 1));
    }

    private function evidence(): array
    {
        Storage::fake('public');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a9sAAAAASUVORK5CYII=');

        return array_merge(array_map(fn ($i) => UploadedFile::fake()->createWithContent('photo'.$i.'.png', $png), range(1, 5)),
            [UploadedFile::fake()->create('proof.mp4', 10, 'video/mp4')]);
    }

    public function test_warranty_assessment_beyond_ordinary_deadline_reserves_without_automatically_refunding(): void
    {
        $order = $this->purchase('delivered');
        $issuance = app(RetailWarrantyService::class)->captureFulfillment($order);
        $this->travel(4)->months();
        $warranty = $issuance->warranties->first();
        $buyer = User::findOrFail($order->customer_id);
        $this->actingAs($buyer, 'user')->post('/orders/request-refund', [
            'order_id' => $order->id, 'reason' => 'Manufacturing defect', 'request_basis' => 'warranty', 'request_type' => 'partial',
            'refund_lines' => [['order_item_id' => $warranty->order_item_id, 'retail_warranty_id' => $warranty->id, 'requested_qty' => 1]],
            'media' => $this->evidence(),
        ], ['Accept' => 'application/json'])->assertOk();
        $refund = OrderRefund::firstOrFail();
        $this->assertSame('warranty', $refund->request_basis);
        $this->assertNotContains($refund->status, ['succeeded', 'processing']);
        $this->assertSame('pending', $refund->finance_status);
        $this->assertSame($warranty->id, $refund->items->first()->retail_warranty_id);
        $qty = app(RetailWarrantyService::class)->quantities($issuance->warranties);
        $this->assertSame(0, $qty[$warranty->id]['refunded']);
        $this->assertSame(1, $qty[$warranty->id]['reserved']);
        $this->assertSame(1, $qty[$warranty->id]['available']);
    }

    public function test_forged_coverage_and_over_quantity_are_rejected_at_the_reservation_boundary(): void
    {
        $order = $this->purchase('delivered');
        $issuance = app(RetailWarrantyService::class)->captureFulfillment($order);
        $line = $issuance->warranties->first();
        $payload = ['request_basis' => 'warranty', 'flow_type' => 'request_approval', 'status' => 'requested', 'shop_owner_status' => 'pending',
            'finance_status' => 'pending', 'return_status' => 'awaiting_approval', 'amount' => 250, 'requested_at' => now(), 'idempotency_key' => 'invalid-warranty'];
        foreach ([[$line->id + 10000, 1], [$line->id, 3]] as [$id, $qty]) {
            try {
                app(OrderRefundService::class)->reserveOrderRefund($order, $payload, [['order_item_id' => $line->order_item_id, 'product_id' => $line->orderItem->product_id, 'retail_warranty_id' => $id, 'requested_qty' => $qty, 'unit_price_snapshot' => 250, 'line_amount' => 250 * $qty]]);
                $this->fail('Invalid warranty context must not reserve funds.');
            } catch (ValidationException $expected) {
                $this->assertDatabaseCount('order_refunds', 0);
            }
        }
    }

    public function test_warranty_reservation_replay_cannot_downgrade_basis_or_rewrite_covered_items(): void
    {
        $order = $this->purchase('delivered');
        $warranty = app(RetailWarrantyService::class)->captureFulfillment($order)->warranties->first();
        $payload = ['request_basis' => 'warranty', 'flow_type' => 'request_approval', 'status' => 'requested', 'shop_owner_status' => 'pending',
            'finance_status' => 'pending', 'return_status' => 'awaiting_approval', 'amount' => 250, 'requested_at' => now(), 'idempotency_key' => 'immutable-assessment'];
        $lines = [['order_item_id' => $warranty->order_item_id, 'product_id' => $warranty->orderItem->product_id, 'retail_warranty_id' => $warranty->id,
            'requested_qty' => 1, 'unit_price_snapshot' => 250, 'line_amount' => 250]];
        $service = app(OrderRefundService::class);
        $refund = $service->reserveOrderRefund($order, $payload, $lines)['refund'];
        $this->travel(2)->years();
        $this->assertSame('recovered', $service->reserveOrderRefund($order, $payload, $lines)['result']);
        try {
            $service->reserveOrderRefund($order, array_replace($payload, ['request_basis' => 'ordinary']), array_replace_recursive($lines, [0 => ['requested_qty' => 2, 'retail_warranty_id' => null]]));
            $this->fail('An existing warranty assessment must not be rewritten through ordinary replay.');
        } catch (ValidationException $expected) {
            $this->assertSame(1, $refund->items()->first()->requested_qty);
            $this->assertSame($warranty->id, $refund->items()->first()->retail_warranty_id);
            $this->assertDatabaseCount('order_refunds', 1);
        }
    }

    public function test_company_shop_owned_warranty_keeps_staff_assessment_without_third_party_classification(): void
    {
        $order = $this->purchase('delivered');
        $order->shopOwner->update(['registration_type' => 'company']);
        $order->forceFill(['carrier_company' => 'Shop-owned logistics', 'delivery_method' => 'shop_owned'])->save();
        $issuance = app(RetailWarrantyService::class)->captureFulfillment($order);
        $warranty = $issuance->warranties->first();
        $result = app(OrderRefundService::class)->reserveOrderRefund($order, ['request_basis' => 'warranty', 'flow_type' => 'request_approval',
            'status' => 'requested', 'shop_owner_status' => 'pending', 'finance_status' => 'pending', 'return_status' => 'awaiting_approval',
            'amount' => 250, 'requested_at' => now(), 'idempotency_key' => 'shop-owned-warranty'],
            [['order_item_id' => $warranty->order_item_id, 'product_id' => $warranty->orderItem->product_id, 'retail_warranty_id' => $warranty->id, 'requested_qty' => 1, 'unit_price_snapshot' => 250, 'line_amount' => 250]]);
        $refund = $result['refund'];
        $service = app(OrderRefundService::class);
        $this->assertFalse($service->isThirdPartyCustomerRefund($refund, $order->fresh()));
        $this->assertTrue($service->requiresStaffCustomerAssessment($refund, $order->fresh()));
        $projection = app(\App\Services\Orders\OrderRefundOwnerProjection::class)->project($refund);
        $this->assertSame('staff', $projection['waiting_on']);
        $this->assertFalse($projection['owner_action_required']);
        $this->assertSame('invalid_state', $service->approveRequestedRefund($refund, 'finance', 1)['result']);
        $this->assertDatabaseMissing('order_refunds', ['id' => $refund->id, 'status' => 'succeeded']);
    }

    public function test_pos_warranty_uses_existing_required_inspection_and_pending_approval_flow(): void
    {
        $order = $this->purchase('delivered');
        $order->update(['origin_channel' => 'pos']);
        $source = PosTransaction::create(['transaction_no' => 'POS-WARRANTY-1', 'shop_owner_id' => $order->shop_owner_id,
            'module_type' => 'retail', 'module_reference_id' => $order->id, 'customer_type' => 'registered', 'customer_id' => $order->customer_id,
            'due_type' => 'full', 'total_amount' => 1000, 'paid_amount' => 1000, 'status' => 'paid', 'paid_at' => now()]);
        $issuance = app(RetailWarrantyService::class)->captureFulfillment($order);
        $warranty = $issuance->warranties->first();
        $staff = User::factory()->create(['shop_owner_id' => $order->shop_owner_id]);
        $payload = ['request_basis' => 'warranty', 'request_type' => 'partial', 'reason_code' => 'warranty_assessment',
            'refund_lines' => [['order_item_id' => $warranty->order_item_id, 'retail_warranty_id' => $warranty->id, 'requested_qty' => 1, 'inspection_disposition' => 'damaged']]];
        $refund = app(RetailPosRefundService::class)->requestRefund($source, $payload, $staff->id);
        $this->assertSame('warranty', $refund->request_basis);
        $this->assertSame('requested', $refund->status);
        $this->assertSame('pending', $refund->finance_status);
        $this->assertSame('damaged', $refund->items->first()->inspection_disposition);
        $this->assertSame($warranty->id, $refund->items->first()->retail_warranty_id);
        $this->assertSame(1, app(RetailWarrantyService::class)->quantities($issuance->warranties)[$warranty->id]['reserved']);
        $service = app(RetailPosRefundService::class);
        $approved = $service->approve($refund, $staff->id, 250);
        $this->assertSame(0, $warranty->fresh()->refunded_quantity);
        $service->execute($approved, $staff->id);
        $this->assertSame(1, $warranty->fresh()->refunded_quantity);
        $this->assertSame('active', $warranty->fresh()->status);
    }
}
