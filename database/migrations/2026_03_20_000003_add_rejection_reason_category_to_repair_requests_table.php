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
        Schema::table('repair_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('repair_requests', 'repairer_rejection_reason_category')) {
                $table->string('repairer_rejection_reason_category', 50)
                    ->nullable()
                    ->after('repairer_rejection_reason');
                $table->index('repairer_rejection_reason_category', 'rr_reason_category_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            if (Schema::hasColumn('repair_requests', 'repairer_rejection_reason_category')) {
                $table->dropIndex('rr_reason_category_index');
                $table->dropColumn('repairer_rejection_reason_category');
            }
        });
    }
};
