<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            // Stores role requirements for each approval level
            // Example: {"1": "finance", "2": "shop_owner", "3": "finance", "4": "finance_final"}
            $table->json('approval_roles')->nullable()->after('total_levels');
            
            // Tracks which role should approve at the current level
            // Example: "finance", "shop_owner", "finance_final"
            $table->string('current_approver_role')->nullable()->after('approval_roles');
            
            // Stores reviewer information at each level for audit trail
            // Example: {"1": {"user_id": 1, "reviewed_at": "..."}, "2": {...}}
            $table->json('level_reviewers')->nullable()->after('current_approver_role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn(['approval_roles', 'current_approver_role', 'level_reviewers']);
        });
    }
};
