<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const STATUSES = [
        'new_request',
        'assigned_to_repairer',
        'repairer_accepted',
        'waiting_customer_confirmation',
        'owner_approval_pending',
        'owner_approved',
        'owner_rejected',
        'confirmed',
        'in_progress',
        'awaiting_parts',
        'completed',
        'ready_for_pickup',
        'shipped',
        'picked_up',
        'pending',
        'received',
        'cancelled',
        'rejected',
        'repairer_rejected',
        'manager_reviewing',
        'manager_approved',
        'manager_rejected',
        'reassignment_required',
        'awaiting_assignment',
    ];

    /** @var list<string> */
    private const LEGACY_STATUSES = [
        'new_request',
        'assigned_to_repairer',
        'repairer_accepted',
        'waiting_customer_confirmation',
        'owner_approval_pending',
        'owner_approved',
        'owner_rejected',
        'confirmed',
        'in_progress',
        'awaiting_parts',
        'completed',
        'ready_for_pickup',
        'shipped',
        'picked_up',
        'pending',
        'received',
        'cancelled',
        'rejected',
        'repairer_rejected',
        'manager_reviewing',
        'manager_approved',
        'manager_rejected',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement($this->enumStatement(self::STATUSES));
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (DB::table('repair_requests')
            ->whereIn('status', ['reassignment_required', 'awaiting_assignment'])
            ->exists()) {
            throw new RuntimeException(
                'Cannot remove manager assignment states while repair requests use them.',
            );
        }

        DB::statement($this->enumStatement(self::LEGACY_STATUSES));
    }

    /**
     * @param  list<string>  $statuses
     */
    private function enumStatement(array $statuses): string
    {
        $values = implode(",\n                ", array_map(
            static fn (string $status): string => "'{$status}'",
            $statuses,
        ));

        return "ALTER TABLE repair_requests CHANGE COLUMN status status ENUM(\n                {$values}\n            ) DEFAULT 'new_request'";
    }
};
