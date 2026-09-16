<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptItem;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\SupplierAdjustment;
use App\Models\SupplierPaymentAttempt;
use App\Models\SupplierPaymentProfile;
use App\Models\User;
use App\Services\Finance\ExpenseSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupplierRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.defaults.guard' => 'user']);
        Storage::fake('local');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([
            'access-finance-expenses',
            'procurement.manage_suppliers',
            'procurement.view',
        ] as $permission) {
            Permission::findOrCreate($permission, 'user');
        }
    }

    public function test_supplier_proof_moves_a_paid_post_payment_adjustment_to_awaiting_verification_and_requires_distinct_finance_proof(): void
    {
        $context = $this->refundContext();
        $this->chooseRefundAndWaiveReturn($context);
        $this->actingAs($context['procurement'], 'user')->post(
            "/api/erp/procurement/supplier-adjustments/{$context['adjustment']->id}/supplier-refund-proof",
            [
                'expected_refund_amount' => '100.00',
                'supplier_reported_refund_amount' => '100.00',
                'supplier_reported_refund_reference' => 'SUPPLIER-REF-001',
                'supplier_reported_refund_date' => '2026-09-12',
                'procurement_notes' => 'Supplier confirmed the refund by email.',
                'supplier_refund_proof' => $this->proof('supplier-proof.pdf'),
            ],
            ['Accept' => 'application/json'],
        )->assertOk()->assertJsonPath('data.status', SupplierAdjustment::STATUS_AWAITING_VERIFICATION);

        $this->assertSame(1, $context['adjustment']->fresh()->getMedia('supplier_refund_proof')->count());
        $this->assertSame(0, $context['adjustment']->fresh()->getMedia('finance_confirmation_proof')->count());
        $this->assertDatabaseCount('finance_expense_settlements', 1);

        $this->actingAs($context['finance'], 'user')->post(
            "/api/finance/expenses/{$context['expense']->id}/supplier-adjustments/{$context['adjustment']->id}/refund-confirmations",
            [
                'amount' => '100.00',
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER,
                'external_transaction_reference' => 'SHOP-REF-001',
                'received_at' => '2026-09-12 12:00:00',
                'idempotency_key' => 'refund-confirmation-1',
            ],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('finance_confirmation_proof');

        $this->assertDatabaseCount('finance_expense_settlements', 1);
        $this->assertSame(SupplierAdjustment::STATUS_AWAITING_VERIFICATION, $context['adjustment']->fresh()->status);
    }

    public function test_supplier_refund_supports_partial_and_full_confirmation_without_changing_original_payment(): void
    {
        $context = $this->refundContext();
        $this->submitSupplierProof($context);

        $partial = $this->confirmRefund($context, '40.00', 'SHOP-REF-002', 'refund-confirmation-2');
        $partial->assertCreated()
            ->assertJsonPath('data.status', SupplierAdjustment::STATUS_PARTIALLY_REFUNDED)
            ->assertJsonPath('data.refunded_amount', '40.00');

        $this->assertDatabaseCount('finance_expense_settlements', 2);
        $this->assertSame('100.00', $context['expense']->fresh()->validSettledAmount());
        $this->assertSame('40.00', ExpenseSettlement::validRefundedAmountForAdjustment($context['adjustment']->id));

        $full = $this->confirmRefund($context, '60.00', 'SHOP-REF-003', 'refund-confirmation-3');
        $full->assertCreated()
            ->assertJsonPath('data.status', SupplierAdjustment::STATUS_RESOLVED)
            ->assertJsonPath('data.refunded_amount', '100.00');

        $this->assertDatabaseCount('finance_expense_settlements', 3);
        $this->assertSame('100.00', $context['expense']->fresh()->validSettledAmount());
        $this->assertSame(2, ExpenseSettlement::query()
            ->where('entry_type', ExpenseSettlement::ENTRY_SUPPLIER_REFUND)
            ->where('supplier_adjustment_id', $context['adjustment']->id)
            ->count());

        $this->actingAs($context['finance'], 'user')
            ->getJson("/api/finance/expenses/{$context['expense']->id}")
            ->assertOk()
            ->assertJsonPath('procurement_details.adjustments.0.status', SupplierAdjustment::STATUS_RESOLVED)
            ->assertJsonPath('procurement_details.adjustments.0.expected_refund_amount', '100.00')
            ->assertJsonPath('procurement_details.adjustments.0.refunded_amount', '100.00')
            ->assertJsonCount(1, 'procurement_details.adjustments.0.supplier_refund_proof')
            ->assertJsonCount(2, 'procurement_details.adjustments.0.finance_confirmation_proof');
    }

    public function test_supplier_refund_confirmation_is_idempotent_and_rejects_over_refund_or_duplicate_reference(): void
    {
        $context = $this->refundContext();
        $this->submitSupplierProof($context);
        $payload = [
            'amount' => '60.00',
            'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_E_WALLET,
            'external_transaction_reference' => 'SHOP-REF-004',
            'received_at' => '2026-09-12 12:00:00',
            'idempotency_key' => 'refund-confirmation-4',
        ];

        $first = $this->actingAs($context['finance'], 'user')->post(
            "/api/finance/expenses/{$context['expense']->id}/supplier-adjustments/{$context['adjustment']->id}/refund-confirmations",
            $payload + ['finance_confirmation_proof' => $this->proof('finance-proof.pdf')],
            ['Accept' => 'application/json'],
        )->assertCreated();

        $this->actingAs($context['finance'], 'user')->post(
            "/api/finance/expenses/{$context['expense']->id}/supplier-adjustments/{$context['adjustment']->id}/refund-confirmations",
            $payload + ['finance_confirmation_proof' => $this->proof('finance-proof-replay.pdf')],
            ['Accept' => 'application/json'],
        )->assertOk()->assertJsonPath('replayed', true);

        $this->assertDatabaseCount('finance_expense_settlements', 2);
        $this->assertSame(1, $context['adjustment']->fresh()->getMedia('finance_confirmation_proof')->count());

        $this->actingAs($context['finance'], 'user')->post(
            "/api/finance/expenses/{$context['expense']->id}/supplier-adjustments/{$context['adjustment']->id}/refund-confirmations",
            [
                'amount' => '1.00',
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_E_WALLET,
                'external_transaction_reference' => 'SHOP-REF-004',
                'received_at' => '2026-09-12 12:00:00',
                'idempotency_key' => 'refund-confirmation-4b',
                'finance_confirmation_proof' => $this->proof('finance-proof-duplicate-reference.pdf'),
            ],
            ['Accept' => 'application/json'],
        )->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_SUBMISSION');

        $this->actingAs($context['finance'], 'user')->post(
            "/api/finance/expenses/{$context['expense']->id}/supplier-adjustments/{$context['adjustment']->id}/refund-confirmations",
            array_merge($payload, [
                'amount' => '99.00',
                'finance_confirmation_proof' => $this->proof('finance-proof-conflict.pdf'),
            ]),
            ['Accept' => 'application/json'],
        )->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_SUBMISSION');

        $this->assertSame(1, $context['adjustment']->fresh()->getMedia('finance_confirmation_proof')->count());

    }

    public function test_supplier_refund_rejects_amount_above_expected_total(): void
    {
        $context = $this->refundContext();
        $this->submitSupplierProof($context);
        $this->confirmRefund($context, '80.00', 'SHOP-REF-005', 'refund-confirmation-5')->assertCreated();
        $this->confirmRefund($context, '21.00', 'SHOP-REF-006', 'refund-confirmation-6')
            ->assertUnprocessable();
    }

    public function test_receiving_defect_cannot_request_a_supplier_refund(): void
    {
        $context = $this->refundContext(SupplierAdjustment::ISSUE_STAGE_RECEIVING_DEFECT);

        $this->actingAs($context['procurement'], 'user')->post(
            "/api/erp/procurement/supplier-adjustments/{$context['adjustment']->id}/supplier-refund-proof",
            [
                'expected_refund_amount' => '100.00',
                'supplier_refund_proof' => $this->proof('receiving-defect-refund.pdf'),
            ],
            ['Accept' => 'application/json'],
        )->assertUnprocessable();

        $this->assertSame(0, $context['adjustment']->fresh()->getMedia('supplier_refund_proof')->count());
        $this->assertDatabaseCount('finance_expense_settlements', 1);
    }

    public function test_post_payment_refund_requires_resolution_and_return_decisions_and_can_be_declined(): void
    {
        $context = $this->refundContext();

        $this->actingAs($context['procurement'], 'user')->post(
            "/api/erp/procurement/supplier-adjustments/{$context['adjustment']->id}/supplier-refund-proof",
            [
                'expected_refund_amount' => '100.00',
                'supplier_refund_proof' => $this->proof('premature-proof.pdf'),
            ],
            ['Accept' => 'application/json'],
        )->assertUnprocessable();

        $this->actingAs($context['procurement'], 'user')
            ->postJson("/api/erp/procurement/supplier-adjustments/{$context['adjustment']->id}/resolution", ['resolution' => 'refund'])
            ->assertOk()
            ->assertJsonPath('data.resolution', SupplierAdjustment::RESOLUTION_REFUND);

        $this->actingAs($context['procurement'], 'user')->post(
            "/api/erp/procurement/supplier-adjustments/{$context['adjustment']->id}/supplier-refund-proof",
            [
                'expected_refund_amount' => '100.00',
                'supplier_refund_proof' => $this->proof('no-return-decision.pdf'),
            ],
            ['Accept' => 'application/json'],
        )->assertUnprocessable();

        $this->actingAs($context['procurement'], 'user')
            ->postJson("/api/erp/procurement/supplier-adjustments/{$context['adjustment']->id}/return", ['status' => 'waived'])
            ->assertOk();

        $this->actingAs($context['procurement'], 'user')
            ->postJson("/api/erp/procurement/supplier-adjustments/{$context['adjustment']->id}/refund/declined", [
                'decline_reason' => 'Supplier disputes the defect.',
                'supplier_reference' => 'DECLINE-100',
            ])
            ->assertOk()
            ->assertJsonPath('data.resolution', null)
            ->assertJsonPath('data.decline_reason', 'Supplier disputes the defect.');

        $this->actingAs($context['procurement'], 'user')
            ->postJson("/api/erp/procurement/supplier-adjustments/{$context['adjustment']->id}/resolution", ['resolution' => 'replacement'])
            ->assertOk()
            ->assertJsonPath('data.resolution', SupplierAdjustment::RESOLUTION_REPLACEMENT);
    }

    public function test_refund_proof_download_is_private_and_tenant_protected(): void
    {
        $context = $this->refundContext();
        $this->submitSupplierProof($context);
        $media = $context['adjustment']->fresh()->getMedia('supplier_refund_proof')->sole();

        $this->actingAs($context['finance'], 'user')->get(
            "/api/finance/supplier-adjustments/{$context['adjustment']->id}/refund-proof/{$media->id}",
        )->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $foreignShop = ShopOwner::factory()->create();
        $foreignFinance = User::factory()->for($foreignShop)->create();
        $foreignFinance->givePermissionTo('access-finance-expenses');

        $this->actingAs($foreignFinance, 'user')->get(
            "/api/finance/supplier-adjustments/{$context['adjustment']->id}/refund-proof/{$media->id}",
        )->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function refundContext(string $issueStage = SupplierAdjustment::ISSUE_STAGE_POST_PAYMENT): array
    {
        $shop = ShopOwner::factory()->create();
        $finance = User::factory()->for($shop)->create();
        $procurement = User::factory()->for($shop)->create();
        $finance->givePermissionTo('access-finance-expenses');
        $procurement->givePermissionTo(['procurement.manage_suppliers', 'procurement.view']);

        $supplier = Supplier::factory()->create([
            'shop_owner_id' => $shop->id,
            'email' => 'supplier@example.test',
        ]);
        $inventory = InventoryItem::factory()->create(['shop_owner_id' => $shop->id]);
        $purchaseOrder = PurchaseOrder::factory()->create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'inventory_item_id' => $inventory->id,
            'status' => 'completed',
        ]);
        $orderItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'inventory_item_id' => $inventory->id,
            'ordered_quantity' => 1,
            'unit_cost' => 100,
            'line_total' => 100,
        ]);
        $receipt = PurchaseOrderReceipt::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'shop_owner_id' => $shop->id,
            'status' => 'posted',
        ]);
        $receiptItem = PurchaseOrderReceiptItem::factory()->create([
            'purchase_order_receipt_id' => $receipt->id,
            'purchase_order_item_id' => $orderItem->id,
            'received_quantity' => 1,
            'accepted_quantity' => 1,
            'defective_quantity' => 0,
        ]);
        $expense = Expense::create([
            'reference' => 'EXP-REFUND-' . uniqid(),
            'date' => now()->toDateString(),
            'category' => 'Supplies',
            'amount' => '100.00',
            'tax_amount' => '0.00',
            'status' => 'posted',
            'shop_id' => $shop->id,
            'procurement_receipt_id' => $receipt->id,
        ]);
        $profile = SupplierPaymentProfile::create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'destination_type' => 'bank',
            'bank_name' => 'BDO',
            'bank_code' => 'BDO',
            'account_name' => 'Supplier Account',
            'account_number' => '1234567890',
            'status' => SupplierPaymentProfile::STATUS_VERIFIED,
        ]);
        $settlement = app(ExpenseSettlementService::class)->record($expense, $shop, [
            'amount' => '100.00',
            'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER,
            'reference' => 'SUPPLIER-PAYMENT-REFUND-001',
            'paid_at' => '2026-09-12 10:00:00',
            'idempotency_key' => 'supplier-payment-refund-1',
            'source' => ExpenseSettlement::SOURCE_SUPPLIER_MANUAL_PAYMENT,
            'source_reference' => 'supplier-manual-payment:refund-1',
        ])['settlement'];
        $attempt = SupplierPaymentAttempt::create([
            'shop_owner_id' => $shop->id,
            'expense_id' => $expense->id,
            'supplier_id' => $supplier->id,
            'supplier_payment_profile_id' => $profile->id,
            'amount' => '100.00',
            'currency' => 'PHP',
            'provider' => 'manual',
            'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER,
            'internal_reference' => 'SPM-REFUND-' . uniqid(),
            'provider_reference' => 'SUPPLIER-PAYMENT-REFUND-001',
            'idempotency_key' => 'supplier-attempt-refund-1',
            'destination_snapshot' => [
                'destination_type' => 'bank',
                'bank_name' => 'BDO',
                'bank_code' => 'BDO',
                'account_name' => 'Supplier Account',
                'account_number' => '1234567890',
            ],
            'status' => SupplierPaymentAttempt::STATUS_SUCCEEDED,
            'initiated_by_user_id' => $finance->id,
            'initiated_at' => '2026-09-12 09:00:00',
            'externally_paid_at' => '2026-09-12 10:00:00',
            'submitted_for_verification_at' => '2026-09-12 10:05:00',
            'verified_by_shop_owner_id' => $shop->id,
            'verified_at' => '2026-09-12 10:10:00',
            'succeeded_at' => '2026-09-12 10:10:00',
            'settled_at' => '2026-09-12 10:10:00',
            'settlement_id' => $settlement->id,
            'supplier_email_to' => 'supplier@example.test',
            'supplier_email_status' => 'sent',
        ]);
        $adjustment = SupplierAdjustment::create([
            'shop_owner_id' => $shop->id,
            'purchase_order_receipt_item_id' => $receiptItem->id,
            'idempotency_key' => 'post-payment-refund-' . uniqid(),
            'issue_stage' => $issueStage,
            'reported_quantity' => 1,
            'unit_cost_snapshot' => '100.00',
            'reason_category' => 'manufacturing_defect',
            'inventory_notes' => 'Defect discovered after payment.',
            'status' => SupplierAdjustment::STATUS_REPORTED,
            'reported_by' => $procurement->id,
            'reported_at' => now(),
        ]);

        return compact('shop', 'finance', 'procurement', 'supplier', 'purchaseOrder', 'orderItem', 'receipt', 'receiptItem', 'expense', 'profile', 'settlement', 'attempt', 'adjustment');
    }

    private function submitSupplierProof(array $context): void
    {
        $this->chooseRefundAndWaiveReturn($context);
        $this->actingAs($context['procurement'], 'user')->post(
            "/api/erp/procurement/supplier-adjustments/{$context['adjustment']->id}/supplier-refund-proof",
            [
                'expected_refund_amount' => '100.00',
                'supplier_reported_refund_amount' => '100.00',
                'supplier_reported_refund_reference' => 'SUPPLIER-REF-001',
                'supplier_reported_refund_date' => '2026-09-12',
                'supplier_refund_proof' => $this->proof('supplier-proof.pdf'),
            ],
            ['Accept' => 'application/json'],
        )->assertOk();
    }

    private function chooseRefundAndWaiveReturn(array $context): void
    {
        $this->actingAs($context['procurement'], 'user')
            ->postJson("/api/erp/procurement/supplier-adjustments/{$context['adjustment']->id}/resolution", ['resolution' => 'refund'])
            ->assertOk();
        $this->actingAs($context['procurement'], 'user')
            ->postJson("/api/erp/procurement/supplier-adjustments/{$context['adjustment']->id}/return", ['status' => 'waived'])
            ->assertOk();
    }

    private function confirmRefund(array $context, string $amount, string $reference, string $key)
    {
        return $this->actingAs($context['finance'], 'user')->post(
            "/api/finance/expenses/{$context['expense']->id}/supplier-adjustments/{$context['adjustment']->id}/refund-confirmations",
            [
                'amount' => $amount,
                'payment_method' => SupplierPaymentAttempt::PAYMENT_METHOD_BANK_TRANSFER,
                'external_transaction_reference' => $reference,
                'received_at' => '2026-09-12 12:00:00',
                'idempotency_key' => $key,
                'finance_confirmation_proof' => $this->proof('finance-proof-' . $key . '.pdf'),
            ],
            ['Accept' => 'application/json'],
        );
    }

    private function proof(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 20, 'application/pdf');
    }
}
