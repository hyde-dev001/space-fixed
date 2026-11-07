<?php

declare(strict_types=1);

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

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement($this->enumStatement(self::STATUSES));
    }

    public function down(): void
    {
        // The canonical shipped status is owned by the earlier repair-status
        // migration and must remain available when this compatibility repair
        // migration is rolled back.
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
