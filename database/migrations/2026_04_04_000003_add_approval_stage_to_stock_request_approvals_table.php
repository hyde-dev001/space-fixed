<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_request_approvals', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_request_approvals', 'approval_stage')) {
                $table->string('approval_stage')->default('inventory_pending')->after('status');
                $table->index('approval_stage');
            }

            if (!Schema::hasColumn('stock_request_approvals', 'request_source_repair_plan_id')) {
                $table->unsignedBigInteger('request_source_repair_plan_id')->nullable()->after('approval_stage');
            }

            if (!Schema::hasColumn('stock_request_approvals', 'is_auto_generated')) {
                $table->boolean('is_auto_generated')->default(false)->after('request_source_repair_plan_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_request_approvals', function (Blueprint $table) {
            if (Schema::hasColumn('stock_request_approvals', 'approval_stage')) {
                $table->dropIndex(['approval_stage']);
                $table->dropColumn('approval_stage');
            }

            if (Schema::hasColumn('stock_request_approvals', 'request_source_repair_plan_id')) {
                $table->dropColumn('request_source_repair_plan_id');
            }

            if (Schema::hasColumn('stock_request_approvals', 'is_auto_generated')) {
                $table->dropColumn('is_auto_generated');
            }
        });
    }
};
