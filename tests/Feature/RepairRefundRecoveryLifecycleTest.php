<?php

namespace Tests\Feature;

use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\RepairPosRefundService;
use App\Services\RepairRefundRecoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairRefundRecoveryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function recovery_schema_exposes_the_authoritative_repair_lifecycle(): void
    {
        $this->assertTrue(Schema::hasColumns('pos_refunds', [
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
        $failedAt = CarbonImmutable::parse('2026-08-16 09:45:00');
        $refund = $this->failedRefund([
            'failed_at' => $failedAt,
            'failure_reason' => 'PayMongo declined the refund.',
        ]);
        $service = app(RepairRefundRecoveryService::class);

        $first = $service->initializeFailure($refund->fresh());
        $second = $service->initializeFailure($first->fresh());

        $this->assertSame(PosRefund::RECOVERY_STATUS_UNRESOLVED, $first->recovery_status);
        $this->assertSame(PosRefund::RECOVERY_RESPONSIBLE_NONE, $first->recovery_responsible_party);
        $this->assertSame(0, $first->recovery_attempt_count);
        $this->assertTrue($first->failed_at->equalTo($failedAt));
        $this->assertSame('PayMongo declined the refund.', $first->failure_reason);
        $this->assertSame($first->recovery_status, $second->recovery_status);
        $this->assertSame($first->recovery_attempt_count, $second->recovery_attempt_count);
        $this->assertTrue($second->failed_at->equalTo($failedAt));
        $this->assertSame('PayMongo declined the refund.', $second->failure_reason);
    }

    #[Test]
    public function recording_a_later_failure_does_not_overwrite_original_failure_evidence(): void
    {
        $failedAt = CarbonImmutable::parse('2026-08-16 09:45:00');
        $refund = $this->failedRefund([
            'failed_at' => $failedAt,
            'failure_reason' => 'First repair refund failure.',
        ]);
        $actor = User::factory()->create();

        $recorded = app(RepairRefundRecoveryService::class)->recordFailure(
            refund: $refund,
            actorId: $actor->id,
            reason: 'A later retry also failed.',
            executionNote: 'Gateway retry exhausted.',
        );

        $this->assertSame('failed', $recorded->status);
        $this->assertSame(PosRefund::RECOVERY_STATUS_UNRESOLVED, $recorded->recovery_status);
        $this->assertTrue($recorded->failed_at->equalTo($failedAt));
        $this->assertSame('First repair refund failure.', $recorded->failure_reason);
        $this->assertSame($actor->id, $recorded->executed_by);
        $this->assertSame('Gateway retry exhausted.', $recorded->execution_notes);
    }

    #[Test]
    public function review_rejection_does_not_overwrite_prior_execution_failure_evidence(): void
    {
        $failedAt = CarbonImmutable::parse('2026-08-16 09:45:00');
        $refund = $this->failedRefund([
            'failed_at' => $failedAt,
            'failure_reason' => 'Original execution failure.',
        ]);
        $actor = User::factory()->create();

        $rejected = app(RepairPosRefundService::class)->reject(
            refund: $refund,
            actorId: $actor->id,
            rejectionReason: 'A later review rejected the request.',
            stage: 'finance',
        );

        $this->assertSame('rejected', $rejected->status);
        $this->assertTrue($rejected->failed_at->equalTo($failedAt));
        $this->assertSame('Original execution failure.', $rejected->failure_reason);
    }

    #[Test]
    public function recovery_can_be_claimed_and_retried_with_evidence(): void
    {
        $service = app(RepairRefundRecoveryService::class);
        $refund = $service->initializeFailure($this->failedRefund());
        $attemptedAt = CarbonImmutable::parse('2026-08-16 10:20:00');

        $claimed = $service->claim($refund, PosRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY);
        $retried = $service->recordRetry($claimed, $attemptedAt);

        $this->assertSame(PosRefund::RECOVERY_STATUS_IN_PROGRESS, $retried->recovery_status);
        $this->assertSame(PosRefund::RECOVERY_RESPONSIBLE_PAYMENT_RECOVERY, $retried->recovery_responsible_party);
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
        $service = app(RepairRefundRecoveryService::class);
        $initialized = $service->initializeFailure($refund);

        $this->expectException(ValidationException::class);
        $service->claim($initialized, PosRefund::RECOVERY_RESPONSIBLE_FINANCE);
    }

    #[Test]
    public function replacement_supersedes_only_a_same_transaction_failed_refund(): void
    {
        $service = app(RepairRefundRecoveryService::class);
        $refund = $service->initializeFailure($this->failedRefund());
        $replacement = $this->failedRefund([
            'shop_owner_id' => $refund->shop_owner_id,
            'source_transaction_id' => $refund->source_transaction_id,
            'status' => 'processing',
            'failed_at' => null,
            'failure_reason' => null,
        ]);

        $superseded = $service->replace($refund, $replacement);

        $this->assertSame(PosRefund::RECOVERY_STATUS_SUPERSEDED, $superseded->recovery_status);
        $this->assertSame($replacement->id, $superseded->replacement_refund_id);
        $this->assertTrue($superseded->failed_at->equalTo($refund->failed_at));
        $this->assertSame($refund->failure_reason, $superseded->failure_reason);
    }

    #[Test]
    public function manual_resolution_requires_actor_timestamp_bounded_outcome_and_reason(): void
    {
        $service = app(RepairRefundRecoveryService::class);
        $refund = $service->initializeFailure($this->failedRefund());
        $actor = User::factory()->create();
        $resolvedAt = CarbonImmutable::parse('2026-08-16 11:15:00');

        $resolved = $service->resolve(
            refund: $refund,
            resolvedByType: 'user',
            resolvedById: $actor->id,
            resolvedAt: $resolvedAt,
            outcome: PosRefund::RECOVERY_OUTCOME_MANUAL_REFUND,
            reason: 'Finance completed the verified manual payout and attached the bank reference.',
        );

        $this->assertSame(PosRefund::RECOVERY_STATUS_RESOLVED, $resolved->recovery_status);
        $this->assertSame('user', $resolved->recovery_resolved_by_type);
        $this->assertSame($actor->id, $resolved->recovery_resolved_by_id);
        $this->assertTrue($resolved->recovery_resolved_at->equalTo($resolvedAt));
        $this->assertSame(PosRefund::RECOVERY_OUTCOME_MANUAL_REFUND, $resolved->recovery_resolution_outcome);
        $this->assertSame('Finance completed the verified manual payout and attached the bank reference.', $resolved->recovery_resolution_reason);
        $this->assertTrue($resolved->failed_at->lessThan($resolved->recovery_resolved_at));
    }

    #[Test]
    public function terminal_recovery_cannot_be_claimed_or_retried_again(): void
    {
        $service = app(RepairRefundRecoveryService::class);
        $refund = $service->initializeFailure($this->failedRefund());
        $actor = User::factory()->create();

        $resolved = $service->resolve(
            refund: $refund,
            resolvedByType: 'user',
            resolvedById: $actor->id,
            resolvedAt: now(),
            outcome: PosRefund::RECOVERY_OUTCOME_NO_RECOVERY_REQUIRED,
            reason: 'The original transaction was reversed and no customer payout remains due.',
        );

        $this->expectException(ValidationException::class);
        $service->recordRetry($resolved);
    }

    #[Test]
    public function successful_execution_closes_open_recovery_without_erasing_failure_evidence(): void
    {
        $failedAt = CarbonImmutable::parse('2026-08-16 09:45:00');
        $refund = app(RepairRefundRecoveryService::class)->initializeFailure($this->failedRefund([
            'failed_at' => $failedAt,
            'failure_reason' => 'Initial repair refund timeout.',
        ]));
        $resolvedAt = CarbonImmutable::parse('2026-08-16 12:15:00');
        $refund->update(['status' => 'succeeded']);

        $resolved = app(RepairRefundRecoveryService::class)->recordSuccessfulExecution(
            refund: $refund,
            actorId: User::factory()->create()->id,
            resolvedAt: $resolvedAt,
        );

        $this->assertSame(PosRefund::RECOVERY_STATUS_RESOLVED, $resolved->recovery_status);
        $this->assertSame('user', $resolved->recovery_resolved_by_type);
        $this->assertTrue($resolved->recovery_resolved_at->equalTo($resolvedAt));
        $this->assertSame(PosRefund::RECOVERY_OUTCOME_AUTOMATIC_SUCCESS, $resolved->recovery_resolution_outcome);
        $this->assertTrue($resolved->failed_at->equalTo($failedAt));
        $this->assertSame('Initial repair refund timeout.', $resolved->failure_reason);
    }

    private function failedRefund(array $overrides = []): PosRefund
    {
        $shop = ShopOwner::factory()->approved()->create();
        $customer = User::factory()->create();
        $source = PosTransaction::create([
            'transaction_no' => 'POS-REC-' . strtoupper(bin2hex(random_bytes(5))),
            'shop_owner_id' => $shop->id,
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

        return PosRefund::create(array_merge([
            'refund_no' => 'RFD-REC-' . strtoupper(bin2hex(random_bytes(5))),
            'shop_owner_id' => $shop->id,
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
