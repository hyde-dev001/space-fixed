<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner\ActionCenter;

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\Finance\Expense;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceipt;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OwnerActionCenter\Adapters\ExpenseAttentionAdapter;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ExpenseAttentionAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_queue_requires_same_shop_submitted_expense_and_current_pending_owner_approval(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $otherOwner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $otherRequester = User::factory()->create(['shop_owner_id' => $otherOwner->id]);

        $actionable = $this->createExpenseWithApproval($owner, $requester);
        $this->createExpenseWithApproval($otherOwner, $otherRequester);
        $this->createExpenseWithApproval($owner, $requester, approvalStatus: ApprovalStatus::APPROVED);
        $this->createExpenseWithApproval($owner, $requester, currentRole: 'finance', currentLevel: 1);
        $this->createExpenseWithApproval($owner, $requester, status: 'approved');

        $receipt = PurchaseOrderReceipt::factory()->create([
            'purchase_order_id' => PurchaseOrder::factory()->create([
                'shop_owner_id' => $owner->id,
            ])->id,
            'shop_owner_id' => $owner->id,
        ]);
        $procurementExpense = Expense::create([
            'reference' => 'EXP-PROC-'.uniqid(),
            'date' => now()->toDateString(),
            'category' => 'Procurement',
            'description' => 'Receipt-backed expense',
            'amount' => 9000,
            'tax_amount' => 0,
            'status' => 'submitted',
            'shop_id' => $owner->id,
            'procurement_receipt_id' => $receipt->id,
        ]);
        $procurementRequester = User::factory()->create(['shop_owner_id' => $owner->id]);
        Approval::create([
            'shop_owner_id' => $owner->id,
            'approvable_type' => Expense::class,
            'approvable_id' => $procurementExpense->id,
            'reference' => $procurementExpense->reference,
            'description' => $procurementExpense->description,
            'amount' => $procurementExpense->amount,
            'requested_by' => $procurementRequester->id,
            'current_level' => 2,
            'total_levels' => 2,
            'status' => ApprovalStatus::PENDING,
            'approval_roles' => ['1' => 'finance', '2' => 'shop_owner'],
            'current_approver_role' => 'shop_owner',
        ]);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery(perPage: 20));

        $this->assertSame(1, $result->qualifyingCount);
        $this->assertCount(1, $result->items);
        $item = $result->items[0];
        $this->assertSame('expense:'.$actionable->id.':expense_approval', $item->attentionKey);
        $this->assertSame('finance', $item->module);
        $this->assertSame((float) $actionable->amount, $item->comparableMonetaryExposure);
        $this->assertSame(
            '/shop-owner/action-center?bucket=needs_my_decision&approval=expense:'.$actionable->id,
            $item->destinationUrl,
        );
    }

    public function test_individual_shop_owner_has_no_expense_decision_coverage(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
        ]);
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $this->createExpenseWithApproval($owner, $requester);

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery());

        $this->assertSame(0, $result->qualifyingCount);
        $this->assertSame([], $result->items);
    }

    public function test_amount_and_due_date_are_normalized_without_mutating_the_expense(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $expense = $this->createExpenseWithApproval($owner, $requester, amount: 6500, dueDate: now()->addDays(3)->toDateString());
        $before = [
            'status' => $expense->status,
            'amount' => (string) $expense->amount,
            'due_date' => $expense->due_date?->toDateString(),
            'updated_at' => $expense->updated_at?->toISOString(),
        ];

        $result = $this->adapter()->read($owner, new OwnerAttentionQuery());

        $item = $result->items[0];
        $this->assertSame(6500.0, $item->comparableMonetaryExposure);
        $this->assertStringStartsWith($before['due_date'], (string) $item->urgencyAt);
        $fresh = $expense->fresh();
        $this->assertSame($before['status'], $fresh->status);
        $this->assertSame($before['amount'], (string) $fresh->amount);
        $this->assertSame($before['due_date'], $fresh->due_date?->toDateString());
        $this->assertSame($before['updated_at'], $fresh->updated_at?->toISOString());
    }

    public function test_read_query_count_does_not_grow_with_qualifying_rows(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
        ]);
        $requester = User::factory()->create(['shop_owner_id' => $owner->id]);
        $this->createExpenseWithApproval($owner, $requester);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->adapter()->read($owner, new OwnerAttentionQuery());
        $oneRowQueries = count(DB::getQueryLog());

        $this->createExpenseWithApproval($owner, $requester);
        $this->createExpenseWithApproval($owner, $requester);
        DB::flushQueryLog();
        $this->adapter()->read($owner, new OwnerAttentionQuery());
        $manyRowQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $oneRowQueries);
        $this->assertSame($oneRowQueries, $manyRowQueries);
    }

    private function adapter(): ExpenseAttentionAdapter
    {
        return app(ExpenseAttentionAdapter::class);
    }

    private function createExpenseWithApproval(
        ShopOwner $owner,
        User $requester,
        ApprovalStatus $approvalStatus = ApprovalStatus::PENDING,
        string $currentRole = 'shop_owner',
        int $currentLevel = 2,
        string $status = 'submitted',
        float $amount = 6000,
        ?string $dueDate = null,
    ): Expense {
        $expense = Expense::create([
            'reference' => 'EXP-ADAPTER-'.uniqid(),
            'date' => now()->toDateString(),
            'due_date' => $dueDate,
            'category' => 'Operations',
            'description' => 'Adapter expense',
            'amount' => $amount,
            'tax_amount' => 0,
            'status' => $status,
            'shop_id' => $owner->id,
            'created_by' => $requester->id,
        ]);

        $approval = Approval::create([
            'shop_owner_id' => $owner->id,
            'approvable_type' => Expense::class,
            'approvable_id' => $expense->id,
            'reference' => $expense->reference,
            'description' => $expense->description,
            'amount' => $expense->amount,
            'requested_by' => $requester->id,
            'current_level' => $currentLevel,
            'total_levels' => 2,
            'status' => $approvalStatus,
            'approval_roles' => ['1' => 'finance', '2' => 'shop_owner'],
            'current_approver_role' => $currentRole,
        ]);

        $expense->update([
            'approval_id' => $approval->id,
            'current_approval_level' => $currentLevel,
        ]);

        return $expense->fresh();
    }
}
