<?php

declare(strict_types=1);

namespace Tests\Feature\ShopOwner;

use App\Models\Employee;
use App\Models\Order;
use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
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
