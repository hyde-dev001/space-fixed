<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE repair_requests CHANGE COLUMN status status ENUM(
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
            'picked_up',
            'pending',
            'received',
            'cancelled',
            'rejected',
            'repairer_rejected',
            'manager_reviewing',
            'manager_approved',
            'manager_rejected'
        ) DEFAULT 'new_request'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE repair_requests CHANGE COLUMN status status ENUM(
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
            'picked_up',
            'pending',
            'received',
            'cancelled',
            'rejected',
            'repairer_rejected',
            'manager_reviewing'
        ) DEFAULT 'new_request'");
    }
};
