<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\OrderRefundRecoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderRefundRecoveryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function recovery_schema_exposes_the_authoritative_order_lifecycle(): void
    {
        $this->assertTrue(Schema::hasColumns('order_refunds', [
            'recovery_status',
            'recovery_responsible_party',
            'recovery_attempt_count',
            'recovery_last_attempted_at',
            'recovery_resolved_at',
            'recovery_resolved_by_type',
            'recovery_resolved_by_id',
            'recovery_resolution_outcome',
            'recovery_resolution_reason',
            'replacement_refund_id',
        ]));
    }

    #[Test]
    public function failure_initialization_is_idempotent_and_preserves_original_failure_evidence(): void
    {
        $failedAt = CarbonImmutable::parse('2026-08-16 09:30:00');
        $refund = $this->failedRefund([
            'failed_at' => $failedAt,
            'failure_reason' => 'Gateway timeout',
        ]);
        $service = app(OrderRefundRecoveryService::class);

        $first = $service->initializeFailure($refund->fresh());
        $second = $service->initializeFailure($first->fresh());

        $this->assertSame(OrderRefund::RECOVERY_STATUS_UNRESOLVED, $first->recovery_status);
        $this->assertSame(OrderRefund::RECOVERY_RESPONSIBLE_NONE, $first->recovery_responsible_party);
        $this->assertSame(0, $first->recovery_attempt_count);
        $this->assertTrue($first->failed_at->equalTo($failedAt));
        $this->assertSame('Gateway timeout', $first->failure_reason);
        $this->assertSame($first->recovery_status, $second->recovery_status);
        $this->assertSame($first->recovery_attempt_count, $second->recovery_attempt_count);
        $this->assertTrue($second->failed_at->equalTo($failedAt));
        $this->assertSame('Gateway timeout', $second->failure_reason);
    }

    #[Test]
    public function recording_a_later_failure_does_not_overwrite_original_failure_evidence(): void
    {
        $failedAt = CarbonImmutable::parse('2026-08-16 09:30:00');
        $refund = $this->failedRefund([
            'failed_at' => $failedAt,
            'failure_reason' => 'First gateway failure.',
        ]);
        $actor = User::factory()->create();

        $recorded = app(OrderRefundRecoveryService::class)->recordFailure(
            refund: $refund,
            reason: 'A later retry also failed.',
            processedBy: $actor->id,
        );

        $this->assertSame('failed', $recorded->status);
        $this->assertSame(OrderRefund::RECOVERY_STATUS_UNRESOLVED, $recorded->recovery_status);
        $this->assertTrue($recorded->failed_at->equalTo($failedAt));
        $this->assertSame('First gateway failure.', $recorded->failure_reason);
        $this->assertSame($actor->id, $recorded->processed_by);
    }

    #[Test]
    public function recovery_can_be_claimed_and_retried_with_evidence(): void
    {
        $refund = app(OrderRefundRecoveryService::class)->initializeFailure($this->failedRefund());
        $service = app(OrderRefundRecoveryService::class);
        $attemptedAt = CarbonImmutable::parse('2026-08-16 10:15:00');

        $claimed = $service->claim($refund, OrderRefund::RECOVERY_RESPONSIBLE_FINANCE);
        $retried = $service->recordRetry($claimed, $attemptedAt);

        $this->assertSame(OrderRefund::RECOVERY_STATUS_IN_PROGRESS, $retried->recovery_status);
        $this->assertSame(OrderRefund::RECOVERY_RESPONSIBLE_FINANCE, $retried->recovery_responsible_party);
        $this->assertSame(1, $retried->recovery_attempt_count);
        $this->assertTrue($retried->recovery_last_attempted_at->equalTo($attemptedAt));
    }

    #[Test]
    public function owner_decision_takes_precedence_over_recovery_claim(): void
    {
        $refund = $this->failedRefund([
            'shop_owner_status' => 'pending',
            'finance_status' => 'approved_initial',
        ]);
        $service = app(OrderRefundRecoveryService::class);
        $initialized = $service->initializeFailure($refund);

        $this->expectException(ValidationException::class);
        $service->claim($initialized, OrderRefund::RECOVERY_RESPONSIBLE_FINANCE);
    }

    #[Test]
    public function replacement_supersedes_only_a_same_order_failed_refund(): void
    {
        $service = app(OrderRefundRecoveryService::class);
        $refund = $service->initializeFailure($this->failedRefund());
        $replacement = OrderRefund::factory()->create([
            'order_id' => $refund->order_id,
            'shop_owner_id' => $refund->shop_owner_id,
            'customer_id' => $refund->customer_id,
            'status' => 'processing',
        ]);

        $superseded = $service->replace($refund, $replacement);

        $this->assertSame(OrderRefund::RECOVERY_STATUS_SUPERSEDED, $superseded->recovery_status);
        $this->assertSame($replacement->id, $superseded->replacement_refund_id);
        $this->assertTrue($superseded->failed_at->equalTo($refund->failed_at));
        $this->assertSame($refund->failure_reason, $superseded->failure_reason);
    }

    #[Test]
    public function manual_resolution_requires_actor_timestamp_bounded_outcome_and_reason(): void
    {
        $service = app(OrderRefundRecoveryService::class);
        $refund = $service->initializeFailure($this->failedRefund());
        $actor = ShopOwner::factory()->approved()->create();
        $resolvedAt = CarbonImmutable::parse('2026-08-16 11:00:00');

        $resolved = $service->resolve(
            refund: $refund,
            resolvedByType: 'shop_owner',
            resolvedById: $actor->id,
            resolvedAt: $resolvedAt,
            outcome: OrderRefund::RECOVERY_OUTCOME_MANUAL_REFUND,
            reason: 'Customer was reimbursed through the verified manual payout channel.',
        );

        $this->assertSame(OrderRefund::RECOVERY_STATUS_RESOLVED, $resolved->recovery_status);
        $this->assertSame('shop_owner', $resolved->recovery_resolved_by_type);
        $this->assertSame($actor->id, $resolved->recovery_resolved_by_id);
        $this->assertTrue($resolved->recovery_resolved_at->equalTo($resolvedAt));
        $this->assertSame(OrderRefund::RECOVERY_OUTCOME_MANUAL_REFUND, $resolved->recovery_resolution_outcome);
        $this->assertSame('Customer was reimbursed through the verified manual payout channel.', $resolved->recovery_resolution_reason);
        $this->assertTrue($resolved->failed_at->lessThan($resolved->recovery_resolved_at));
    }

    #[Test]
    public function terminal_recovery_cannot_be_claimed_or_retried_again(): void
    {
        $service = app(OrderRefundRecoveryService::class);
        $refund = $service->initializeFailure($this->failedRefund());
        $actor = User::factory()->create();

        $resolved = $service->resolve(
            refund: $refund,
            resolvedByType: 'user',
            resolvedById: $actor->id,
            resolvedAt: now(),
            outcome: OrderRefund::RECOVERY_OUTCOME_NO_RECOVERY_REQUIRED,
            reason: 'The original payment was reversed outside the refund gateway.',
        );

        $this->expectException(ValidationException::class);
        $service->recordRetry($resolved);
    }

    #[Test]
    public function successful_execution_closes_open_recovery_without_erasing_failure_evidence(): void
    {
        $failedAt = CarbonImmutable::parse('2026-08-16 09:30:00');
        $refund = app(OrderRefundRecoveryService::class)->initializeFailure($this->failedRefund([
            'failed_at' => $failedAt,
            'failure_reason' => 'Initial gateway timeout.',
        ]));
        $resolvedAt = CarbonImmutable::parse('2026-08-16 12:00:00');
        $refund->update(['status' => 'succeeded']);

        $resolved = app(OrderRefundRecoveryService::class)->recordSuccessfulExecution(
            refund: $refund,
            actorId: User::factory()->create()->id,
            resolvedAt: $resolvedAt,
        );

        $this->assertSame(OrderRefund::RECOVERY_STATUS_RESOLVED, $resolved->recovery_status);
        $this->assertSame('user', $resolved->recovery_resolved_by_type);
        $this->assertTrue($resolved->recovery_resolved_at->equalTo($resolvedAt));
        $this->assertSame(OrderRefund::RECOVERY_OUTCOME_AUTOMATIC_SUCCESS, $resolved->recovery_resolution_outcome);
        $this->assertTrue($resolved->failed_at->equalTo($failedAt));
        $this->assertSame('Initial gateway timeout.', $resolved->failure_reason);
    }

    private function failedRefund(array $overrides = []): OrderRefund
    {
        $shop = ShopOwner::factory()->approved()->create();
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'shop_owner_id' => $shop->id,
            'customer_id' => $customer->id,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);

        return OrderRefund::factory()->create(array_merge([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'shop_owner_id' => $shop->id,
            'status' => 'failed',
            'shop_owner_status' => 'approved',
            'finance_status' => 'approved',
            'failure_reason' => 'Refund gateway failed.',
            'failed_at' => now()->subMinute(),
        ], $overrides));
    }
}
