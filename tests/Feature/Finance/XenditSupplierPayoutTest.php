<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptItem;
use App\Models\ShopOwner;
use App\Models\ShopPaymentIntegration;
use App\Models\Supplier;
use App\Models\SupplierPaymentAttempt;
use App\Models\SupplierPaymentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class XenditSupplierPayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-finance-expenses', 'user');
        config()->set('services.xendit.base_url', 'https://api.xendit.co');
    }

    public function test_shop_owner_can_connect_xendit_without_exposing_credentials(): void
    {
        Http::fake([
            'https://api.xendit.co/balance*' => Http::response(['balance' => 100000], 200),
        ]);
        $shop = ShopOwner::factory()->create();
        $secretKey = 'xnd_test_secret_key_123456789';
        $callbackToken = 'xendit-callback-token';

        $response = $this->actingAs($shop, 'shop_owner')->postJson('/shop-owner/settings/xendit-key', [
            'environment' => 'test',
            'secret_key' => $secretKey,
            'callback_token' => $callbackToken,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('data.secret_key')
            ->assertJsonMissingPath('data.callback_token');
        $this->assertStringNotContainsString($secretKey, $response->getContent());
        $this->assertStringNotContainsString($callbackToken, $response->getContent());

        $integration = ShopPaymentIntegration::query()->sole();
        $this->assertSame('connected', $integration->status);
        $this->assertSame('test', $integration->environment);
        $this->assertNotSame($secretKey, DB::table('shop_payment_integrations')->value('secret_key'));
        $this->assertNotSame($callbackToken, DB::table('shop_payment_integrations')->value('webhook_callback_token'));
    }

    public function test_connection_error_explains_balance_read_permission(): void
    {
        Http::fake([
            'https://api.xendit.co/balance*' => Http::response(['error_code' => 'FORBIDDEN'], 403),
        ]);
        $shop = ShopOwner::factory()->create();

        $this->actingAs($shop, 'shop_owner')->postJson('/shop-owner/settings/xendit-key', [
            'environment' => 'test',
            'secret_key' => 'xnd_test_secret_key_123456789',
            'callback_token' => 'xendit-callback-token',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'XENDIT_CONNECTION_FAILED')
            ->assertJsonPath('message', 'Xendit rejected this key. Grant Money-Out Read access for balance verification and Money-Out Write access for supplier payouts.');
    }

    public function test_finance_creates_one_server_amount_xendit_payout_and_keeps_it_processing_until_webhook(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payouts' => Http::response([
                'payout_id' => 'payout-001',
                'status' => 'ACCEPTED',
                'reference_id' => 'server-reference',
            ], 202),
        ]);
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $this->connect($shop);

        $response = $this->actingAs($finance, 'user')->postJson(
            "/api/finance/expenses/{$expense->id}/supplier-payment-attempts",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_XENDIT,
                'idempotency_key' => 'xendit-attempt-001',
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('data.status', SupplierPaymentAttempt::STATUS_PROCESSING)
            ->assertJsonPath('data.provider', 'xendit')
            ->assertJsonPath('data.external_transaction_reference', 'payout-001')
            ->assertJsonMissingPath('data.destination_snapshot');

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.xendit.co/v3/payouts'
                && $request->header('idempotency-key')[0] === 'xendit-attempt-001'
                && ($payload['payout_details']['source_amount'] ?? null) === 10000
                && ($payload['payout_details']['source_currency'] ?? null) === 'PHP'
                && ($payload['recipient']['type'] ?? null) === 'BUSINESS'
                && ($payload['recipient']['business_name'] ?? null) === 'Supplier Trading'
                && ($payload['recipient']['relationship'] ?? null) === 'SUPPLIER'
                && ($payload['recipient']['address']['province_state'] ?? null) === 'Cavite'
                && ($payload['recipient']['address']['postal_code'] ?? null) === '4117';
        });

        $this->assertDatabaseHas('supplier_payment_attempts', [
            'expense_id' => $expense->id,
            'provider' => 'xendit',
            'provider_reference' => 'payout-001',
            'status' => SupplierPaymentAttempt::STATUS_PROCESSING,
        ]);
        $this->assertDatabaseCount('finance_expense_settlements', 0);
    }

    public function test_individual_payout_uses_the_immutable_recipient_snapshot(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payouts' => Http::response([
                'payout_id' => 'payout-individual',
                'status' => 'ACCEPTED',
            ], 202),
        ]);
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $supplier->paymentProfile()->update([
            'recipient_type' => SupplierPaymentProfile::RECIPIENT_INDIVIDUAL,
            'business_name' => null,
            'given_name' => 'Juanito',
            'surname' => 'Dimaguiba',
        ]);
        $this->connect($shop);

        $this->actingAs($finance, 'user')->postJson(
            "/api/finance/expenses/{$expense->id}/supplier-payment-attempts",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_XENDIT,
                'idempotency_key' => 'xendit-attempt-individual',
            ],
        )->assertCreated();

        $supplier->paymentProfile()->update(['given_name' => 'Changed']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            $recipient = $request->data()['recipient'] ?? [];

            return ($recipient['type'] ?? null) === 'INDIVIDUAL'
                && ($recipient['given_name'] ?? null) === 'Juanito'
                && ($recipient['surname'] ?? null) === 'Dimaguiba'
                && ! array_key_exists('business_name', $recipient);
        });
    }

    public function test_xendit_permission_rejection_is_actionable_instead_of_being_reported_as_a_gateway_error(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payouts' => Http::response([
                'error_code' => 'REQUEST_FORBIDDEN_ERROR',
            ], 403),
        ]);
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $this->connect($shop);

        $response = $this->actingAs($finance, 'user')->postJson(
            "/api/finance/expenses/{$expense->id}/supplier-payment-attempts",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_XENDIT,
                'idempotency_key' => 'xendit-attempt-permission-denied',
            ],
        );

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'XENDIT_PERMISSION_DENIED')
            ->assertJsonPath('message', 'Xendit rejected this payout because the shop API key does not have Money-Out Write permission. Update the key permissions, then start a new payment attempt.');
        $this->assertSame(SupplierPaymentAttempt::STATUS_FAILED, SupplierPaymentAttempt::query()->sole()->status);
        $this->assertDatabaseCount('finance_expense_settlements', 0);
    }

    public function test_xendit_validation_rejection_exposes_a_safe_provider_code_instead_of_being_reported_as_a_gateway_error(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payouts' => Http::response([
                'error_code' => 'API_VALIDATION_ERROR',
                'errors' => [[
                    'path' => 'recipient.address.postal_code',
                    'message' => 'Rejected account 1234567890 with secret xnd_test_do_not_expose',
                ]],
            ], 400),
        ]);
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $this->connect($shop);

        $response = $this->actingAs($finance, 'user')->postJson(
            "/api/finance/expenses/{$expense->id}/supplier-payment-attempts",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_XENDIT,
                'idempotency_key' => 'xendit-attempt-invalid-payout',
            ],
        );

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'XENDIT_PAYOUT_INVALID')
            ->assertJsonPath('message', 'Xendit rejected the payout details (API_VALIDATION_ERROR, field: recipient.address.postal_code). Check the supplier bank code, account number, and payout destination, then start a new payment attempt.');
        $this->assertStringNotContainsString('1234567890', $response->getContent());
        $this->assertStringNotContainsString('xnd_test_do_not_expose', $response->getContent());
        $this->assertSame(SupplierPaymentAttempt::STATUS_FAILED, SupplierPaymentAttempt::query()->sole()->status);
        $this->assertDatabaseCount('finance_expense_settlements', 0);
    }

    public function test_xendit_webhook_requires_the_shop_callback_token_and_settles_success_once(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payouts' => Http::response([
                'payout_id' => 'payout-002',
                'status' => 'ACCEPTED',
            ], 202),
        ]);
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $this->connect($shop);
        $this->startXenditAttempt($finance, $expense, 'xendit-attempt-002');

        $payload = [
            'event' => 'v3_payout.succeeded',
            'business_id' => 'xendit-business-id',
            'created' => now()->toIso8601String(),
            'data' => [
                'payout_id' => 'payout-002',
                'reference_id' => SupplierPaymentAttempt::query()->sole()->internal_reference,
                'status' => 'SUCCEEDED',
            ],
        ];

        $this->postJson('/api/webhooks/xendit/payout', $payload, [
            'x-callback-token' => 'wrong-token',
        ])->assertUnauthorized();
        $this->assertDatabaseCount('finance_expense_settlements', 0);

        $this->postJson('/api/webhooks/xendit/payout', $payload, [
            'x-callback-token' => 'xendit-callback-token',
        ])->assertOk();
        $this->postJson('/api/webhooks/xendit/payout', $payload, [
            'x-callback-token' => 'xendit-callback-token',
        ])->assertOk();

        $this->assertDatabaseHas('supplier_payment_attempts', [
            'provider_reference' => 'payout-002',
            'status' => SupplierPaymentAttempt::STATUS_SUCCEEDED,
        ]);
        $this->assertDatabaseHas('finance_expense_settlements', [
            'expense_id' => $expense->id,
            'source' => ExpenseSettlement::SOURCE_SUPPLIER_XENDIT_PAYOUT,
            'source_reference' => 'supplier-xendit-payout:' . SupplierPaymentAttempt::query()->sole()->id,
        ]);
        $this->assertDatabaseCount('finance_expense_settlements', 1);
    }

    public function test_pending_or_failed_xendit_webhooks_do_not_mark_the_expense_paid(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payouts' => Http::response([
                'payout_id' => 'payout-003',
                'status' => 'ACCEPTED',
            ], 202),
        ]);
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $this->connect($shop);
        $attempt = $this->startXenditAttempt($finance, $expense, 'xendit-attempt-003');

        $basePayload = [
            'business_id' => 'xendit-business-id',
            'data' => [
                'payout_id' => 'payout-003',
                'reference_id' => $attempt->internal_reference,
            ],
        ];

        $this->postJson('/api/webhooks/xendit/payout', [
            ...$basePayload,
            'event' => 'v3_payout.pending_compliance',
            'data' => [...$basePayload['data'], 'status' => 'PENDING_COMPLIANCE_REVIEW'],
        ], ['x-callback-token' => 'xendit-callback-token'])->assertOk();
        $this->assertSame(SupplierPaymentAttempt::STATUS_PENDING_COMPLIANCE, $attempt->fresh()->status);
        $this->assertDatabaseCount('finance_expense_settlements', 0);

        $this->postJson('/api/webhooks/xendit/payout', [
            ...$basePayload,
            'event' => 'v3_payout.failed',
            'data' => [...$basePayload['data'], 'status' => 'FAILED', 'failure_code' => 'INSUFFICIENT_BALANCE'],
        ], ['x-callback-token' => 'xendit-callback-token'])->assertOk();

        $this->assertSame(SupplierPaymentAttempt::STATUS_FAILED, $attempt->fresh()->status);
        $this->assertDatabaseCount('finance_expense_settlements', 0);

        $this->postJson('/api/webhooks/xendit/payout', [
            ...$basePayload,
            'event' => 'v3_payout.pending_compliance',
            'data' => [...$basePayload['data'], 'status' => 'PENDING_COMPLIANCE_REVIEW'],
        ], ['x-callback-token' => 'xendit-callback-token'])->assertOk();
        $this->assertSame(SupplierPaymentAttempt::STATUS_FAILED, $attempt->fresh()->status);
    }

    public function test_shop_owner_cannot_confirm_a_succeeded_xendit_payout(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payouts' => Http::response([
                'payout_id' => 'payout-owner-review-blocked',
                'status' => 'ACCEPTED',
            ], 202),
        ]);
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $this->connect($shop);
        $attempt = $this->startXenditAttempt($finance, $expense, 'xendit-attempt-owner-review');

        $this->postJson('/api/webhooks/xendit/payout', [
            'event' => 'v3_payout.succeeded',
            'data' => [
                'payout_id' => 'payout-owner-review-blocked',
                'reference_id' => $attempt->internal_reference,
                'status' => 'SUCCEEDED',
            ],
        ], ['x-callback-token' => 'xendit-callback-token'])->assertOk();

        $this->actingAs($owner, 'shop_owner')
            ->postJson("/api/shop-owner/finance/supplier-payment-attempts/{$attempt->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'XENDIT_OWNER_REVIEW_NOT_REQUIRED');
    }

    public function test_unknown_xendit_response_keeps_the_attempt_processing_to_prevent_a_duplicate_payout(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payouts' => Http::response([], 500),
        ]);
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $this->connect($shop);

        $response = $this->actingAs($finance, 'user')->postJson(
            "/api/finance/expenses/{$expense->id}/supplier-payment-attempts",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_XENDIT,
                'idempotency_key' => 'xendit-attempt-unknown',
            ],
        );

        $response->assertStatus(502)->assertJsonPath('code', 'XENDIT_PAYOUT_UNKNOWN');
        $this->assertSame(SupplierPaymentAttempt::STATUS_PROCESSING, SupplierPaymentAttempt::query()->sole()->status);
        $this->assertDatabaseCount('finance_expense_settlements', 0);

        $this->actingAs($finance, 'user')->postJson(
            "/api/finance/expenses/{$expense->id}/supplier-payment-attempts",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_XENDIT,
                'idempotency_key' => 'xendit-attempt-unknown',
            ],
        )->assertOk()->assertJsonPath('replayed', true);
        Http::assertSentCount(1);
    }

    public function test_disconnect_is_blocked_while_a_xendit_payout_is_in_flight(): void
    {
        Http::fake([
            'https://api.xendit.co/v3/payouts' => Http::response([
                'payout_id' => 'payout-004',
                'status' => 'ACCEPTED',
            ], 202),
        ]);
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $this->connect($shop);
        $this->startXenditAttempt($finance, $expense, 'xendit-attempt-004');

        $this->actingAs($shop, 'shop_owner')
            ->deleteJson('/shop-owner/settings/xendit-key')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'XENDIT_IN_FLIGHT');

        $this->assertSame('connected', ShopPaymentIntegration::query()->sole()->status);
    }

    private function connect(ShopOwner $shop): void
    {
        ShopPaymentIntegration::create([
            'shop_owner_id' => $shop->id,
            'provider' => ShopPaymentIntegration::PROVIDER_XENDIT,
            'purpose' => ShopPaymentIntegration::PURPOSE_SUPPLIER_PAYOUT,
            'environment' => 'test',
            'secret_key' => 'xnd_test_secret_key_123456789',
            'webhook_callback_token' => 'xendit-callback-token',
            'status' => ShopPaymentIntegration::STATUS_CONNECTED,
            'connected_at' => now(),
            'last_verified_at' => now(),
        ]);
    }

    private function startXenditAttempt(User $finance, Expense $expense, string $key): SupplierPaymentAttempt
    {
        $response = $this->actingAs($finance, 'user')->postJson(
            "/api/finance/expenses/{$expense->id}/supplier-payment-attempts",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_XENDIT,
                'idempotency_key' => $key,
            ],
        );

        $response->assertCreated();

        return SupplierPaymentAttempt::findOrFail((int) $response->json('data.id'));
    }

    /** @return array{0: ShopOwner, 1: User, 2: ShopOwner, 3: Supplier, 4: Expense} */
    private function paymentContext(): array
    {
        $shop = ShopOwner::factory()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);
        $finance->givePermissionTo('access-finance-expenses');
        $supplier = Supplier::factory()->create([
            'shop_owner_id' => $shop->id,
            'email' => 'supplier@example.test',
        ]);
        SupplierPaymentProfile::create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'recipient_type' => SupplierPaymentProfile::RECIPIENT_BUSINESS,
            'business_name' => 'Supplier Trading',
            'recipient_country' => 'PH',
            'recipient_province_state' => 'Cavite',
            'recipient_city' => 'General Mariano Alvarez',
            'recipient_street_line_1' => '123 Test Street',
            'recipient_postal_code' => '4117',
            'destination_type' => SupplierPaymentProfile::DESTINATION_BANK_ACCOUNT,
            'bank_name' => 'Test Bank',
            'bank_code' => 'TBK',
            'account_name' => 'Supplier Trading',
            'account_number' => '1234567890',
            'status' => SupplierPaymentProfile::STATUS_VERIFIED,
            'verified_by' => $finance->id,
            'verified_at' => now(),
        ]);

        $purchaseOrder = PurchaseOrder::factory()->create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'status' => 'delivered',
            'ordered_by' => $finance->id,
        ]);
        $orderItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'ordered_quantity' => 1,
            'unit_cost' => '100.00',
            'line_total' => '100.00',
        ]);
        $receipt = PurchaseOrderReceipt::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'shop_owner_id' => $shop->id,
            'status' => 'posted',
        ]);
        PurchaseOrderReceiptItem::factory()->create([
            'purchase_order_receipt_id' => $receipt->id,
            'purchase_order_item_id' => $orderItem->id,
            'received_quantity' => 1,
            'defective_quantity' => 0,
            'accepted_quantity' => 1,
        ]);
        $expense = Expense::create([
            'reference' => 'EXP-XENDIT-' . uniqid(),
            'date' => now()->toDateString(),
            'category' => 'Supplies',
            'amount' => '100.00',
            'tax_amount' => '0.00',
            'status' => 'posted',
            'shop_id' => $shop->id,
            'procurement_receipt_id' => $receipt->id,
        ]);

        return [$shop, $finance, $shop, $supplier, $expense];
    }
}
