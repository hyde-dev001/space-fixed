<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExpenseSettlementTest extends TestCase
{
    use RefreshDatabase;

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
