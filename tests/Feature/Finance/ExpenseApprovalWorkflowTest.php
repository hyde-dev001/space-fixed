<?php

namespace Tests\Feature\Finance;

use App\Models\Approval;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\ExpenseApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ExpenseApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shopOwnerAuth;
    private User $shopOwnerMappedUser;
    private User $requester;
    private User $financeFirst;
    private User $financeSecond;
    private User $financeFinal;
    private ExpenseApprovalService $expenseApprovalService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('approve-expenses', 'user');
        Permission::findOrCreate('access-finance-expenses', 'user');
        Permission::findOrCreate('access-approval-workflow', 'user');

        Role::findOrCreate('finance', 'user');
        Role::findOrCreate('shop-owner', 'user');
        Role::findOrCreate('Finance Manager', 'user');

        $this->shopOwnerAuth = ShopOwner::factory()->approved()->create();

        // For role checks at level 2, we map a user identity to this shop owner
        $this->shopOwnerMappedUser = User::factory()->create([
            'id' => $this->shopOwnerAuth->id,
            'shop_owner_id' => $this->shopOwnerAuth->id,
            'role' => 'Shop Owner',
        ]);
        $this->shopOwnerMappedUser->assignRole('shop-owner');

        $this->requester = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
        ]);

        $this->financeFirst = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
        ]);
        $this->financeFirst->assignRole('finance');

        $this->financeSecond = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
        ]);
        $this->financeSecond->assignRole('finance');

        $this->financeFinal = User::factory()->create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
        ]);
        $this->financeFinal->assignRole('Finance Manager');
        $this->financeFinal->givePermissionTo('approve-expenses');

        $this->expenseApprovalService = app(ExpenseApprovalService::class);
    }

    public function test_low_value_expense_has_one_finance_approval(): void
    {
        $expense = $this->createWorkflowBoundExpense();
        $approval = $expense->approval()->firstOrFail();

        $this->assertSame(1, $approval->total_levels);
        $response = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/approve", [
                'approval_notes' => 'Finance approved',
            ]);

        $response->assertStatus(200)->assertJson(['is_final' => true]);
        $this->assertSame('approved', $expense->fresh()->status);

        $conflict = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/approve", [
                'approval_notes' => 'Duplicate approval',
            ]);
        $conflict->assertStatus(422)->assertJsonPath('details.code', 'APPROVAL_STATE_CONFLICT');
    }

    public function test_high_value_expense_escalates_once_to_shop_owner(): void
    {
        $expense = $this->createWorkflowBoundExpense(6000);
        $this->assertSame(2, $expense->approval()->firstOrFail()->total_levels);

        $first = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/approve", [
                'approval_notes' => 'Finance review complete',
            ]);
        $first->assertStatus(200)->assertJson(['is_final' => false]);
        $this->assertSame(2, $expense->fresh()->current_approval_level);

        $wrongFinance = $this->actingAs($this->financeSecond, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/approve", []);
        $wrongFinance->assertStatus(422);

        $final = $this->actingAs($this->shopOwnerMappedUser, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/approve", [
                'approval_notes' => 'Owner approved high-value expense',
            ]);
        $final->assertStatus(200)->assertJson(['is_final' => true]);
        $this->assertSame('approved', $expense->fresh()->status);
    }

    public function test_rejecting_a_paid_expense_does_not_create_a_reversal(): void
    {
        $expense = $this->createWorkflowBoundExpense();
        ExpenseSettlement::create([
            'shop_owner_id' => $this->shopOwnerAuth->id,
            'expense_id' => $expense->id,
            'entry_type' => ExpenseSettlement::ENTRY_SETTLEMENT,
            'amount' => '2500.00',
            'payment_method' => 'cash',
            'paid_at' => now(),
            'recorded_by_user_id' => $this->financeFirst->id,
            'idempotency_key' => 'approval-paid-expense-1',
            'source' => ExpenseSettlement::SOURCE_MANUAL,
        ]);

        $reject = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/reject", [
                'approval_notes' => 'Rejected after payment; reconcile separately',
            ]);

        $reject->assertStatus(200)->assertJsonPath('settlement_state.paid_amount', '2500.00');
        $this->assertDatabaseCount('finance_expense_settlements', 1);
    }

    public function test_finance_module_permission_can_approve_without_legacy_expense_permission(): void
    {
        $this->withMiddleware();
        $this->financeFirst->givePermissionTo('access-approval-workflow');

        $expense = $this->createWorkflowBoundExpense();

        $response = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/approve", [
                'approval_notes' => 'Approved from the Finance expense page',
            ]);

        $response->assertOk()
            ->assertJson([
                'is_final' => true,
            ]);

        $this->assertSame('approved', $expense->fresh()->status);
    }

    private function createWorkflowBoundExpense(float $amount = 2500): Expense
    {
        $expense = Expense::create([
            'reference' => 'EXP-WF-' . now()->timestamp . '-' . random_int(100, 999),
            'date' => now()->toDateString(),
            'category' => 'Operations',
            'vendor' => 'Workflow Vendor',
            'description' => 'Expense workflow feature test',
            'amount' => $amount,
            'tax_amount' => 0,
            'status' => 'submitted',
            'shop_id' => $this->shopOwnerAuth->id,
            'meta' => [
                'created_by' => $this->requester->id,
            ],
        ]);

        $approval = $this->expenseApprovalService->createExpenseApproval(
            $expense,
            $this->shopOwnerMappedUser
        );

        Approval::whereKey($approval->id)->update([
            'requested_by' => $this->requester->id,
        ]);

        return $expense->fresh();
    }
}
