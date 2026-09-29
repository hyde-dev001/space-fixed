<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner;

use App\Models\Employee;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Services\Reconciliation\PhaseOneStateInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class PhaseOneStateReconciliationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_only_inventory_is_scoped_deterministic_and_does_not_write(): void
    {
        [$shop, $foreignShop] = $this->seedMixedStateRows();
        $before = $this->rawStateFor($shop->id);

        $firstOutput = $this->runInventoryCommand(['--chunk' => 1]);

        $this->assertMatchesRegularExpression('/Run ID: [0-9a-f-]{36}/i', $firstOutput);
        $this->assertStringContainsString(
            "Shop owner {$shop->id} orders: examined 3, canonical 1, normalizable 0, unresolved 2.",
            $firstOutput,
        );
        $this->assertStringContainsString(
            'Reasons: legacy_refund_fulfillment_unknown=1, unknown_order_status=1',
            $firstOutput,
        );
        $this->assertStringContainsString(
            "Shop owner {$shop->id} employees: examined 4, canonical 1, normalizable 2, unresolved 1.",
            $firstOutput,
        );
        $this->assertStringContainsString('Reasons: legacy_on_leave_status=2, unknown_employee_status=1', $firstOutput);
        $this->assertStringContainsString('Enforcement blocked 1.', $firstOutput);
        $this->assertStringContainsString('Dispositions: rollout_blocker=1.', $firstOutput);

        $this->assertSame($before, $this->rawStateFor($shop->id));
        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $before['leave_employee_id'],
            'status' => 'pending',
        ]);

        $secondOutput = $this->runInventoryCommand(['--chunk' => 1]);

        $withoutRunId = static function (string $output): string {
            return (string) preg_replace('/^Run ID:.*\R/m', '', $output);
        };

        $this->assertSame($withoutRunId($firstOutput), $withoutRunId($secondOutput));
        $this->assertStringContainsString("Shop owner {$foreignShop->id} orders:", $firstOutput);
    }

    public function test_shop_filter_cannot_inspect_another_shops_rows(): void
    {
        [$shop, $foreignShop] = $this->seedMixedStateRows();

        $output = $this->runInventoryCommand([
            '--domain' => 'orders',
            '--shop-owner-id' => $shop->id,
        ]);

        $this->assertStringContainsString("Shop owner {$shop->id} orders:", $output);
        $this->assertStringNotContainsString("Shop owner {$foreignShop->id} orders:", $output);
        $this->assertStringNotContainsString("Shop owner {$foreignShop->id} employees:", $output);
    }

    public function test_apply_normalizes_legacy_leave_status_without_touching_leave_records_or_unknown_values(): void
    {
        [$shop] = $this->seedMixedStateRows();
        $before = $this->rawStateFor($shop->id);
        $beforeLeave = DB::table('leave_requests')
            ->where('employee_id', $before['leave_employee_id'])
            ->value('status');

        $this->runInventoryCommand([
            '--domain' => 'employees',
            '--shop-owner-id' => $shop->id,
            '--apply' => true,
        ]);

        $employees = DB::table('employees')
            ->where('shop_owner_id', $shop->id)
            ->orderBy('id')
            ->pluck('status', 'id')
            ->all();

        $this->assertSame('active', $employees[$before['leave_employee_id']]);
        $this->assertSame('active', $employees[$before['hyphen_leave_employee_id']]);
        $this->assertSame('unknown_employee_state', $employees[$before['unknown_employee_id']]);
        $this->assertSame($beforeLeave, DB::table('leave_requests')
            ->where('employee_id', $before['leave_employee_id'])
            ->value('status'));
    }

    public function test_terminated_employee_is_canonical_and_is_not_normalized(): void
    {
        $shop = ShopOwner::factory()->approved()->create();
        $employee = Employee::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'terminated',
        ]);

        $output = $this->runInventoryCommand([
            '--domain' => 'employees',
            '--shop-owner-id' => $shop->id,
        ]);

        $this->assertStringContainsString(
            "Shop owner {$shop->id} employees: examined 1, canonical 1, normalizable 0, unresolved 0.",
            $output,
        );

        $this->runInventoryCommand([
            '--domain' => 'employees',
            '--shop-owner-id' => $shop->id,
            '--apply' => true,
        ]);

        $this->assertSame('terminated', DB::table('employees')->where('id', $employee->id)->value('status'));
    }

    public function test_apply_is_idempotent_reports_dispositions_and_logs_scoped_counts_without_pii(): void
    {
        Log::spy();
        [$shop] = $this->seedMixedStateRows();
        $beforeOrders = DB::table('orders')
            ->where('shop_owner_id', $shop->id)
            ->orderBy('id')
            ->pluck('status')
            ->all();

        $firstOutput = $this->runInventoryCommand([
            '--domain' => 'all',
            '--shop-owner-id' => $shop->id,
            '--apply' => true,
        ]);

        $this->assertStringContainsString('Mode: apply', $firstOutput);
        $this->assertStringContainsString(
            "Shop owner {$shop->id} employees: examined 4, canonical 1, normalizable 2, unresolved 1.",
            $firstOutput,
        );
        $this->assertStringContainsString('Updated 2.', $firstOutput);
        $this->assertStringContainsString('Enforcement blocked 1.', $firstOutput);
        $this->assertSame(
            $beforeOrders,
            DB::table('orders')
                ->where('shop_owner_id', $shop->id)
                ->orderBy('id')
                ->pluck('status')
                ->all(),
        );

        $secondOutput = $this->runInventoryCommand([
            '--domain' => 'all',
            '--shop-owner-id' => $shop->id,
            '--apply' => true,
        ]);

        $this->assertStringContainsString(
            "Shop owner {$shop->id} employees: examined 4, canonical 3, normalizable 0, unresolved 1.",
            $secondOutput,
        );
        $this->assertStringNotContainsString('Updated 2.', $secondOutput);

        Log::shouldHaveReceived('info')->atLeast()->withArgs(
            static function (string $message, array $context) use ($shop): bool {
                return $message === 'Shop Owner phase-one state reconciliation'
                    && isset(
                        $context['run_id'],
                        $context['domain'],
                        $context['shop_owner_id'],
                        $context['counts'],
                    )
                    && $context['shop_owner_id'] === $shop->id
                    && ! array_key_exists('email', $context)
                    && ! array_key_exists('name', $context)
                    && ! array_key_exists('phone', $context);
            },
        );
    }

    public function test_unresolved_rows_have_a_rollout_blocker_disposition_and_block_enforcement(): void
    {
        [$shop] = $this->seedMixedStateRows();

        $result = app(PhaseOneStateInventory::class)->inspect(
            $shop->id,
            'orders',
            1,
            false,
        );

        $report = $result['reports'][0]['domains']['orders'];

        $this->assertSame(2, $report['unresolved']);
        $this->assertSame(2, $report['enforcement_blocked']);
        $this->assertCount(2, $report['unresolved_rows']);
        $this->assertSame(
            ['rollout_blocker'],
            array_values(array_unique(array_column($report['unresolved_rows'], 'disposition'))),
        );
        $this->assertTrue($report['unresolved_rows'][0]['enforcement_blocked']);
    }

    public function test_apply_rolls_back_the_failed_shop_chunk_without_reverting_prior_completed_chunks(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The failure trigger is SQLite-specific.');
        }

        $shops = [];
        foreach (range(1, 4) as $index) {
            $shop = ShopOwner::factory()->approved()->create();
            $employee = Employee::factory()->create([
                'shop_owner_id' => $shop->id,
                'status' => 'active',
            ]);
            DB::table('employees')->where('id', $employee->id)->update(['status' => 'on_leave']);
            $shops[] = $shop;
        }

        $trigger = 'phase_one_reconciliation_failure';
        DB::statement("CREATE TRIGGER {$trigger}
            BEFORE UPDATE ON employees
            WHEN OLD.shop_owner_id = {$shops[3]->id} AND NEW.status = 'active'
            BEGIN
                SELECT RAISE(ABORT, 'phase-one test failure');
            END");

        try {
            $exception = null;

            try {
                $this->runInventoryCommand([
                    '--domain' => 'employees',
                    '--chunk' => 2,
                    '--apply' => true,
                ]);
            } catch (\Throwable $caught) {
                $exception = $caught;
            }

            $this->assertNotNull($exception);
            $this->assertSame(
                ['active', 'active', 'on_leave', 'on_leave'],
                DB::table('employees')
                    ->whereIn('shop_owner_id', array_map(static fn (ShopOwner $shop): int => $shop->id, $shops))
                    ->orderBy('shop_owner_id')
                    ->pluck('status')
                    ->all(),
            );
        } finally {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    /** @return array{ShopOwner, ShopOwner} */
    private function seedMixedStateRows(): array
    {
        $shop = ShopOwner::factory()->approved()->create();
        $foreignShop = ShopOwner::factory()->approved()->create();

        Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'pending',
        ]);
        Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'status' => 'refund',
        ]);
        $unknownOrder = Order::factory()->create(['shop_owner_id' => $shop->id]);
        DB::table('orders')->where('id', $unknownOrder->id)->update(['status' => 'unknown_order_state']);

        Order::factory()->create([
            'shop_owner_id' => $foreignShop->id,
            'status' => 'delivered',
        ]);

        $activeEmployee = Employee::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'active']);
        $leaveEmployee = Employee::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'active']);
        DB::table('employees')->where('id', $leaveEmployee->id)->update(['status' => 'on_leave']);
        $hyphenLeaveEmployee = Employee::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'active']);
        DB::table('employees')->where('id', $hyphenLeaveEmployee->id)->update(['status' => 'on-leave']);
        $unknownEmployee = Employee::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'active']);
        DB::table('employees')->where('id', $unknownEmployee->id)->update(['status' => 'unknown_employee_state']);

        Employee::factory()->create(['shop_owner_id' => $foreignShop->id, 'status' => 'active']);

        DB::table('leave_requests')->insert([
            'employee_id' => $leaveEmployee->id,
            'shop_owner_id' => $shop->id,
            'leave_type' => 'vacation',
            'start_date' => '2026-08-15',
            'end_date' => '2026-08-16',
            'no_of_days' => 2,
            'is_half_day' => false,
            'reason' => 'Characterization leave record.',
            'status' => 'pending',
            'approval_level' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$shop, $foreignShop];
    }

    /** @return array<string, mixed> */
    private function rawStateFor(int $shopOwnerId): array
    {
        $employees = DB::table('employees')
            ->where('shop_owner_id', $shopOwnerId)
            ->orderBy('id')
            ->get(['id', 'status']);

        return [
            'orders' => DB::table('orders')
                ->where('shop_owner_id', $shopOwnerId)
                ->orderBy('id')
                ->pluck('status')
                ->all(),
            'employees' => $employees->pluck('status', 'id')->all(),
            'leave_employee_id' => $employees->firstWhere('status', 'on_leave')->id,
            'hyphen_leave_employee_id' => $employees->firstWhere('status', 'on-leave')->id,
            'unknown_employee_id' => $employees->firstWhere('status', 'unknown_employee_state')->id,
        ];
    }

    /** @param array<string, mixed> $options */
    private function runInventoryCommand(array $options): string
    {
        $this->assertSame(0, Artisan::call('shop-owner:reconcile-phase-one-state', $options));

        return Artisan::output();
    }
}
