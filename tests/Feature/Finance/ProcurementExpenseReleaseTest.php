<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Expense;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptItem;
use App\Models\ShopOwner;
use App\Models\Supplier;
use App\Models\SupplierPaymentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProcurementExpenseReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-finance-expenses', 'user');
        Permission::findOrCreate('access-approval-workflow', 'user');
        Permission::findOrCreate('approve-expenses', 'user');
    }

    public function test_finance_can_review_and_release_a_valid_procurement_expense(): void
    {
        [$shop, $finance, $expense] = $this->procurementExpense();
        $finance->givePermissionTo('access-approval-workflow');

        $response = $this->actingAs($finance, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/review-release", [
                'approval_notes' => 'Receipt and payable verified.',
            ]);

        $response->assertOk()
            ->assertJsonPath('expense.status', 'posted')
            ->assertJsonPath('expense.approved_by', $finance->id)
            ->assertJsonPath('expense.approval_notes', 'Receipt and payable verified.');
        $this->assertDatabaseHas('finance_expenses', [
            'id' => $expense->id,
            'shop_id' => $shop->id,
            'status' => 'posted',
            'approved_by' => $finance->id,
        ]);
    }

    public function test_review_release_replay_is_rejected_without_rewriting_the_review(): void
    {
        [$finance, $expense] = $this->releaseExpenseForFinance();

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/review-release", [
                'approval_notes' => 'First review.',
            ])
            ->assertOk();

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/review-release", [
                'approval_notes' => 'Second review.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_STATE');

        $this->assertDatabaseHas('finance_expenses', [
            'id' => $expense->id,
            'approval_notes' => 'First review.',
        ]);
    }

    public function test_review_release_rejects_a_non_procurement_expense(): void
    {
        $shop = ShopOwner::factory()->create();
        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);
        $expense = Expense::create([
            'reference' => 'EXP-NON-PROCUREMENT',
            'date' => now()->toDateString(),
            'category' => 'Supplies',
            'amount' => '200.00',
            'tax_amount' => '0.00',
            'status' => 'submitted',
            'shop_id' => $shop->id,
        ]);
        $finance->givePermissionTo('access-approval-workflow');

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/review-release")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_review_release_requires_a_submitted_expense(): void
    {
        [$finance, $expense] = $this->releaseExpenseForFinance(['status' => 'posted']);

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/review-release")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_review_release_rejects_a_voided_receipt(): void
    {
        [$finance, $expense] = $this->releaseExpenseForFinance([], ['status' => 'voided']);

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/review-release")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_review_release_rejects_a_payable_amount_mismatch(): void
    {
        [$finance, $expense] = $this->releaseExpenseForFinance(['amount' => '250.00']);

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/review-release")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_review_release_requires_the_existing_finance_approval_capability(): void
    {
        [, $finance, $expense] = $this->procurementExpense();

        $this->actingAs($finance, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/review-release")
            ->assertForbidden();
    }

    public function test_review_release_cannot_cross_shop_boundaries(): void
    {
        [$shopA, $financeA] = $this->financeActor();
        [, , $expenseB] = $this->procurementExpense();
        $financeA->givePermissionTo('approve-expenses');

        $this->assertNotSame($shopA->id, $expenseB->shop_id);

        $this->actingAs($financeA, 'user')
            ->postJson("/api/finance/expenses/{$expenseB->id}/review-release")
            ->assertNotFound();
    }

    public function test_finance_projection_exposes_receipt_payable_and_due_timing_details(): void
    {
        $this->travelTo(Carbon::parse('2026-09-12 09:00:00'));
        [$finance, $expense] = $this->releaseExpenseForFinance();
        $expense->update(['due_date' => '2026-09-14']);

        $this->actingAs($finance, 'user')
            ->getJson("/api/finance/expenses/{$expense->id}")
            ->assertOk()
            ->assertJsonPath('procurement_details.receipt_number', 'RCV-2026-0001')
            ->assertJsonPath('procurement_details.ordered_quantity', 5)
            ->assertJsonPath('procurement_details.received_quantity', 3)
            ->assertJsonPath('procurement_details.accepted_quantity', 2)
            ->assertJsonPath('procurement_details.defective_quantity', 1)
            ->assertJsonPath('procurement_details.unit_cost', '100.00')
            ->assertJsonPath('procurement_details.payable_amount', '200.00')
            ->assertJsonPath('procurement_details.payment_terms', 'Net 30')
            ->assertJsonPath('procurement_details.due_date', '2026-09-14')
            ->assertJsonPath('procurement_details.expense_status', 'submitted')
            ->assertJsonPath('procurement_details.payment_status', 'unpaid')
            ->assertJsonPath('procurement_details.payment_timing', 'Due Soon');

        $this->travelBack();
    }

    public function test_finance_projection_exposes_only_the_masked_supplier_payment_profile(): void
    {
        [$shop, $finance, $expense] = $this->procurementExpense();
        $supplier = Supplier::query()->where('shop_owner_id', $shop->id)->firstOrFail();
        SupplierPaymentProfile::create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'destination_type' => 'bank_account',
            'bank_name' => 'Test Bank',
            'bank_code' => 'TBK',
            'account_name' => 'Supplier Trading',
            'account_number' => '1234567890',
        ]);
        $finance->givePermissionTo('access-finance-expenses');

        $this->actingAs($finance, 'user')
            ->getJson("/api/finance/expenses/{$expense->id}")
            ->assertOk()
            ->assertJsonPath('procurement_details.supplier_id', $supplier->id)
            ->assertJsonPath('procurement_details.payment_profile.masked_account_number', '******7890')
            ->assertJsonMissingPath('procurement_details.payment_profile.account_number');
    }

    /** @return array{0: ShopOwner, 1: User, 2: Expense} */
    private function procurementExpense(array $expenseOverrides = [], array $receiptOverrides = []): array
    {
        [$shop, $finance] = $this->financeActor();
        $supplier = Supplier::factory()->create(['shop_owner_id' => $shop->id]);
        $purchaseOrder = PurchaseOrder::factory()->create([
            'shop_owner_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'status' => 'delivered',
            'payment_terms' => 'Net 30',
        ]);
        $purchaseOrderItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'ordered_quantity' => 5,
            'unit_cost' => '100.00',
            'line_total' => '500.00',
        ]);
        $receipt = PurchaseOrderReceipt::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'shop_owner_id' => $shop->id,
            'status' => PurchaseOrderReceipt::STATUS_POSTED,
            'receipt_reference' => 'RCV-2026-0001',
            ...$receiptOverrides,
        ]);
        PurchaseOrderReceiptItem::factory()->create([
            'purchase_order_receipt_id' => $receipt->id,
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'received_quantity' => 3,
            'defective_quantity' => 1,
            'accepted_quantity' => 2,
        ]);
        $expense = Expense::create([
            'reference' => 'PROC-' . $shop->id . '-' . $receipt->receipt_reference,
            'date' => $receipt->received_at->toDateString(),
            'due_date' => $receipt->received_at->copy()->addDays(30)->toDateString(),
            'category' => 'Procurement',
            'vendor' => $supplier->name,
            'amount' => '200.00',
            'tax_amount' => '0.00',
            'status' => 'submitted',
            'shop_id' => $shop->id,
            'created_by' => $finance->id,
            'procurement_receipt_id' => $receipt->id,
            'meta' => ['source' => 'procurement_receipt'],
            ...$expenseOverrides,
        ]);

        return [$shop, $finance, $expense];
    }

    /** @return array{0: User, 1: Expense} */
    private function releaseExpenseForFinance(array $expenseOverrides = [], array $receiptOverrides = []): array
    {
        [, $finance, $expense] = $this->procurementExpense($expenseOverrides, $receiptOverrides);
        $finance->givePermissionTo(['access-approval-workflow', 'access-finance-expenses']);

        return [$finance, $expense];
    }

    /** @return array{0: ShopOwner, 1: User} */
    private function financeActor(): array
    {
        $shop = ShopOwner::factory()->create();
        $finance = User::factory()->create(['shop_owner_id' => $shop->id]);

        return [$shop, $finance];
    }
}
