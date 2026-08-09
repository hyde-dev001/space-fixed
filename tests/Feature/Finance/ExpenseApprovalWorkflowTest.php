<?php

namespace Tests\Feature\Finance;

use App\Models\Approval;
use App\Models\Finance\Expense;
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

    public function test_expense_v4_workflow_progresses_through_all_four_levels(): void
    {
        $expense = $this->createWorkflowBoundExpense();

        // Level 1: Finance
        $l1 = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/approve", [
                'approval_notes' => 'L1 finance approved',
            ]);

        $l1->assertStatus(200)
            ->assertJson([
                'is_final' => false,
            ]);

        $expense->refresh();
        $this->assertSame(2, $expense->current_approval_level);
        $this->assertSame('submitted', $expense->status);

        // Wrong actor at level 2: finance should fail at owner stage
        $wrongRole = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/approve", [
                'approval_notes' => 'Attempt owner stage as finance',
            ]);

        $wrongRole->assertStatus(422);

        // Level 2: Shop owner
        $l2 = $this->actingAs($this->shopOwnerMappedUser, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/approve", [
                'approval_notes' => 'Owner approved level 2',
            ]);

        $l2->assertStatus(200)
            ->assertJson([
                'is_final' => false,
            ]);

        $expense->refresh();
        $this->assertSame(3, $expense->current_approval_level);

        // Level 3: Finance
        $l3 = $this->actingAs($this->financeSecond, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/approve", [
                'approval_notes' => 'L3 finance approved',
            ]);

        $l3->assertStatus(200)
            ->assertJson([
                'is_final' => false,
            ]);

        $expense->refresh();
        $this->assertSame(4, $expense->current_approval_level);

        // Level 4: Finance final
        $l4 = $this->actingAs($this->financeFinal, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/approve", [
                'approval_notes' => 'L4 final finance approved',
            ]);

        $l4->assertStatus(200)
            ->assertJson([
                'is_final' => true,
            ]);

        $expense->refresh();
        $this->assertSame(4, $expense->current_approval_level);
        $this->assertSame('approved', $expense->status);
    }

    public function test_finance_can_reject_expense_at_level_one(): void
    {
        $expense = $this->createWorkflowBoundExpense();

        $reject = $this->actingAs($this->financeFirst, 'user')
            ->postJson("/api/finance/expenses/{$expense->id}/reject", [
                'approval_notes' => 'Insufficient supporting documents',
            ]);

        $reject->assertStatus(200)
            ->assertJsonStructure(['message', 'expense']);

        $expense->refresh();
        $this->assertSame(1, $expense->current_approval_level);
        $this->assertSame('rejected', $expense->status);
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
                'is_final' => false,
            ]);

        $this->assertSame(2, $expense->fresh()->current_approval_level);
    }

    private function createWorkflowBoundExpense(): Expense
    {
        $expense = Expense::create([
            'reference' => 'EXP-WF-' . now()->timestamp . '-' . random_int(100, 999),
            'date' => now()->toDateString(),
            'category' => 'Operations',
            'vendor' => 'Workflow Vendor',
            'description' => 'Expense workflow feature test',
            'amount' => 2500,
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
