<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LEGACY_OWNER_PENDING_STATUSES = [
        'owner_approval_pending',
        'pending_owner_approval',
    ];

    public function up(): void
    {
        DB::table('repair_requests')
            ->whereIn('status', self::LEGACY_OWNER_PENDING_STATUSES)
            ->whereNotNull('repairer_rejected_at')
            ->where('manager_decision', 'approve_rejection')
            ->whereNotNull('manager_reviewed_at')
            ->update([
                'status' => 'rejected',
                'requires_owner_approval' => false,
            ]);

        DB::table('repair_requests')
            ->whereIn('status', self::LEGACY_OWNER_PENDING_STATUSES)
            ->whereNotNull('repairer_rejected_at')
            ->whereNull('manager_decision')
            ->whereNull('owner_decision')
            ->update([
                'status' => 'repairer_rejected',
                'requires_owner_approval' => false,
            ]);

        DB::table('repair_requests')
            ->whereIn('status', self::LEGACY_OWNER_PENDING_STATUSES)
            ->whereNull('repairer_rejected_at')
            ->whereNull('manager_decision')
            ->whereNull('owner_decision')
            ->update([
                'status' => 'pending',
                'requires_owner_approval' => false,
            ]);
    }

    public function down(): void
    {
        // The original Owner-stage state cannot be recovered without
        // overwriting decisions and reintroducing the removed workflow.
    }
};
