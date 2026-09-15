<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReportPhaseThreeCRefundAssignmentGapsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_legacy_order_and_repair_assignment_gaps_without_mutating_rows(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $otherOwner = ShopOwner::factory()->approved()->create();
        $orderGap = $this->failedOrderRefund($owner, [
            'recovery_status' => OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'recovery_assigned_at' => null,
            'failure_reason' => 'do not print this order reason',
        ]);
        $repairGap = $this->failedRepairRefund($otherOwner, [
            'recovery_status' => PosRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY,
            'recovery_assigned_at' => null,
            'failure_reason' => 'do not print this repair reason',
        ]);
        $assignedOrder = $this->failedOrderRefund($owner, [
            'recovery_status' => OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'recovery_assigned_at' => now()->subHour(),
        ]);
        $unresolvedRepair = $this->failedRepairRefund($otherOwner, [
            'recovery_status' => PosRefund::RECOVERY_STATUS_UNRESOLVED,
            'recovery_responsible_party' => PosRefund::RECOVERY_RESPONSIBLE_NONE,
            'recovery_assigned_at' => null,
        ]);
        $succeededOrder = $this->failedOrderRefund($owner, [
            'status' => 'succeeded',
            'recovery_status' => OrderRefund::RECOVERY_STATUS_IN_PROGRESS,
            'recovery_responsible_party' => OrderRefund::RECOVERY_RESPONSIBLE_FINANCE,
            'recovery_assigned_at' => null,
        ]);

        $before = [
            'order_gap' => $orderGap->fresh()->only(['status', 'recovery_status', 'recovery_responsible_party', 'recovery_assigned_at', 'failure_reason']),
            'repair_gap' => $repairGap->fresh()->only(['status', 'recovery_status', 'recovery_responsible_party', 'recovery_assigned_at', 'failure_reason']),
            'assigned_order' => $assignedOrder->fresh()->only(['status', 'recovery_status', 'recovery_responsible_party', 'recovery_assigned_at']),
            'unresolved_repair' => $unresolvedRepair->fresh()->only(['status', 'recovery_status', 'recovery_responsible_party', 'recovery_assigned_at']),
            'succeeded_order' => $succeededOrder->fresh()->only(['status', 'recovery_status', 'recovery_responsible_party', 'recovery_assigned_at']),
        ];
        $orderCustomerEmail = (string) $orderGap->customer->email;
        $repairCustomerEmail = (string) $repairGap->requestedByUser->email;

        $this->artisan('shop-owner:report-phase-3c-refund-assignment-gaps')
            ->assertExitCode(0)
            ->expectsOutputToContain('Shop owner '.$owner->id.': order=1, repair=0')
            ->expectsOutputToContain('Shop owner '.$otherOwner->id.': order=0, repair=1')
            ->expectsOutputToContain('Order refund gap IDs: '.$orderGap->id)
            ->expectsOutputToContain('Repair refund gap IDs: '.$repairGap->id)
            ->expectsOutputToContain('Totals: shops=2, order_refunds=1, repair_refunds=1')
            ->doesntExpectOutputToContain('do not print this order reason')
            ->doesntExpectOutputToContain('do not print this repair reason')
            ->doesntExpectOutputToContain($orderCustomerEmail)
            ->doesntExpectOutputToContain($repairCustomerEmail);

        $after = [
            'order_gap' => $orderGap->fresh()->only(['status', 'recovery_status', 'recovery_responsible_party', 'recovery_assigned_at', 'failure_reason']),
            'repair_gap' => $repairGap->fresh()->only(['status', 'recovery_status', 'recovery_responsible_party', 'recovery_assigned_at', 'failure_reason']),
            'assigned_order' => $assignedOrder->fresh()->only(['status', 'recovery_status', 'recovery_responsible_party', 'recovery_assigned_at']),
            'unresolved_repair' => $unresolvedRepair->fresh()->only(['status', 'recovery_status', 'recovery_responsible_party', 'recovery_assigned_at']),
            'succeeded_order' => $succeededOrder->fresh()->only(['status', 'recovery_status', 'recovery_responsible_party', 'recovery_assigned_at']),
        ];

        $this->assertEquals($before, $after);
    }

    private function failedOrderRefund(ShopOwner $owner, array $overrides = []): OrderRefund
    {
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $owner->id,
            'customer_id' => $customer->id,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);

        return OrderRefund::factory()->create(array_merge([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $owner->id,
            'status' => 'failed',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'failure_reason' => 'Refund gateway failed.',
            'failed_at' => now()->subMinute(),
        ], $overrides));
    }

    private function failedRepairRefund(ShopOwner $owner, array $overrides = []): PosRefund
    {
        $customer = User::factory()->create();
        $source = PosTransaction::create([
            'transaction_no' => 'POS-GAP-' . strtoupper(bin2hex(random_bytes(5))),
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

        return PosRefund::query()->create(array_merge([
            'refund_no' => 'RFD-GAP-' . strtoupper(bin2hex(random_bytes(5))),
            'shop_owner_id' => $owner->id,
            'source_transaction_id' => $source->id,
            'module_type' => 'repair',
            'module_reference_id' => 1,
            'workflow_source' => 'online_myrepair',
            'request_type' => 'full',
            'requested_amount' => 500,
            'reason_code' => 'service_defect',
            'status' => 'failed',
            'finance_status' => 'approved',
            'shop_owner_status' => 'approved',
            'repairer_status' => 'approved',
            'requested_by' => $customer->id,
            'requested_at' => now()->subHour(),
            'failure_reason' => 'Refund execution failed.',
            'failed_at' => now()->subMinute(),
        ], $overrides));
    }
}
