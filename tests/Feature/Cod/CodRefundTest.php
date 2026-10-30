<?php

namespace Tests\Feature\Cod;

use App\Models\CodCollection;
use App\Models\CodRemittance;
use App\Models\CodRemittanceItem;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Models\ShopPaymentIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CodRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-refund-approval', 'user');
    }

    public function test_cod_payout_is_blocked_until_finance_settles_the_remittance(): void
    {
        [$shop, $finance, $order, $collection, $remittance] = $this->makeContext('submitted');
        $refund = $this->makeRefund($order);

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/refunds/{$refund->id}/execute-gateway-refund")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'COD remittance must be settled before the Xendit refund payout can execute.');

        Http::assertNothingSent();
        $this->assertDatabaseHas('order_refunds', [
            'id' => $refund->id,
            'payout_status' => 'not_started',
        ]);
    }

    public function test_cod_payout_requires_a_customer_refund_destination(): void
    {
        [$shop, $finance, $order, $collection, $remittance] = $this->makeContext('settled');
        $refund = $this->makeRefund($order);
        $refund->update([
            'refund_destination_type' => null,
            'refund_destination' => null,
        ]);
        Http::fake();

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/refunds/{$refund->id}/execute-gateway-refund")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Customer must provide a refund destination before the Xendit refund payout can execute.');

        Http::assertNothingSent();
        $this->assertDatabaseHas('order_refunds', [
            'id' => $refund->id,
            'status' => 'pending_approval',
            'payout_status' => 'not_started',
        ]);
    }

    public function test_cod_xendit_payout_is_async_idempotent_and_webhook_settles_once(): void
    {
        [$shop, $finance, $order, $collection, $remittance] = $this->makeContext('settled');
        $refund = $this->makeRefund($order);
        $refund->update([
            'refund_destination_type' => 'e_wallet',
            'refund_destination' => [
                'type' => 'e_wallet',
                'channel_code' => 'PH_MAYA',
                'channel' => 'Maya',
                'account_name' => 'Test Customer',
                'account_number' => '09123456789',
            ],
        ]);
        Http::fake([
            'https://api.xendit.co/v3/payouts' => Http::response([
                'payout_id' => 'cod-payout-001',
                'status' => 'ACCEPTED',
            ], 202),
        ]);

        $first = $this->actingAs($finance, 'user')
            ->postJson("/api/finance/refunds/{$refund->id}/execute-gateway-refund");
        $first->assertOk()->assertJsonPath('refund.payoutStatus', 'processing');
        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            $payload = $request->data();

            return ($payload['recipient']['account_details']['routing_type_1'] ?? null) === 'WALLET'
                && ($payload['recipient']['account_details']['routing_value_1'] ?? null) === 'PH_MAYA';
        });

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/refunds/{$refund->id}/execute-gateway-refund")
            ->assertOk()
            ->assertJsonPath('message', 'Refund execution has already started for this request.');
        Http::assertSentCount(1);

        $payload = [
            'event' => 'v3_payout.succeeded',
            'data' => [
                'payout_id' => 'cod-payout-001',
                'reference_id' => 'CODR-' . $refund->id,
                'source_amount' => 4000,
                'source_currency' => 'PHP',
                'destination_currency' => 'PHP',
            ],
        ];

        $this->postJson('/api/webhooks/xendit/payout', $payload)
            ->assertUnauthorized();

        $this->postJson('/api/webhooks/xendit/payout', $payload, [
            'x-callback-token' => 'cod-callback-token',
        ])->assertOk()->assertJsonPath('status', 'succeeded');

        $this->postJson('/api/webhooks/xendit/payout', $payload, [
            'x-callback-token' => 'cod-callback-token',
        ])->assertOk()->assertJsonPath('status', 'succeeded');

        $this->assertDatabaseHas('order_refunds', [
            'id' => $refund->id,
            'status' => 'succeeded',
            'payout_status' => 'succeeded',
            'provider_payout_id' => 'cod-payout-001',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_method' => 'cod',
            'payment_status' => 'refunded',
        ]);
    }

    public function test_finance_refund_list_reconciles_a_completed_xendit_payout(): void
    {
        [$shop, $finance, $order] = $this->makeContext('settled');
        $refund = $this->makeRefund($order);
        $refund->update([
            'status' => 'processing',
            'payout_status' => 'processing',
            'provider_payout_id' => 'cod-payout-reconciled',
            'provider_reference' => 'CODR-' . $refund->id,
        ]);

        Http::fake([
            'https://api.xendit.co/v3/payouts/cod-payout-reconciled' => Http::response([
                'payout_id' => 'cod-payout-reconciled',
                'reference_id' => 'CODR-' . $refund->id,
                'status' => 'SUCCEEDED',
            ], 200),
        ]);

        $this->actingAs($finance, 'user')
            ->getJson('/api/finance/refunds?status=all')
            ->assertOk()
            ->assertJsonPath('data.0.payoutStatus', 'succeeded');

        $this->assertDatabaseHas('order_refunds', [
            'id' => $refund->id,
            'status' => 'succeeded',
            'payout_status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'refunded',
        ]);
    }

    public function test_customer_orders_reconcile_a_completed_xendit_payout(): void
    {
        [$shop, $customer, $order] = $this->makeContext('settled');
        $refund = $this->makeRefund($order);
        $refund->update([
            'status' => 'processing',
            'payout_status' => 'processing',
            'provider_payout_id' => 'cod-payout-customer-reconciled',
            'provider_reference' => 'CODR-' . $refund->id,
        ]);

        Http::fake([
            'https://api.xendit.co/v3/payouts/cod-payout-customer-reconciled' => Http::response([
                'payout_id' => 'cod-payout-customer-reconciled',
                'reference_id' => 'CODR-' . $refund->id,
                'status' => 'SUCCEEDED',
            ], 200),
        ]);

        $this->actingAs($customer, 'user')
            ->get('/my-orders')
            ->assertOk();

        $this->assertDatabaseHas('order_refunds', [
            'id' => $refund->id,
            'status' => 'succeeded',
            'payout_status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => 'refunded',
        ]);
    }

    public function test_cod_wallet_payout_includes_customer_mobile_and_shipping_address(): void
    {
        [$shop, $finance, $order, $collection, $remittance] = $this->makeContext('settled');
        $order->update([
            'customer_phone' => '09171234567',
            'shipping_address_line' => '123 Rizal Avenue',
            'shipping_barangay' => 'Barangay 1',
            'shipping_city' => 'Manila',
            'shipping_province' => 'Metro Manila',
            'shipping_region' => 'NCR',
            'shipping_postal_code' => '1000',
        ]);
        $refund = $this->makeRefund($order);
        $refund->update([
            'refund_destination_type' => 'e_wallet',
            'refund_destination' => [
                'type' => 'e_wallet',
                'channel_code' => 'PH_GCASH',
                'channel' => 'GCash',
                'account_name' => 'Test Customer',
                'account_number' => '09171234567',
            ],
        ]);
        Http::fake([
            'https://api.xendit.co/v3/payouts' => Http::response([
                'payout_id' => 'cod-payout-address',
                'status' => 'ACCEPTED',
            ], 202),
        ]);

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/refunds/{$refund->id}/execute-gateway-refund")
            ->assertOk();

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            $recipient = $request->data()['recipient'] ?? [];
            $address = $recipient['address'] ?? [];

            return ($recipient['details']['personal_mobile_number'] ?? null) === '09171234567'
                && ($address['street_line_1'] ?? null) === '123 Rizal Avenue, Barangay 1'
                && ($address['city'] ?? null) === 'Manila'
                && ($address['province_state'] ?? null) === 'Metro Manila'
                && ($address['postal_code'] ?? null) === '1000';
        });
    }

    public function test_cod_payout_removes_shipping_from_a_legacy_full_refund_amount(): void
    {
        [$shop, $finance, $order, $collection, $remittance] = $this->makeContext('settled');
        $order->update([
            'total_amount' => '1339.29',
            'shipping_fee' => '178.00',
            'vat_amount' => '160.71',
        ]);
        $collection->update([
            'expected_amount' => '1678.00',
            'collected_amount' => '1678.00',
        ]);
        $remittance->update([
            'expected_amount' => '1678.00',
            'submitted_amount' => '1678.00',
            'received_amount' => '1678.00',
        ]);
        $refund = $this->makeRefund($order);
        $refund->update(['amount' => '1678.00']);

        Http::fake([
            'https://api.xendit.co/v3/payouts' => Http::response([
                'payout_id' => 'cod-payout-without-shipping',
                'status' => 'ACCEPTED',
            ], 202),
        ]);

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/refunds/{$refund->id}/execute-gateway-refund")
            ->assertOk()
            ->assertJsonPath('refund.refundAmountValue', 1500)
            ->assertJsonPath('refund.payoutAmountValue', 1500);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return ($request->data()['payout_details']['source_amount'] ?? null) === 150000;
        });

        $this->assertDatabaseHas('order_refunds', [
            'id' => $refund->id,
            'amount' => '1500.00',
        ]);
    }

    public function test_xendit_rejection_returns_a_safe_provider_diagnostic(): void
    {
        [$shop, $finance, $order, $collection, $remittance] = $this->makeContext('settled');
        $refund = $this->makeRefund($order);
        $refund->update([
            'refund_destination' => [
                'type' => 'gcash',
                'channel_code' => 'PH_GCASH',
                'channel' => 'GCash',
                'account_name' => 'Test Customer',
                'account_number' => '09123456789',
            ],
        ]);

        Http::fake([
            'https://api.xendit.co/v3/payouts' => Http::response([
                'error_code' => 'API_VALIDATION_ERROR',
                'message' => 'Inputs are failing validation.',
                'errors' => [[
                    'path' => 'recipient.account_details.account_number',
                    'message' => 'Invalid destination account.',
                ]],
            ], 400),
        ]);

        $response = $this->actingAs($finance, 'user')
            ->postJson("/api/finance/refunds/{$refund->id}/execute-gateway-refund")
            ->assertUnprocessable();

        $response->assertJsonPath('message', 'Xendit rejected the COD payout details. Verify the selected channel and account number, then retry the payout.');
        $this->assertStringNotContainsString('API_VALIDATION_ERROR', $response->json('message'));
        $this->assertStringNotContainsString('recipient.account_details.account_number', $response->json('message'));
        $this->assertStringNotContainsString('09123456789', $response->json('message'));
        $this->assertDatabaseHas('order_refunds', [
            'id' => $refund->id,
            'payout_failure_code' => 'API_VALIDATION_ERROR',
            'payout_failure_message' => 'Xendit rejected the COD payout details. Verify the selected channel and account number, then retry the payout.',
        ]);

        $refund->update([
            'payout_failure_message' => 'Xendit rejected the COD payout details (API_VALIDATION_ERROR, field: recipient.account_details.account_number). Verify the selected channel and account number, then retry the payout.',
        ]);
        $this->actingAs($finance, 'user')
            ->getJson('/api/finance/refunds?status=all')
            ->assertOk()
            ->assertJsonPath('data.0.payoutFailureMessage', 'Xendit rejected the COD payout details. Verify the selected channel and account number, then retry the payout.');
    }

    public function test_finance_refund_list_does_not_select_removed_order_total_columns(): void
    {
        [, $finance, $order] = $this->makeContext('settled');
        $this->makeRefund($order);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($finance, 'user')
            ->getJson('/api/finance/refunds?status=all')
            ->assertOk();

        $orderSelect = collect($queries)->first(static function (string $sql): bool {
            $normalizedSql = strtolower($sql);

            return str_contains($normalizedSql, 'orders')
                && str_contains($normalizedSql, 'order_number')
                && str_contains($normalizedSql, 'total_amount');
        });

        $this->assertIsString($orderSelect);
        $this->assertDoesNotMatchRegularExpression('/["`]total["`]/i', $orderSelect);
        $this->assertDoesNotMatchRegularExpression('/["`]grand_total["`]/i', $orderSelect);
    }

    public function test_finance_can_explicitly_reveal_a_cod_destination_without_leaking_it_in_the_list(): void
    {
        [$shop, $finance, $order, $collection, $remittance] = $this->makeContext('settled');
        $refund = $this->makeRefund($order);
        $refund->update([
            'refund_destination_type' => 'e_wallet',
            'refund_destination' => [
                'type' => 'e_wallet',
                'channel_code' => 'PH_GCASH',
                'channel' => 'GCash',
                'account_name' => 'Test Customer',
                'account_number' => '09123456789',
            ],
        ]);

        $this->actingAs($finance, 'user')
            ->getJson('/api/finance/refunds?status=all')
            ->assertOk()
            ->assertJsonPath('data.0.refundDestination.account_number', '*******6789')
            ->assertJsonMissing(['account_number' => '09123456789']);

        $this->actingAs($finance, 'user')
            ->getJson("/api/finance/refunds/{$refund->id}/destination/reveal")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('destination.account_number', '09123456789')
            ->assertJsonPath('destination.channel_code', 'PH_GCASH');
    }

    public function test_cod_refund_reservation_requires_collected_cash_and_bounds_amount(): void
    {
        [$shop, $finance, $order, $collection, $remittance] = $this->makeContext('settled');
        $collection->update(['collected_amount' => '50.00']);

        $service = app(\App\Services\OrderRefundService::class);
        $payload = [
            'shop_owner_id' => $shop->id,
            'customer_id' => $order->customer_id,
            'flow_type' => 'request_approval',
            'status' => 'pending_approval',
            'payment_gateway' => 'xendit',
            'amount' => 60.00,
            'currency' => 'PHP',
            'requested_refund_method' => 'original_payment_method',
            'refund_destination_type' => 'gcash',
            'refund_destination' => ['type' => 'gcash', 'account_name' => 'Test Customer', 'number' => '09123456789'],
            'refund_provider' => 'xendit',
            'idempotency_key' => 'cod-refund-over-limit',
            'requested_at' => now(),
        ];

        $collection->update(['status' => CodCollection::STATUS_PENDING]);
        $payload['amount'] = 40.00;
        $payload['idempotency_key'] = 'cod-refund-before-collection';
        $result = $service->reserveOrderRefund($order, $payload);
        $this->assertSame('collision', $result['result']);

        $collection->update(['status' => CodCollection::STATUS_SETTLED]);
        $payload['amount'] = 60.00;
        $payload['idempotency_key'] = 'cod-refund-over-limit';
        $result = $service->reserveOrderRefund($order, $payload);

        $this->assertSame('collision', $result['result']);
        $this->assertDatabaseCount('order_refunds', 0);
    }

    private function makeContext(string $remittanceStatus): array
    {
        $shop = ShopOwner::factory()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);
        $finance->givePermissionTo('access-refund-approval');
        $this->clockInEmployee($finance);

        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $finance->id,
            'status' => 'completed',
            'total_amount' => '100.00',
            'shipping_fee' => '0.00',
            'vat_amount' => '0.00',
            'payment_method' => 'cod',
            'payment_status' => 'paid',
        ]);
        $collection = CodCollection::create([
            'shop_owner_id' => $shop->id,
            'order_id' => $order->id,
            'expected_amount' => '100.00',
            'collected_amount' => '100.00',
            'status' => $remittanceStatus === 'settled' ? CodCollection::STATUS_SETTLED : CodCollection::STATUS_CASH_COLLECTED,
            'collection_reference' => 'COD-COLLECTION-' . $order->order_number,
            'collection_idempotency_key' => 'cod-refund-collection-' . $order->id,
            'collected_at' => now(),
        ]);
        $remittance = CodRemittance::create([
            'shop_owner_id' => $shop->id,
            'reference' => 'COD-REM-' . $order->id,
            'expected_amount' => '100.00',
            'submitted_amount' => '100.00',
            'received_amount' => $remittanceStatus === 'settled' ? '100.00' : null,
            'variance_amount' => $remittanceStatus === 'settled' ? '0.00' : null,
            'status' => $remittanceStatus,
            'idempotency_key' => 'cod-refund-remittance-' . $order->id,
            'submitted_at' => now(),
            'confirmed_at' => $remittanceStatus === 'settled' ? now() : null,
        ]);
        CodRemittanceItem::create([
            'cod_remittance_id' => $remittance->id,
            'cod_collection_id' => $collection->id,
            'expected_amount' => '100.00',
        ]);
        $collection->update(['settled_at' => $remittanceStatus === 'settled' ? now() : null]);

        ShopPaymentIntegration::create([
            'shop_owner_id' => $shop->id,
            'provider' => ShopPaymentIntegration::PROVIDER_XENDIT,
            'purpose' => ShopPaymentIntegration::PURPOSE_SUPPLIER_PAYOUT,
            'environment' => 'test',
            'secret_key' => 'xnd_test_customer_refund_secret',
            'webhook_callback_token' => 'cod-callback-token',
            'status' => ShopPaymentIntegration::STATUS_CONNECTED,
        ]);

        return [$shop, $finance, $order, $collection, $remittance];
    }

    private function makeRefund(Order $order): OrderRefund
    {
        return OrderRefund::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'shop_owner_id' => $order->shop_owner_id,
            'flow_type' => 'request_approval',
            'status' => 'pending_approval',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'return_status' => 'received',
            'payment_gateway' => 'xendit',
            'amount' => '40.00',
            'currency' => 'PHP',
            'requested_refund_method' => 'original_payment_method',
            'refund_destination_type' => 'gcash',
            'refund_destination' => [
                'type' => 'gcash',
                'account_name' => 'Test Customer',
                'number' => '09123456789',
            ],
            'refund_provider' => 'xendit',
            'idempotency_key' => 'cod-refund-' . $order->id,
            'requested_at' => now(),
        ]);
    }
}
