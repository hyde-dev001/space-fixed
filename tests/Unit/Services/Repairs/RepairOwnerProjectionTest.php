<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Repairs;

use App\Models\RepairRequest;
use App\Services\Repairs\RepairOwnerProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RepairOwnerProjectionTest extends TestCase
{
    #[Test]
    #[DataProvider('repairStatusGroups')]
    public function it_maps_repair_statuses_to_owner_groups(string $status, string $expectedGroup): void
    {
        $repair = RepairRequest::make(['status' => $status]);

        $result = (new RepairOwnerProjection())->project($repair);

        $this->assertSame($expectedGroup, $result['group']);
        $this->assertSame($status, $result['raw_status']);
    }

    public static function repairStatusGroups(): iterable
    {
        yield 'new request' => ['new_request', 'new_request'];
        yield 'assigned for diagnosis' => ['assigned_to_repairer', 'diagnosis_quote'];
        yield 'awaiting owner approval' => ['pending_owner_approval', 'awaiting_approval'];
        yield 'work in progress' => ['in_progress', 'in_progress'];
        yield 'waiting for parts' => ['awaiting_parts', 'awaiting_parts'];
        yield 'ready for pickup' => ['ready_for_pickup', 'ready_for_customer'];
        yield 'shipped back' => ['shipped', 'delivery_or_pickup'];
        yield 'completed' => ['completed', 'closed'];
        yield 'unknown state' => ['future_state', 'exception'];
    }

    #[Test]
    public function it_preserves_decision_critical_repair_flags(): void
    {
        $repair = RepairRequest::make([
            'status' => 'awaiting_parts',
            'is_high_value' => true,
            'requires_owner_approval' => true,
            'payment_status' => 'pending',
            'pickup_enabled' => true,
            'owner_decision' => 'pending',
            'manager_decision' => 'approved',
        ]);

        $result = (new RepairOwnerProjection())->project($repair);

        $this->assertSame([
            'is_high_value' => true,
            'requires_owner_approval' => true,
            'payment_status' => 'pending',
            'pickup_enabled' => true,
            'owner_decision' => 'pending',
            'manager_decision' => 'approved',
        ], $result['decision_flags']);
    }
}
