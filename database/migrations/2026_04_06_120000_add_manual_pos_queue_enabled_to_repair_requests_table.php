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
        if (Schema::hasColumn('repair_requests', 'manual_pos_queue_enabled')) {
            return;
        }

        Schema::table('repair_requests', function (Blueprint $table) {
            $table->boolean('manual_pos_queue_enabled')
                ->default(false)
                ->after('latest_pos_transaction_id');
            $table->index(['shop_owner_id', 'manual_pos_queue_enabled'], 'repair_requests_manual_pos_queue_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('repair_requests', 'manual_pos_queue_enabled')) {
            return;
        }

        Schema::table('repair_requests', function (Blueprint $table) {
            $table->dropIndex('repair_requests_manual_pos_queue_idx');
            $table->dropColumn('manual_pos_queue_enabled');
        });
    }
};
