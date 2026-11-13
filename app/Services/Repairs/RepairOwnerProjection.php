<?php

declare(strict_types=1);

namespace App\Services\Repairs;

use App\Models\RepairRequest;

final class RepairOwnerProjection
{
    /**
     * @return array{group: string, raw_status: string, decision_flags: array<string, mixed>}
     */
    public function project(RepairRequest $repair): array
    {
        $rawStatus = strtolower(trim((string) $repair->getAttribute('status')));

        return [
            'group' => $this->groupFor($rawStatus),
            'raw_status' => $rawStatus,
            'decision_flags' => [
                'is_high_value' => (bool) $repair->getAttribute('is_high_value'),
                'requires_owner_approval' => (bool) $repair->getAttribute('requires_owner_approval'),
                'payment_status' => $repair->getAttribute('payment_status'),
                'pickup_enabled' => (bool) $repair->getAttribute('pickup_enabled'),
                'owner_decision' => $repair->getAttribute('owner_decision'),
                'manager_decision' => $repair->getAttribute('manager_decision'),
            ],
        ];
    }

    private function groupFor(string $status): string
    {
        return match (true) {
            $status === 'new_request' => 'new_request',
            in_array($status, [
                'assigned_to_repairer',
                'repairer_accepted',
                'waiting_customer_confirmation',
                'owner_approved',
                'confirmed',
                'pending',
                'received',
                'under-review',
            ], true) => 'diagnosis_quote',
            in_array($status, [
                'owner_approval_pending',
                'pending_owner_approval',
                'manager_reviewing',
            ], true) => 'awaiting_approval',
            in_array($status, ['in_progress', 'in-progress'], true) => 'in_progress',
            $status === 'awaiting_parts' => 'awaiting_parts',
            in_array($status, ['ready_for_pickup', 'ready-for-pickup'], true) => 'ready_for_customer',
            in_array($status, ['shipped', 'picked_up'], true) => 'delivery_or_pickup',
            $status === 'completed' => 'closed',
            default => 'exception',
        };
    }
}
