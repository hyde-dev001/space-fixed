<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\ProcurementSettings;
use App\Services\ExpenseApprovalService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ExpenseSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-finance-expenses', 'user');
        Permission::findOrCreate('approve-expenses', 'user');
    }

    public function test_settlement_schema_and_expense_due_date_are_indexed(): void
    {
        $this->assertTrue(Schema::hasColumns('finance_expense_settlements', [
            'shop_owner_id', 'expense_id', 'entry_type', 'amount', 'payment_method',
            'reference', 'paid_at', 'recorded_by_user_id', 'idempotency_key',
            'reverses_settlement_id', 'reversal_reason', 'source', 'source_reference',
        ]));
        $this->assertTrue(Schema::hasColumn('finance_expenses', 'due_date'));

        $indexes = collect(Schema::getIndexes('finance_expense_settlements'));
        $this->assertTrue($indexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['shop_owner_id', 'idempotency_key']));
        $this->assertTrue($indexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['reverses_settlement_id']));
        $this->assertTrue($indexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['source', 'source_reference']));
        $this->assertTrue($indexes->contains(fn (array $index): bool => $index['columns'] === ['shop_owner_id', 'paid_at']));
        $this->assertTrue($indexes->contains(fn (array $index): bool => $index['columns'] === ['expense_id', 'entry_type']));

        $expenseIndexes = collect(Schema::getIndexes('finance_expenses'));
        $this->assertTrue($expenseIndexes->contains(fn (array $index): bool => $index['columns'] === ['shop_id', 'due_date']));
    }

    public function test_settlement_casts_amount_due_date_and_subtracts_full_reversal(): void
    {
        [$shop, $expense, $user] = $this->makeExpenseContext();
        $expense->update(['due_date' => '2026-08-31']);
        $this->assertSame('2026-08-31', $expense->due_date->toDateString());

        $settlement = ExpenseSettlement::create([
            'shop_owner_id' => $shop->id,
            'expense_id' => $expense->id,
            'entry_type' => ExpenseSettlement::ENTRY_SETTLEMENT,
            'amount' => '55.25',
            'payment_method' => 'cash',
            'paid_at' => now(),
            'recorded_by_user_id' => $user->id,
            'idempotency_key' => 'expense-test-1',
            'source' => ExpenseSettlement::SOURCE_MANUAL,
        ]);

        $this->assertSame('55.25', $settlement->amount);
        $this->assertTrue($settlement->expense->is($expense));
        $this->assertSame('55.25', $expense->validSettledAmount());

        ExpenseSettlement::create([
            'shop_owner_id' => $shop->id,
            'expense_id' => $expense->id,
            'entry_type' => ExpenseSettlement::ENTRY_REVERSAL,
            'amount' => '55.25',
            'payment_method' => 'cash',
            'paid_at' => now(),
            'recorded_by_user_id' => $user->id,
            'reverses_settlement_id' => $settlement->id,
            'reversal_reason' => 'Correction',
            'source' => ExpenseSettlement::SOURCE_MANUAL,
        ]);

        $this->assertSame('0.00', $expense->validSettledAmount());
        $this->expectException(\LogicException::class);
        $settlement->delete();
    }

    public function test_source_reference_is_unique_for_integrations(): void
    {
        [$shop, $expense, $user] = $this->makeExpenseContext();
        $attributes = [
            'shop_owner_id' => $shop->id,
            'expense_id' => $expense->id,
            'entry_type' => ExpenseSettlement::ENTRY_SETTLEMENT,
            'amount' => '10.00',
            'payment_method' => 'bank_transfer',
            'paid_at' => now(),
            'recorded_by_user_id' => $user->id,
            'source' => ExpenseSettlement::SOURCE_PAYROLL,
            'source_reference' => 'payroll:123',
        ];

        ExpenseSettlement::create($attributes);
        $this->expectException(QueryException::class);
        ExpenseSettlement::create([...$attributes, 'expense_id' => $expense->id]);
    }

    public function test_paid_now_expense_settles_atomically_and_replays_exact_request(): void
    {
        [$shop, $expense, $user] = $this->makeExpenseContext();
        $user->givePermissionTo('access-finance-expenses');
        $expense->forceDelete();

        $payload = [
            'date' => '2026-08-11',
            'category' => 'Supplies',
            'description' => 'Paid immediately',
            'amount' => '100.00',
            'payment_mode' => 'paid_now',
            'payment_method' => 'cash',
            'payment_reference' => 'CASH-100',
            'idempotency_key' => 'expense-api-replay-1',
        ];

        $first = $this->actingAs($user, 'user')->postJson('/api/finance/expenses', $payload);
        $first->assertCreated()->assertJsonPath('settlement_state.status', 'paid');
        $expenseId = (int) $first->json('id');

        $second = $this->actingAs($user, 'user')->postJson('/api/finance/expenses', $payload);
        $second->assertOk()->assertJsonPath('id', $expenseId)->assertJsonPath('settlement_state.paid_amount', '100.00');

        $conflict = $this->actingAs($user, 'user')->postJson('/api/finance/expenses', [
            ...$payload,
            'amount' => '90.00',
        ]);
        $conflict->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_SUBMISSION');

        $this->assertDatabaseCount('finance_expenses', 1);
        $this->assertDatabaseCount('finance_expense_settlements', 1);
    }

    public function test_pay_later_expense_has_no_initial_settlement(): void
    {
        [$shop, $expense, $user] = $this->makeExpenseContext();
        $user->givePermissionTo('access-finance-expenses');
        $expense->forceDelete();

        $response = $this->actingAs($user, 'user')->postJson('/api/finance/expenses', [
            'date' => '2026-08-11',
            'due_date' => '2026-08-31',
            'category' => 'Rent',
            'amount' => '250.00',
            'payment_mode' => 'pay_later',
        ]);

        $response->assertCreated()
            ->assertJsonPath('settlement_state.status', 'unpaid')
            ->assertJsonPath('settlement_state.outstanding_balance', '250.00');
        $this->assertDatabaseCount('finance_expense_settlements', 0);
    }

    public function test_settlement_requires_approved_expense_and_reversal_is_append_only(): void
    {
        [$shop, $expense, $user] = $this->makeExpenseContext();
        $user->givePermissionTo('access-finance-expenses');

        $blocked = $this->actingAs($user, 'user')->postJson("/api/finance/expenses/{$expense->id}/settlements", [
            'amount' => '20.00',
            'payment_method' => 'cash',
            'idempotency_key' => 'expense-settlement-blocked',
        ]);
        $blocked->assertStatus(422)->assertJsonPath('code', 'INVALID_STATE');

        $expense->update(['status' => 'approved']);
        $created = $this->actingAs($user, 'user')->postJson("/api/finance/expenses/{$expense->id}/settlements", [
            'amount' => '20.00',
            'payment_method' => 'cash',
            'idempotency_key' => 'expense-settlement-1',
        ]);
        $created->assertCreated()->assertJsonPath('expense.paid_amount', '20.00');
        $settlementId = (int) $created->json('settlement.id');

        $reversed = $this->actingAs($user, 'user')->postJson("/api/finance/expenses/{$expense->id}/settlements/{$settlementId}/reverse", [
            'reason' => 'Payment returned',
        ]);
        $reversed->assertCreated()->assertJsonPath('expense.paid_amount', '0.00');

        $duplicate = $this->actingAs($user, 'user')->postJson("/api/finance/expenses/{$expense->id}/settlements/{$settlementId}/reverse", [
            'reason' => 'Second attempt',
        ]);
        $duplicate->assertStatus(409)->assertJsonPath('code', 'ALREADY_REVERSED');
    }

    public function test_manual_expense_with_pending_owner_stage_cannot_be_settled(): void
    {
        [$shop, $expense, $user] = $this->makeExpenseContext();
        $user->givePermissionTo('access-finance-expenses');
        $settings = ProcurementSettings::getForShopOwner($shop->id);
        $settingsJson = $settings->settings_json;
        $settingsJson['approval_pages']['expense_approval']['enabled'] = true;
        $settings->update(['settings_json' => $settingsJson]);

        $approval = app(ExpenseApprovalService::class)->createExpenseApproval($expense, $user);
        $this->assertSame(['1' => 'finance', '2' => 'shop_owner'], $approval->approval_roles);

        $blocked = $this->actingAs($user, 'user')->postJson("/api/finance/expenses/{$expense->id}/settlements", [
            'amount' => '20.00',
            'payment_method' => 'cash',
            'idempotency_key' => 'expense-pending-owner-stage',
        ]);

        $blocked->assertStatus(422)->assertJsonPath('code', 'INVALID_STATE');
        $this->assertSame('submitted', $expense->fresh()->status);
    }

    public function test_rejecting_a_paid_expense_preserves_settlement_and_warns(): void
    {
        [$shop, $expense, $user] = $this->makeExpenseContext();
        $user->givePermissionTo(['access-finance-expenses', 'approve-expenses']);
        ExpenseSettlement::create([
            'shop_owner_id' => $shop->id,
            'expense_id' => $expense->id,
            'entry_type' => ExpenseSettlement::ENTRY_SETTLEMENT,
            'amount' => '100.00',
            'payment_method' => 'cash',
            'paid_at' => now(),
            'recorded_by_user_id' => $user->id,
            'idempotency_key' => 'expense-rejected-paid-1',
            'source' => ExpenseSettlement::SOURCE_MANUAL,
        ]);

        $response = $this->actingAs($user, 'user')->postJson("/api/finance/expenses/{$expense->id}/reject", [
            'approval_notes' => 'Rejected after payment; reconcile separately',
        ]);

        $response->assertOk()->assertJsonPath('settlement_state.paid_amount', '100.00');
        $this->assertDatabaseCount('finance_expense_settlements', 1);
        $this->assertDatabaseHas('finance_expenses', ['id' => $expense->id, 'status' => 'rejected']);
    }

    private function makeExpenseContext(): array
    {
        $shop = ShopOwner::factory()->create();
        $user = User::factory()->create(['shop_owner_id' => $shop->id]);
        $expense = Expense::create([
            'reference' => 'EXP-' . uniqid(),
            'date' => now()->toDateString(),
            'category' => 'Supplies',
            'amount' => '100.00',
            'tax_amount' => '0.00',
            'status' => 'submitted',
            'shop_id' => $shop->id,
        ]);

        return [$shop, $expense, $user];
    }
}
