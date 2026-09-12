<?php

namespace Tests\Feature\Finance;

use App\Mail\SupplierPaymentConfirmationMail;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptItem;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\SupplierPaymentAttempt;
use App\Models\SupplierPaymentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupplierManualPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-finance-expenses', 'user');
    }

    public function test_supplier_payment_attempt_supports_manual_payment_and_audit_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('supplier_payment_attempts', [
            'shop_owner_id',
            'expense_id',
            'supplier_id',
            'supplier_payment_profile_id',
            'amount',
            'currency',
            'payment_method',
            'internal_reference',
            'provider_reference',
            'idempotency_key',
            'destination_snapshot',
            'status',
            'initiated_by_user_id',
            'initiated_at',
            'externally_paid_at',
            'submitted_for_verification_at',
            'verified_by_shop_owner_id',
            'verified_at',
            'rejected_by_shop_owner_id',
            'rejected_at',
            'rejection_reason',
            'cancellation_reason',
            'cancelled_by_user_id',
            'cancelled_at',
            'settlement_id',
            'supplier_email_to',
            'supplier_email_status',
            'supplier_email_sent_at',
            'supplier_email_failed_at',
            'supplier_email_failure_message',
        ]));

        $indexes = collect(Schema::getIndexes('supplier_payment_attempts'));

        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['unique']
                && $index['columns'] === ['shop_owner_id', 'idempotency_key'],
        ));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['unique']
                && $index['columns'] === ['provider_reference'],
        ));
    }

    public function test_finance_can_start_a_manual_supplier_payment_with_a_masked_snapshot(): void
    {
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();

        $response = $this->actingAs($finance, 'user')->postJson(
            "/api/finance/expenses/{$expense->id}/supplier-payment-attempts",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER,
                'idempotency_key' => 'manual-attempt-1',
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('data.status', SupplierPaymentAttempt::STATUS_INITIATING)
            ->assertJsonPath('data.amount', '100.00')
            ->assertJsonPath('data.supplier_email_to', 'supplier@example.test')
            ->assertJsonPath('data.masked_destination.masked_account_number', '******7890')
            ->assertJsonMissingPath('data.destination_snapshot');

        $this->assertDatabaseHas('supplier_payment_attempts', [
            'expense_id' => $expense->id,
            'supplier_id' => $supplier->id,
            'status' => SupplierPaymentAttempt::STATUS_INITIATING,
            'supplier_email_to' => 'supplier@example.test',
        ]);
    }

    public function test_finance_expense_projection_includes_the_manual_payment_state(): void
    {
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $this->startAttempt($finance, $expense);

        $this->actingAs($finance, 'user')
            ->getJson("/api/finance/expenses/{$expense->id}")
            ->assertOk()
            ->assertJsonPath('procurement_details.payment_status', SupplierPaymentAttempt::STATUS_INITIATING)
            ->assertJsonPath('procurement_details.payment_attempt.payment_method', SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER)
            ->assertJsonMissingPath('procurement_details.payment_attempt.destination_snapshot');
    }

    public function test_payment_initiation_requires_a_verified_profile_and_supplier_email(): void
    {
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $supplier->update(['email' => 'not-an-email']);

        $this->actingAs($finance, 'user')->postJson(
            "/api/finance/expenses/{$expense->id}/supplier-payment-attempts",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER,
                'idempotency_key' => 'manual-invalid-email',
            ],
        )->assertUnprocessable();

        $supplier->update(['email' => 'supplier@example.test']);
        $supplier->paymentProfile()->update(['status' => SupplierPaymentProfile::STATUS_UNVERIFIED]);

        $this->actingAs($finance, 'user')->postJson(
            "/api/finance/expenses/{$expense->id}/supplier-payment-attempts",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER,
                'idempotency_key' => 'manual-unverified-profile',
            ],
        )->assertUnprocessable();
    }

    public function test_initiation_replays_the_same_key_and_rejects_changed_payload(): void
    {
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $payload = [
            'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER,
            'idempotency_key' => 'manual-replay-1',
        ];

        $first = $this->actingAs($finance, 'user')->postJson(
            "/api/finance/expenses/{$expense->id}/supplier-payment-attempts",
            $payload,
        )->assertCreated();
        $second = $this->actingAs($finance, 'user')->postJson(
            "/api/finance/expenses/{$expense->id}/supplier-payment-attempts",
            $payload,
        )->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        $this->actingAs($finance, 'user')->postJson(
            "/api/finance/expenses/{$expense->id}/supplier-payment-attempts",
            [...$payload, 'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_E_WALLET],
        )->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_SUBMISSION');
    }

    public function test_finance_must_submit_the_full_balance_and_proof_before_verification(): void
    {
        Storage::fake('local');
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $attempt = $this->startAttempt($finance, $expense);

        $missingProof = $this->actingAs($finance, 'user')->post(
            "/api/finance/supplier-payment-attempts/{$attempt->id}/submit",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER,
                'amount' => '100.00',
                'external_transaction_reference' => 'BANK-001',
                'externally_paid_at' => '2026-09-12 10:00:00',
            ],
            ['Accept' => 'application/json'],
        );
        $missingProof->assertUnprocessable()->assertJsonValidationErrors('payment_proof');

        $wrongAmount = $this->actingAs($finance, 'user')->post(
            "/api/finance/supplier-payment-attempts/{$attempt->id}/submit",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER,
                'amount' => '99.00',
                'external_transaction_reference' => 'BANK-001',
                'externally_paid_at' => '2026-09-12 10:00:00',
                'payment_proof' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'),
            ],
            ['Accept' => 'application/json'],
        );
        $wrongAmount->assertStatus(422);

        $submitted = $this->actingAs($finance, 'user')->post(
            "/api/finance/supplier-payment-attempts/{$attempt->id}/submit",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER,
                'amount' => '100.00',
                'external_transaction_reference' => 'BANK-001',
                'externally_paid_at' => '2026-09-12 10:00:00',
                'finance_note' => 'Paid through the shop bank account.',
                'payment_proof' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'),
            ],
            ['Accept' => 'application/json'],
        );

        $submitted->assertOk()
            ->assertJsonPath('data.status', SupplierPaymentAttempt::STATUS_AWAITING_VERIFICATION)
            ->assertJsonPath('data.external_transaction_reference', 'BANK-001');

        $this->assertDatabaseCount('finance_expense_settlements', 0);
        $this->assertSame(1, SupplierPaymentAttempt::findOrFail($attempt->id)->getMedia('payment_proof')->count());
    }

    public function test_profile_changes_after_initiation_do_not_change_the_attempt_snapshot(): void
    {
        Storage::fake('local');
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $attempt = $this->startAttempt($finance, $expense);
        $supplier->paymentProfile()->update([
            'account_name' => 'New Supplier Account',
            'account_number' => '9999999999',
            'status' => SupplierPaymentProfile::STATUS_UNVERIFIED,
        ]);

        $this->submitProof($finance, $attempt);

        $this->assertSame('******7890', $attempt->fresh()->maskedDestination()['masked_account_number']);
    }

    public function test_payment_proof_is_private_and_only_same_shop_actors_can_download_it(): void
    {
        Storage::fake('local');
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $attempt = $this->startAttempt($finance, $expense);
        $this->submitProof($finance, $attempt);
        $media = $attempt->fresh()->getMedia('payment_proof')->sole();

        $this->actingAs($finance, 'user')
            ->get("/api/finance/supplier-payment-attempts/{$attempt->id}/proof/{$media->id}")
            ->assertOk()
            ->assertHeader('cache-control', 'no-store, private');

        $this->actingAs($owner, 'shop_owner')
            ->get("/api/shop-owner/finance/supplier-payment-attempts/{$attempt->id}/proof/{$media->id}")
            ->assertOk();

        $otherShop = ShopOwner::factory()->create();
        $otherFinance = User::factory()->create(['shop_owner_id' => $otherShop->id]);
        $otherFinance->givePermissionTo('access-finance-expenses');
        $this->actingAs($otherFinance, 'user')
            ->get("/api/finance/supplier-payment-attempts/{$attempt->id}/proof/{$media->id}")
            ->assertNotFound();
    }

    public function test_shop_owner_confirmation_records_one_settlement_and_emails_the_snapshotted_address(): void
    {
        Storage::fake('local');
        Mail::fake();
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $attempt = $this->startAttempt($finance, $expense);

        $this->submitProof($finance, $attempt);
        $supplier->update(['email' => 'changed-after-initiation@example.test']);

        $confirmed = $this->actingAs($owner, 'shop_owner')->postJson(
            "/api/shop-owner/finance/supplier-payment-attempts/{$attempt->id}/confirm",
        );

        $confirmed->assertOk()
            ->assertJsonPath('data.status', SupplierPaymentAttempt::STATUS_SUCCEEDED)
            ->assertJsonPath('data.payment_status', 'paid');

        $this->assertDatabaseCount('finance_expense_settlements', 1);
        $this->assertDatabaseHas('supplier_payment_attempts', [
            'id' => $attempt->id,
            'status' => SupplierPaymentAttempt::STATUS_SUCCEEDED,
            'verified_by_shop_owner_id' => $owner->id,
            'supplier_email_to' => 'supplier@example.test',
        ]);
        $this->assertSame('100.00', $expense->fresh()->validSettledAmount());
        Mail::assertSent(SupplierPaymentConfirmationMail::class, function (SupplierPaymentConfirmationMail $mail): bool {
            return $mail->hasTo('supplier@example.test')
                && $mail->amount === '100.00'
                && $mail->externalTransactionReference === 'BANK-001';
        });
    }

    public function test_email_failure_does_not_rollback_successful_payment_and_can_be_resent(): void
    {
        Storage::fake('local');
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $attempt = $this->startAttempt($finance, $expense);
        $this->submitProof($finance, $attempt);

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP unavailable'));

        $confirmed = $this->actingAs($owner, 'shop_owner')->postJson(
            "/api/shop-owner/finance/supplier-payment-attempts/{$attempt->id}/confirm",
        );

        $confirmed->assertOk()
            ->assertJsonPath('data.status', SupplierPaymentAttempt::STATUS_SUCCEEDED)
            ->assertJsonPath('data.supplier_email_status', 'failed');
        $this->assertDatabaseCount('finance_expense_settlements', 1);
    }

    public function test_replaying_successful_confirmation_does_not_send_a_duplicate_supplier_email(): void
    {
        Storage::fake('local');
        Mail::fake();
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $attempt = $this->startAttempt($finance, $expense);
        $this->submitProof($finance, $attempt);

        $this->actingAs($owner, 'shop_owner')->postJson(
            "/api/shop-owner/finance/supplier-payment-attempts/{$attempt->id}/confirm",
        )->assertOk();
        $this->actingAs($owner, 'shop_owner')->postJson(
            "/api/shop-owner/finance/supplier-payment-attempts/{$attempt->id}/confirm",
        )->assertOk()->assertJsonPath('data.status', SupplierPaymentAttempt::STATUS_SUCCEEDED);

        $this->assertDatabaseCount('finance_expense_settlements', 1);
        Mail::assertSent(SupplierPaymentConfirmationMail::class, 1);
    }

    public function test_failed_supplier_confirmation_can_be_resent_without_a_second_settlement(): void
    {
        Storage::fake('local');
        Mail::fake();
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $attempt = $this->startAttempt($finance, $expense);
        $this->submitProof($finance, $attempt);

        $this->actingAs($owner, 'shop_owner')->postJson(
            "/api/shop-owner/finance/supplier-payment-attempts/{$attempt->id}/confirm",
        )->assertOk();
        $attempt->refresh()->update(['supplier_email_status' => 'failed']);

        $resent = $this->actingAs($finance, 'user')->postJson(
            "/api/finance/supplier-payment-attempts/{$attempt->id}/resend-confirmation",
        );

        $resent->assertOk()->assertJsonPath('data.supplier_email_status', 'sent');
        $this->assertDatabaseCount('finance_expense_settlements', 1);
        Mail::assertSent(SupplierPaymentConfirmationMail::class, fn (SupplierPaymentConfirmationMail $mail): bool => $mail->hasTo('supplier@example.test'));
    }

    public function test_shop_owner_rejection_is_terminal_and_allows_a_new_attempt_without_a_settlement(): void
    {
        Storage::fake('local');
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $attempt = $this->startAttempt($finance, $expense);
        $this->submitProof($finance, $attempt);

        $rejected = $this->actingAs($owner, 'shop_owner')->postJson(
            "/api/shop-owner/finance/supplier-payment-attempts/{$attempt->id}/reject",
            ['reason' => 'The bank receipt does not match the supplier destination.'],
        );

        $rejected->assertOk()->assertJsonPath('data.status', SupplierPaymentAttempt::STATUS_REJECTED);
        $this->assertDatabaseCount('finance_expense_settlements', 0);

        $retry = $this->actingAs($finance, 'user')->postJson(
            "/api/finance/expenses/{$expense->id}/supplier-payment-attempts",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_E_WALLET,
                'idempotency_key' => 'manual-attempt-2',
            ],
        );

        $retry->assertCreated();
        $this->assertNotSame($attempt->id, (int) $retry->json('data.id'));
    }

    public function test_manual_e_wallet_payment_uses_the_same_maker_checker_settlement_flow(): void
    {
        Storage::fake('local');
        Mail::fake();
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $attempt = $this->startAttempt($finance, $expense, SupplierPaymentAttempt::PAYMENT_METHOD_E_WALLET);

        $this->submitProof($finance, $attempt, 'GCASH-001', SupplierPaymentAttempt::PAYMENT_METHOD_E_WALLET);

        $this->actingAs($owner, 'shop_owner')->postJson(
            "/api/shop-owner/finance/supplier-payment-attempts/{$attempt->id}/confirm",
        )->assertOk()->assertJsonPath('data.status', SupplierPaymentAttempt::STATUS_SUCCEEDED);

        $this->assertDatabaseHas('finance_expense_settlements', [
            'expense_id' => $expense->id,
            'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_E_WALLET,
            'reference' => 'GCASH-001',
        ]);
    }

    public function test_finance_can_cancel_only_an_unsubmitted_initiating_attempt(): void
    {
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $attempt = $this->startAttempt($finance, $expense);

        $cancelled = $this->actingAs($finance, 'user')->postJson(
            "/api/finance/supplier-payment-attempts/{$attempt->id}/cancel",
            ['reason' => 'The external banking app was unavailable.'],
        );

        $cancelled->assertOk()->assertJsonPath('data.status', SupplierPaymentAttempt::STATUS_CANCELLED);

        $this->assertDatabaseHas('supplier_payment_attempts', [
            'id' => $attempt->id,
            'status' => SupplierPaymentAttempt::STATUS_CANCELLED,
            'cancelled_by_user_id' => $finance->id,
        ]);
        $this->assertNotNull($attempt->fresh()->cancelled_at);
    }

    public function test_finance_cannot_cancel_after_payment_proof_is_submitted(): void
    {
        Storage::fake('local');
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $attempt = $this->startAttempt($finance, $expense);
        $this->submitProof($finance, $attempt);

        $this->actingAs($finance, 'user')->postJson(
            "/api/finance/supplier-payment-attempts/{$attempt->id}/cancel",
            ['reason' => 'Trying to cancel after transfer evidence.'],
        )->assertUnprocessable();

        $this->assertSame(
            SupplierPaymentAttempt::STATUS_AWAITING_VERIFICATION,
            $attempt->fresh()->status,
        );
    }

    public function test_external_transaction_reference_cannot_be_reused_after_a_rejected_attempt(): void
    {
        Storage::fake('local');
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $attempt = $this->startAttempt($finance, $expense);
        $this->submitProof($finance, $attempt, 'BANK-REUSED');

        $this->actingAs($owner, 'shop_owner')->postJson(
            "/api/shop-owner/finance/supplier-payment-attempts/{$attempt->id}/reject",
            ['reason' => 'Proof needs correction.'],
        )->assertOk();

        $retry = $this->startAttempt($finance, $expense);
        $this->actingAs($finance, 'user')->post(
            "/api/finance/supplier-payment-attempts/{$retry->id}/submit",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER,
                'amount' => '100.00',
                'external_transaction_reference' => 'BANK-REUSED',
                'externally_paid_at' => '2026-09-12 11:00:00',
                'payment_proof' => UploadedFile::fake()->create('retry-proof.pdf', 20, 'application/pdf'),
            ],
            ['Accept' => 'application/json'],
        )->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_SUBMISSION');
    }

    public function test_successful_attempt_proof_is_immutable_and_cannot_be_submitted_again(): void
    {
        Storage::fake('local');
        Mail::fake();
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $attempt = $this->startAttempt($finance, $expense);
        $this->submitProof($finance, $attempt);
        $mediaId = $attempt->fresh()->getMedia('payment_proof')->sole()->id;

        $this->actingAs($owner, 'shop_owner')->postJson(
            "/api/shop-owner/finance/supplier-payment-attempts/{$attempt->id}/confirm",
        )->assertOk();

        $this->actingAs($finance, 'user')->post(
            "/api/finance/supplier-payment-attempts/{$attempt->id}/submit",
            [
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER,
                'amount' => '100.00',
                'external_transaction_reference' => 'BANK-NEW',
                'externally_paid_at' => '2026-09-12 12:00:00',
                'payment_proof' => UploadedFile::fake()->create('replacement-proof.pdf', 20, 'application/pdf'),
            ],
            ['Accept' => 'application/json'],
        )->assertUnprocessable();

        $this->assertSame(1, $attempt->fresh()->getMedia('payment_proof')->count());
        $this->assertDatabaseHas('media', ['id' => $mediaId, 'model_type' => SupplierPaymentAttempt::class]);
    }

    public function test_user_guard_cannot_verify_supplier_payment_as_shop_owner(): void
    {
        Storage::fake('local');
        [$shop, $finance, $owner, $supplier, $expense] = $this->paymentContext();
        $attempt = $this->startAttempt($finance, $expense);
        $this->submitProof($finance, $attempt);

        $this->actingAs($finance, 'user')->postJson(
            "/api/shop-owner/finance/supplier-payment-attempts/{$attempt->id}/confirm",
        )->assertForbidden()
            ->assertJsonPath('code', 'ERP_ROUTE_NOT_ALLOWED');

        $this->assertSame(
            SupplierPaymentAttempt::STATUS_AWAITING_VERIFICATION,
            $attempt->fresh()->status,
        );
    }

    public function test_supplier_confirmation_email_contains_remittance_details_without_raw_account_data(): void
    {
        $mail = new SupplierPaymentConfirmationMail(
            supplierName: 'Supplier Trading',
            poNumber: 'PO-2026-003',
            amount: '100.00',
            paymentMethod: SupplierPaymentAttempt::PAYMENT_METHOD_E_WALLET,
            externalTransactionReference: 'GCASH-001',
            externallyPaidAt: CarbonImmutable::parse('2026-09-12 10:00:00'),
            maskedDestination: [
                'destination_type' => 'e_wallet',
                'bank_name' => 'GCash',
                'masked_account_number' => '******7890',
            ],
        );

        $body = $mail->render();

        $this->assertStringContainsString('PO-2026-003', $body);
        $this->assertStringContainsString('100.00', $body);
        $this->assertStringContainsString('GCASH-001', $body);
        $this->assertStringContainsString('******7890', $body);
        $this->assertStringNotContainsString('1234567890', $body);
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
            'destination_type' => 'bank_account',
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
            'reference' => 'EXP-MANUAL-'.uniqid(),
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

    private function startAttempt(
        User $finance,
        Expense $expense,
        string $paymentMethod = SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER,
    ): SupplierPaymentAttempt
    {
        $response = $this->actingAs($finance, 'user')->postJson(
            "/api/finance/expenses/{$expense->id}/supplier-payment-attempts",
            [
                'payment_method' => $paymentMethod,
                'idempotency_key' => 'manual-attempt-'.uniqid(),
            ],
        );

        $response->assertCreated();

        return SupplierPaymentAttempt::findOrFail((int) $response->json('data.id'));
    }

    private function submitProof(
        User $finance,
        SupplierPaymentAttempt $attempt,
        string $reference = 'BANK-001',
        string $paymentMethod = SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER,
    ): void
    {
        $response = $this->actingAs($finance, 'user')->post(
            "/api/finance/supplier-payment-attempts/{$attempt->id}/submit",
            [
                'payment_method' => $paymentMethod,
                'amount' => '100.00',
                'external_transaction_reference' => $reference,
                'externally_paid_at' => '2026-09-12 10:00:00',
                'payment_proof' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'),
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertOk();
    }
}
