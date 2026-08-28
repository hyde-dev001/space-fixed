<?php

namespace Tests\Feature\ShopOwner;

use App\Models\HR\SalaryChange;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\ProcurementSettings;
use App\Models\PurchaseRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ApprovalPolicySnapshotMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_08_22_120000_add_owner_approval_snapshots_to_phase_four_workflows.php';

    public function test_snapshot_columns_are_nullable_only_on_confirmed_workflows_and_backfill_conservatively(): void
    {
        foreach (['order_refunds', 'pos_refunds', 'purchase_requests', 'salary_changes'] as $table) {
            $this->assertNullableSnapshotColumn($table);
        }

        $this->assertSame(0, $this->snapshotColumnCount('approvals'));
        $this->assertSame(1, $this->snapshotColumnCount('repair_requests'));

        [$owner, $customer] = [
            ShopOwner::factory()->approved()->create(),
            User::factory()->create(),
        ];
        $order = Order::factory()->create([
            'shop_owner_id' => $owner->id,
            'customer_id' => $customer->id,
        ]);

        $refundBeforeOwnerStage = OrderRefund::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $owner->id,
            'flow_type' => 'request_approval',
            'status' => 'requested',
            'finance_status' => 'pending',
            'shop_owner_status' => 'pending',
        ]);
        $refundAtOwnerStage = OrderRefund::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $owner->id,
            'flow_type' => 'request_approval',
            'status' => 'pending_approval',
            'finance_status' => 'approved_initial',
            'shop_owner_status' => 'pending',
        ]);
        $refundAfterOwnerStage = OrderRefund::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $owner->id,
            'flow_type' => 'request_approval',
            'status' => 'approved',
            'finance_status' => 'approved',
            'shop_owner_status' => 'approved',
        ]);
        $automaticRefund = OrderRefund::factory()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $owner->id,
            'flow_type' => 'cancel_auto',
            'status' => 'succeeded',
            'finance_status' => 'approved',
            'shop_owner_status' => 'pending',
        ]);

        $transaction = PosTransaction::create([
            'transaction_no' => 'POS-SNAPSHOT-001',
            'shop_owner_id' => $owner->id,
            'module_type' => 'repair',
            'module_reference_id' => 1,
            'customer_type' => 'registered',
            'customer_id' => $customer->id,
            'due_type' => 'full',
            'subtotal' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        $posRefundBeforeOwnerStage = $this->createPosRefund($owner, $customer, $transaction, 'pending');
        $posRefundAfterOwnerStage = $this->createPosRefund($owner, $customer, $transaction, 'approved');
        $posRefundSkippedOwnerStage = $this->createPosRefund($owner, $customer, $transaction, 'skipped');

        $requester = User::factory()->for($owner)->create();
        $purchaseDraft = PurchaseRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'requested_by' => $requester->id,
            'status' => 'draft',
        ]);
        $purchaseBeforeOwnerStage = PurchaseRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'requested_by' => $requester->id,
            'status' => 'pending_finance',
        ]);
        $purchaseAtOwnerStage = PurchaseRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'requested_by' => $requester->id,
            'status' => 'pending_shop_owner',
        ]);
        $purchaseAfterOwnerStage = PurchaseRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'requested_by' => $requester->id,
            'status' => 'pending_finance_final',
        ]);

        $employee = \App\Models\Employee::factory()->active()->create([
            'shop_owner_id' => $owner->id,
        ]);
        $proposer = User::factory()->for($owner)->create();
        $salaryBeforeOwnerStage = $this->createSalaryChange($owner, $employee, $proposer, SalaryChange::STATUS_PENDING);
        $salaryAfterOwnerStage = $this->createSalaryChange($owner, $employee, $proposer, SalaryChange::STATUS_APPROVED);
        $cancelledSalaryChange = $this->createSalaryChange($owner, $employee, $proposer, SalaryChange::STATUS_CANCELLED);

        $migration = require base_path(self::MIGRATION);
        foreach (['order_refunds', 'pos_refunds', 'purchase_requests', 'salary_changes'] as $table) {
            Schema::table($table, static function ($tableBlueprint): void {
                $tableBlueprint->dropColumn('requires_owner_approval');
            });
        }
        $migration->up();

        $this->assertTrue((bool) $this->snapshotValue('order_refunds', $refundBeforeOwnerStage->id));
        $this->assertTrue((bool) $this->snapshotValue('order_refunds', $refundAtOwnerStage->id));
        $this->assertTrue((bool) $this->snapshotValue('order_refunds', $refundAfterOwnerStage->id));
        $this->assertFalse((bool) $this->snapshotValue('order_refunds', $automaticRefund->id));

        $this->assertTrue((bool) $this->snapshotValue('pos_refunds', $posRefundBeforeOwnerStage->id));
        $this->assertTrue((bool) $this->snapshotValue('pos_refunds', $posRefundAfterOwnerStage->id));
        $this->assertFalse((bool) $this->snapshotValue('pos_refunds', $posRefundSkippedOwnerStage->id));

        $this->assertNull($this->snapshotValue('purchase_requests', $purchaseDraft->id));
        $this->assertTrue((bool) $this->snapshotValue('purchase_requests', $purchaseBeforeOwnerStage->id));
        $this->assertTrue((bool) $this->snapshotValue('purchase_requests', $purchaseAtOwnerStage->id));
        $this->assertTrue((bool) $this->snapshotValue('purchase_requests', $purchaseAfterOwnerStage->id));

        $this->assertTrue((bool) $this->snapshotValue('salary_changes', $salaryBeforeOwnerStage->id));
        $this->assertTrue((bool) $this->snapshotValue('salary_changes', $salaryAfterOwnerStage->id));
        $this->assertNull($this->snapshotValue('salary_changes', $cancelledSalaryChange->id));
    }

    public function test_existing_snapshots_survive_later_settings_toggles(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $requester = User::factory()->for($owner)->create();

        $this->actingAs($owner, 'shop_owner')
            ->putJson('/shop-owner/settings', ['approval_pages' => $this->approvalPages(true)])
            ->assertRedirect();

        $onRecord = PurchaseRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'requested_by' => $requester->id,
            'status' => 'pending_shop_owner',
            'requires_owner_approval' => true,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->putJson('/shop-owner/settings', ['approval_pages' => $this->approvalPages(false)])
            ->assertRedirect();

        $offRecord = PurchaseRequest::factory()->create([
            'shop_owner_id' => $owner->id,
            'requested_by' => $requester->id,
            'status' => 'pending_finance_final',
            'requires_owner_approval' => false,
        ]);

        $this->actingAs($owner, 'shop_owner')
            ->putJson('/shop-owner/settings', ['approval_pages' => $this->approvalPages(true)])
            ->assertRedirect();

        $this->assertTrue((bool) $onRecord->fresh()->requires_owner_approval);
        $this->assertFalse((bool) $offRecord->fresh()->requires_owner_approval);
        $settings = ProcurementSettings::query()
            ->where('shop_owner_id', $owner->id)
            ->firstOrFail();
        $this->assertTrue($settings->settings_json['approval_pages']['purchase_request_approval']['enabled']);
    }

    private function assertNullableSnapshotColumn(string $table): void
    {
        $column = collect(Schema::getColumns($table))
            ->firstWhere('name', 'requires_owner_approval');

        $this->assertNotNull($column, "{$table} must have the owner approval snapshot column.");
        $this->assertTrue($column['nullable'], "{$table}.requires_owner_approval must be nullable.");
    }

    private function snapshotColumnCount(string $table): int
    {
        return collect(Schema::getColumnListing($table))
            ->filter(static fn (string $column): bool => $column === 'requires_owner_approval')
            ->count();
    }

    private function snapshotValue(string $table, int $id): mixed
    {
        return \Illuminate\Support\Facades\DB::table($table)
            ->where('id', $id)
            ->value('requires_owner_approval');
    }

    /** @return array<string, array{enabled: bool}> */
    private function approvalPages(bool $enabled): array
    {
        return collect([
            'refund_approval',
            'price_approval',
            'payslip_approval',
            'salary_adjustment_approval',
            'purchase_request_approval',
            'expense_approval',
            'repair_reject_approval',
        ])->mapWithKeys(static fn (string $key): array => [$key => ['enabled' => $enabled]])->all();
    }

    private function createPosRefund(
        ShopOwner $owner,
        User $customer,
        PosTransaction $transaction,
        string $shopOwnerStatus,
    ): PosRefund {
        return PosRefund::create([
            'refund_no' => 'RFD-SNAPSHOT-' . uniqid('', true),
            'shop_owner_id' => $owner->id,
            'source_transaction_id' => $transaction->id,
            'module_type' => 'repair',
            'module_reference_id' => 1,
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'snapshot_test',
            'status' => $shopOwnerStatus === 'approved' ? 'approved' : 'requested',
            'finance_status' => $shopOwnerStatus === 'approved' ? 'approved' : 'pending',
            'shop_owner_status' => $shopOwnerStatus,
            'requested_by' => $customer->id,
            'requested_at' => now(),
        ]);
    }

    private function createSalaryChange(
        ShopOwner $owner,
        \App\Models\Employee $employee,
        User $proposer,
        string $status,
    ): SalaryChange {
        return SalaryChange::create([
            'employee_id' => $employee->id,
            'shop_owner_id' => $owner->id,
            'proposed_by' => $proposer->id,
            'previous_salary' => 1000,
            'new_salary' => 1100,
            'change_percent' => 10,
            'change_type' => SalaryChange::TYPE_MAJOR,
            'effective_date' => now()->toDateString(),
            'reason' => 'Snapshot migration test',
            'status' => $status,
        ]);
    }
}
