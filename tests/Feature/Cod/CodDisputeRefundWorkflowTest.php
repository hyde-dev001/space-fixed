<?php

namespace Tests\Feature\Cod;

use App\Enums\OrderStatus;
use App\Models\DeliveryDispute;
use App\Models\CodCollection;
use App\Models\CodRemittance;
use App\Models\CodRemittanceItem;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ProcurementSettings;
use App\Models\ShopOwner;
use App\Models\ShopPaymentIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CodDisputeRefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_can_create_a_cod_refund_before_collection_and_finance_notifies_the_customer(): void
    {
        [$shop, $dispatcher, $finance, $customer, $order] = $this->makeContext();
        $dispute = DeliveryDispute::query()->create([
            'shop_owner_id' => $shop->id,
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'status' => 'open',
            'reason' => 'damaged',
            'reported_at' => now(),
        ]);

        $this->actingAs($dispatcher, 'user')
            ->postJson("/api/logistics/delivery-disputes/{$dispute->id}/investigate")
            ->assertOk();

        $resolved = $this->actingAs($dispatcher, 'user')
            ->postJson("/api/logistics/delivery-disputes/{$dispute->id}/resolve", [
                'resolution' => 'refund_required',
                'resolution_note' => 'COD refund is required after investigation.',
            ])
            ->assertOk()
            ->assertJsonPath('result', 'resolved');

        $refundId = (int) $resolved->json('refund.id');

        $this->assertDatabaseHas('order_refunds', [
            'id' => $refundId,
            'order_id' => $order->id,
            'status' => 'requested',
            'requires_owner_approval' => 1,
            'finance_status' => 'pending',
            'payment_gateway' => 'xendit',
            'refund_destination_type' => null,
        ]);

        $staff = $this->makeStaff($shop);
        $this->actingAs($staff, 'user')
            ->postJson("/api/staff/orders/{$order->id}/refund/approve")
            ->assertOk();

        $this->assertDatabaseHas('order_refunds', [
            'id' => $refundId,
            'status' => 'pending_approval',
            'shop_owner_status' => 'pending',
        ]);

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/refunds/{$refundId}/approve")
            ->assertOk()
            ->assertJsonPath('refund.financeStatus', 'approved_initial');

        $this->actingAs($shop, 'shop_owner')
            ->getJson('/api/shop-owner/refunds?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $refundId);

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/shop-owner/refunds/{$refundId}/approve")
            ->assertOk()
            ->assertJsonPath('refund.shopOwnerStatus', 'approved')
            ->assertJsonPath('refund.financeStatus', 'approved_initial');

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/refunds/{$refundId}/approve")
            ->assertOk()
            ->assertJsonPath('refund.financeStatus', 'approved');

        $this->assertDatabaseHas('order_refunds', [
            'id' => $refundId,
            'return_status' => 'pending_customer_shipment',
            'return_source' => 'staff',
        ]);

        $this->actingAs($customer, 'user')
            ->get('/my-orders')
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.0.refund_stage.return_source', 'staff')
                ->where('orders.0.refund_stage.can_mark_return_shipped', false));

        $this->actingAs($customer, 'user')
            ->postJson("/orders/refunds/{$refundId}/mark-shipped-return", [
                'delivery_method' => 'third_party',
                'tracking_number' => 'CUSTOMER-MUST-NOT-SHIP',
                'carrier' => 'LBC',
                'tracking_link' => 'https://track.example/CUSTOMER-MUST-NOT-SHIP',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Staff/Logistics must arrange the return pickup before the customer hands over the item.');

        $notification = Notification::query()
            ->where('user_id', $customer->id)
            ->where('title', 'Refund Approved')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('/my-orders', $notification->action_url);
        $this->assertSame($refundId, (int) data_get($notification->data, 'refund_id'));
        $this->assertFalse((bool) data_get($notification->data, 'awaiting_refund_destination'));
    }

    public function test_customer_can_load_live_cod_refund_destination_channels(): void
    {
        [$shop, , , $customer, $order] = $this->makeContext();
        $refund = OrderRefund::query()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $shop->id,
            'flow_type' => 'request_approval',
            'status' => 'pending_approval',
            'shop_owner_status' => 'approved',
            'requires_owner_approval' => false,
            'finance_status' => 'approved',
            'return_status' => 'received',
            'payment_gateway' => 'xendit',
            'amount' => 1000,
            'currency' => 'PHP',
            'refund_provider' => 'xendit',
            'idempotency_key' => 'cod-dispute-channels-' . $order->id,
            'requested_at' => now(),
        ]);
        $this->settleCodCollection($shop, $order);
        ShopPaymentIntegration::query()->create([
            'shop_owner_id' => $shop->id,
            'provider' => ShopPaymentIntegration::PROVIDER_XENDIT,
            'purpose' => ShopPaymentIntegration::PURPOSE_SUPPLIER_PAYOUT,
            'environment' => 'test',
            'secret_key' => 'xnd_test_customer_channels',
            'webhook_callback_token' => 'callback-token',
            'status' => ShopPaymentIntegration::STATUS_CONNECTED,
        ]);
        Http::fake([
            'https://api.xendit.co/payouts_channels*' => Http::response([
                'data' => [
                    [
                        'channel_code' => 'PH_BDO',
                        'channel_name' => 'Banco De Oro Unibank, Inc.',
                        'channel_category' => 'BANK',
                        'currency' => 'PHP',
                    ],
                    [
                        'channel_code' => 'PH_MAYA',
                        'channel_name' => 'Maya',
                        'channel_category' => 'EWALLET',
                        'currency' => 'PHP',
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($customer, 'user')
            ->getJson("/orders/refunds/{$refund->id}/cod-destination-options")
            ->assertOk()
            ->assertJsonFragment([
                'channel_code' => 'PH_BDO',
                'channel_name' => 'Banco De Oro Unibank, Inc.',
                'channel_category' => 'BANK',
                'currency' => 'PHP',
            ])
            ->assertJsonFragment([
                'channel_code' => 'PH_MAYA',
                'channel_name' => 'Maya',
                'channel_category' => 'EWALLET',
                'currency' => 'PHP',
            ]);

        $this->actingAs(User::factory()->create(), 'user')
            ->getJson("/orders/refunds/{$refund->id}/cod-destination-options")
            ->assertNotFound();
    }

    public function test_cod_refund_waits_for_staff_acceptance_before_finance_visibility(): void
    {
        [$shop, $dispatcher, $finance, $customer, $order] = $this->makeContext();
        $staff = $this->makeStaff($shop);

        $dispute = DeliveryDispute::query()->create([
            'shop_owner_id' => $shop->id,
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'status' => 'investigating',
            'reason' => 'damaged',
            'reported_at' => now(),
        ]);

        $refundId = $this->actingAs($dispatcher, 'user')
            ->postJson("/api/logistics/delivery-disputes/{$dispute->id}/resolve", [
                'resolution' => 'refund_required',
                'resolution_note' => 'Staff must review this COD refund first.',
            ])
            ->assertOk()
            ->json('refund.id');

        $financeQueue = $this->actingAs($finance, 'user')
            ->getJson('/api/finance/refunds?status=pending')
            ->assertOk()
            ->json('data');
        $this->assertNotContains($refundId, collect($financeQueue)->pluck('id')->all());

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/refunds/{$refundId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Staff acceptance is required before Finance initial approval.');

        $this->actingAs($staff, 'user')
            ->postJson("/api/staff/orders/{$order->id}/refund/approve")
            ->assertOk();

        $this->assertDatabaseHas('order_refunds', [
            'id' => $refundId,
            'status' => 'pending_approval',
            'shop_owner_status' => 'pending',
        ]);

        $financeQueue = $this->actingAs($finance, 'user')
            ->getJson('/api/finance/refunds?status=pending')
            ->assertOk()
            ->json('data');
        $this->assertContains($refundId, collect($financeQueue)->pluck('id')->all());

        $this->actingAs($shop, 'shop_owner')
            ->getJson('/api/shop-owner/refunds?status=pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/refunds/{$refundId}/approve")
            ->assertOk()
            ->assertJsonPath('refund.financeStatus', 'approved_initial');

        $financeQueue = $this->actingAs($finance, 'user')
            ->getJson('/api/finance/refunds?status=pending')
            ->assertOk()
            ->json('data');
        $this->assertNotContains($refundId, collect($financeQueue)->pluck('id')->all());

        $this->actingAs($shop, 'shop_owner')
            ->getJson('/api/shop-owner/refunds?status=pending')
            ->assertOk()
            ->assertJsonFragment(['id' => $refundId]);

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/shop-owner/refunds/{$refundId}/approve")
            ->assertOk()
            ->assertJsonPath('refund.shopOwnerStatus', 'approved')
            ->assertJsonPath('refund.financeStatus', 'approved_initial');

        $financeQueue = $this->actingAs($finance, 'user')
            ->getJson('/api/finance/refunds?status=pending')
            ->assertOk()
            ->json('data');
        $this->assertContains($refundId, collect($financeQueue)->pluck('id')->all());
    }

    public function test_cod_refund_skips_shop_owner_when_refund_approval_is_disabled(): void
    {
        [$shop, $dispatcher, $finance, $customer, $order] = $this->makeContext(false);
        $staff = $this->makeStaff($shop);
        $dispute = DeliveryDispute::query()->create([
            'shop_owner_id' => $shop->id,
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'status' => 'investigating',
            'reason' => 'damaged',
            'reported_at' => now(),
        ]);

        $refundId = $this->actingAs($dispatcher, 'user')
            ->postJson("/api/logistics/delivery-disputes/{$dispute->id}/resolve", [
                'resolution' => 'refund_required',
                'resolution_note' => 'Shop owner refund approval is disabled for this shop.',
            ])
            ->assertOk()
            ->json('refund.id');

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/refunds/{$refundId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Staff acceptance is required before Finance initial approval.');

        $this->actingAs($staff, 'user')
            ->postJson("/api/staff/orders/{$order->id}/refund/approve")
            ->assertOk();

        $this->assertDatabaseHas('order_refunds', [
            'id' => $refundId,
            'requires_owner_approval' => 0,
            'shop_owner_status' => 'pending',
        ]);

        $this->actingAs($shop, 'shop_owner')
            ->getJson('/api/shop-owner/refunds?status=pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($shop, 'shop_owner')
            ->getJson("/api/shop-owner/refunds/{$refundId}")
            ->assertNotFound();

        $this->actingAs($shop, 'shop_owner')
            ->postJson("/api/shop-owner/refunds/{$refundId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shop owner approval is not required by policy for this refund request.');

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/refunds/{$refundId}/approve")
            ->assertOk()
            ->assertJsonPath('refund.financeStatus', 'approved');
    }

    public function test_customer_can_submit_a_cod_refund_destination_after_finance_approval(): void
    {
        [$shop, , , $customer, $order] = $this->makeContext();
        $refund = OrderRefund::query()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $shop->id,
            'flow_type' => 'request_approval',
            'status' => 'pending_approval',
            'shop_owner_status' => 'approved',
            'requires_owner_approval' => false,
            'finance_status' => 'approved',
            'return_status' => 'received',
            'payment_gateway' => 'xendit',
            'amount' => 1000,
            'currency' => 'PHP',
            'requested_refund_method' => 'customer_selected',
            'refund_provider' => 'xendit',
            'idempotency_key' => 'cod-dispute-destination-' . $order->id,
            'requested_at' => now(),
        ]);
        $this->settleCodCollection($shop, $order);
        ShopPaymentIntegration::query()->create([
            'shop_owner_id' => $shop->id,
            'provider' => ShopPaymentIntegration::PROVIDER_XENDIT,
            'purpose' => ShopPaymentIntegration::PURPOSE_SUPPLIER_PAYOUT,
            'environment' => 'test',
            'secret_key' => 'xnd_test_customer_destination',
            'webhook_callback_token' => 'callback-token',
            'status' => ShopPaymentIntegration::STATUS_CONNECTED,
        ]);
        Http::fake([
            'https://api.xendit.co/payouts_channels*' => Http::response([
                'data' => [[
                    'channel_code' => 'PH_MAYA',
                    'channel_name' => 'Maya',
                    'channel_category' => 'EWALLET',
                    'currency' => 'PHP',
                ]],
            ], 200),
        ]);

        $this->actingAs($customer, 'user')
            ->postJson("/orders/refunds/{$refund->id}/cod-destination", [
                'destination_type' => 'e_wallet',
                'channel_code' => 'PH_UNSUPPORTED',
                'account_name' => 'Maria Santos',
                'account_number' => '09123456789',
            ])
            ->assertUnprocessable();

        $this->actingAs($customer, 'user')
            ->postJson("/orders/refunds/{$refund->id}/cod-destination", [
                'destination_type' => 'e_wallet',
                'channel_code' => 'PH_MAYA',
                'account_name' => 'Maria Santos',
                'account_number' => '09123456789',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('refund.refund_destination_type', 'e_wallet')
            ->assertJsonPath('refund.refund_destination.channel', 'Maya')
            ->assertJsonPath('refund.refund_destination.account_number', '*******6789');

        $storedDestination = $refund->fresh()->refund_destination;
        $this->assertSame('e_wallet', $storedDestination['type']);
        $this->assertSame('PH_MAYA', $storedDestination['channel_code']);
        $this->assertSame('09123456789', $storedDestination['account_number']);

        $this->actingAs($customer, 'user')
            ->getJson("/orders/refunds/{$refund->id}/cod-destination-options")
            ->assertOk()
            ->assertJsonPath('e_wallets.0.channel_code', 'PH_MAYA');

        $this->actingAs($customer, 'user')
            ->postJson("/orders/refunds/{$refund->id}/cod-destination", [
                'destination_type' => 'e_wallet',
                'channel_code' => 'PH_MAYA',
                'account_name' => 'Ana Reyes',
                'account_number' => '09987654321',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('refund.refund_destination.account_name', 'Ana Reyes')
            ->assertJsonPath('refund.refund_destination.account_number', '*******4321');

        $updatedDestination = $refund->fresh()->refund_destination;
        $this->assertSame('Ana Reyes', $updatedDestination['account_name']);
        $this->assertSame('09987654321', $updatedDestination['account_number']);

        $this->actingAs(User::factory()->create(), 'user')
            ->postJson("/orders/refunds/{$refund->id}/cod-destination", [
                'destination_type' => 'gcash',
                'account_name' => 'Other Customer',
                'account_number' => '09111111111',
            ])
            ->assertNotFound();
    }

    public function test_customer_cannot_submit_a_cod_refund_destination_before_finance_approval(): void
    {
        [$shop, , , $customer, $order] = $this->makeContext();
        $refund = OrderRefund::query()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $shop->id,
            'flow_type' => 'request_approval',
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'pending',
            'return_status' => 'awaiting_approval',
            'payment_gateway' => 'xendit',
            'amount' => 1000,
            'currency' => 'PHP',
            'requested_refund_method' => 'customer_selected',
            'refund_provider' => 'xendit',
            'idempotency_key' => 'cod-dispute-destination-blocked-' . $order->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($customer, 'user')
            ->postJson("/orders/refunds/{$refund->id}/cod-destination", [
                'destination_type' => 'gcash',
                'account_name' => 'Maria Santos',
                'account_number' => '09123456789',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'COD refund is not ready for payout yet.');

        $this->assertNull($refund->fresh()->refund_destination);
    }

    public function test_customer_can_provide_a_destination_after_a_failed_cod_payout_attempt(): void
    {
        [$shop, , , $customer, $order] = $this->makeContext();
        $refund = OrderRefund::query()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $shop->id,
            'flow_type' => 'request_approval',
            'status' => 'failed',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'received',
            'payment_gateway' => 'xendit',
            'amount' => 1000,
            'currency' => 'PHP',
            'refund_provider' => 'xendit',
            'payout_status' => 'failed',
            'idempotency_key' => 'cod-dispute-destination-retry-' . $order->id,
            'requested_at' => now(),
        ]);
        $this->settleCodCollection($shop, $order);
        ShopPaymentIntegration::query()->create([
            'shop_owner_id' => $shop->id,
            'provider' => ShopPaymentIntegration::PROVIDER_XENDIT,
            'purpose' => ShopPaymentIntegration::PURPOSE_SUPPLIER_PAYOUT,
            'environment' => 'test',
            'secret_key' => 'xnd_test_customer_destination_retry',
            'webhook_callback_token' => 'callback-token',
            'status' => ShopPaymentIntegration::STATUS_CONNECTED,
        ]);
        Http::fake([
            'https://api.xendit.co/payouts_channels*' => Http::response([
                'data' => [[
                    'channel_code' => 'PH_MAYA',
                    'channel_name' => 'Maya',
                    'channel_category' => 'EWALLET',
                    'currency' => 'PHP',
                ]],
            ], 200),
        ]);

        $this->actingAs($customer, 'user')
            ->get('/my-orders')
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.0.refund_stage.awaiting_refund_destination', true));

        $this->actingAs($customer, 'user')
            ->getJson("/orders/refunds/{$refund->id}/cod-destination-options")
            ->assertOk();

        $this->actingAs($customer, 'user')
            ->postJson("/orders/refunds/{$refund->id}/cod-destination", [
                'destination_type' => 'e_wallet',
                'channel_code' => 'PH_MAYA',
                'account_name' => 'Maria Santos',
                'account_number' => '09123456789',
            ])
            ->assertOk()
            ->assertJsonPath('refund.refund_destination_type', 'e_wallet');
    }

    public function test_customer_can_open_my_orders_with_a_cod_order(): void
    {
        [, , , $customer] = $this->makeContext();

        $this->actingAs($customer, 'user')
            ->get('/my-orders')
            ->assertOk();
    }

    public function test_customer_cannot_start_a_new_delivery_report_after_a_refund_payout_failure(): void
    {
        [$shop, , , $customer, $order] = $this->makeContext();
        OrderRefund::query()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $shop->id,
            'flow_type' => 'request_approval',
            'status' => 'failed',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'received',
            'payment_gateway' => 'xendit',
            'amount' => 1000,
            'currency' => 'PHP',
            'payout_status' => 'failed',
            'idempotency_key' => 'failed-cod-refund-' . $order->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($customer, 'user')
            ->postJson("/orders/{$order->id}/delivery-disputes", [
                'reason' => 'item_not_received',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order')
            ->assertJsonPath('errors.order.0', 'This order already has an active or completed refund workflow.');
    }

    public function test_customer_cannot_start_a_new_delivery_report_while_payout_is_processing(): void
    {
        [$shop, , , $customer, $order] = $this->makeContext();
        OrderRefund::query()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $shop->id,
            'flow_type' => 'request_approval',
            'status' => 'approved',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'received',
            'payment_gateway' => 'xendit',
            'amount' => 1000,
            'currency' => 'PHP',
            'payout_status' => 'processing',
            'idempotency_key' => 'processing-cod-refund-' . $order->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($customer, 'user')
            ->postJson("/orders/{$order->id}/delivery-disputes", [
                'reason' => 'item_not_received',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order')
            ->assertJsonPath('errors.order.0', 'This order already has an active or completed refund workflow.');
    }

    public function test_finance_can_view_cod_dispute_evidence_on_the_refund_request(): void
    {
        Storage::fake('local');
        [$shop, $dispatcher, $finance, $customer, $order] = $this->makeContext();
        $path = "delivery-dispute-evidence/order-{$order->id}/proof.jpg";
        $videoPath = "delivery-dispute-evidence/order-{$order->id}/opening.mp4";
        Storage::disk('local')->put($path, 'fake-image');
        Storage::disk('local')->put($videoPath, 'fake-video');

        $dispute = DeliveryDispute::query()->create([
            'shop_owner_id' => $shop->id,
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'status' => 'open',
            'reason' => 'damaged',
            'evidence_media' => [
                [
                    'id' => 'proof-1',
                    'path' => $path,
                    'kind' => 'image',
                    'mime_type' => 'image/jpeg',
                    'original_name' => 'proof.jpg',
                    'size' => 10,
                ],
                [
                    'id' => 'proof-2',
                    'path' => $videoPath,
                    'kind' => 'video',
                    'mime_type' => 'video/mp4',
                    'original_name' => 'opening.mp4',
                    'size' => 10,
                ],
            ],
            'reported_at' => now(),
        ]);

        $this->actingAs($dispatcher, 'user')
            ->postJson("/api/logistics/delivery-disputes/{$dispute->id}/investigate")
            ->assertOk();

        $refundId = $this->actingAs($dispatcher, 'user')
            ->postJson("/api/logistics/delivery-disputes/{$dispute->id}/resolve", [
                'resolution' => 'refund_required',
                'resolution_note' => 'Evidence is available for Finance review.',
            ])
            ->assertOk()
            ->json('refund.id');

        $refundRecord = OrderRefund::query()->findOrFail($refundId);
        $this->assertCount(2, $refundRecord->evidence_media);
        $refundRecord->update(['evidence_media' => []]);

        $staff = $this->makeStaff($shop);
        $this->actingAs($staff, 'user')
            ->postJson("/api/staff/orders/{$order->id}/refund/approve")
            ->assertOk();

        $refunds = $this->actingAs($finance, 'user')
            ->getJson('/api/finance/refunds?status=pending')
            ->assertOk()
            ->json('data');
        $request = collect($refunds)->firstWhere('id', $refundId);

        $this->assertCount(2, $request['media']);
        $evidencePath = parse_url($request['media'][0], PHP_URL_PATH);
        $this->assertSame(
            "/api/logistics/delivery-disputes/{$dispute->id}/evidence/proof-1",
            $evidencePath,
        );
        $this->assertStringContainsString('media_kind=video', $request['media'][1]);

        $this->actingAs($finance, 'user')
            ->get($evidencePath)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    private function makeStaff(ShopOwner $shop): User
    {
        Permission::findOrCreate('access-staff-job-orders', 'user');
        $staff = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'Staff',
        ]);
        $staff->givePermissionTo('access-staff-job-orders');
        $this->clockInEmployee($staff);

        return $staff;
    }

    private function settleCodCollection(ShopOwner $shop, Order $order): void
    {
        $collection = CodCollection::query()->create([
            'shop_owner_id' => $shop->id,
            'order_id' => $order->id,
            'expected_amount' => 1000,
            'collected_amount' => 1000,
            'status' => CodCollection::STATUS_SETTLED,
            'collection_reference' => 'COL-' . $order->id,
            'collection_idempotency_key' => 'COL-KEY-' . $order->id,
            'collected_at' => now(),
            'settled_at' => now(),
        ]);
        $remittance = CodRemittance::query()->create([
            'shop_owner_id' => $shop->id,
            'reference' => 'REM-' . $order->id,
            'expected_amount' => 1000,
            'submitted_amount' => 1000,
            'received_amount' => 1000,
            'variance_amount' => 0,
            'status' => CodRemittance::STATUS_SETTLED,
            'idempotency_key' => 'REM-KEY-' . $order->id,
            'submitted_at' => now(),
            'confirmed_at' => now(),
        ]);
        CodRemittanceItem::query()->create([
            'cod_remittance_id' => $remittance->id,
            'cod_collection_id' => $collection->id,
            'expected_amount' => 1000,
        ]);
    }

    private function makeContext(bool $refundApprovalEnabled = true): array
    {
        $shop = ShopOwner::factory()->create([
            'registration_type' => 'individual',
        ]);
        ProcurementSettings::query()->create([
            'shop_owner_id' => $shop->id,
            'settings_json' => [
                'approval_pages' => [
                    'refund_approval' => ['enabled' => $refundApprovalEnabled],
                ],
            ],
        ]);

        Permission::findOrCreate('view-logistics-shipments', 'user');
        Permission::findOrCreate('resolve-logistics-exceptions', 'user');
        Permission::findOrCreate('access-refund-approval', 'user');

        $dispatcher = User::factory()->create(['shop_owner_id' => $shop->id]);
        $dispatcher->givePermissionTo(['view-logistics-shipments', 'resolve-logistics-exceptions']);
        $this->clockInEmployee($dispatcher);

        $finance = User::factory()->create([
            'shop_owner_id' => $shop->id,
            'role' => 'Finance',
        ]);
        $finance->givePermissionTo('access-refund-approval');
        $this->clockInEmployee($finance);

        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::DELIVERED,
            'carrier_company' => 'Shop-owned logistics',
            'customer_receipt_status' => 'disputed',
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'total_amount' => 1000,
        ]);

        return [$shop, $dispatcher, $finance, $customer, $order];
    }
}
