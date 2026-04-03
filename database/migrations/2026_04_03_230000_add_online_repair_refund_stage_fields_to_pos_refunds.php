<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_refunds', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_refunds', 'repairer_status')) {
                $table->string('repairer_status', 30)->default('pending')->after('shop_owner_status');
            }

            if (!Schema::hasColumn('pos_refunds', 'repairer_assessment_note')) {
                $table->text('repairer_assessment_note')->nullable()->after('repairer_status');
            }

            if (!Schema::hasColumn('pos_refunds', 'evidence_snapshot')) {
                $table->json('evidence_snapshot')->nullable()->after('reason_notes');
            }

            if (!Schema::hasColumn('pos_refunds', 'repairer_reviewed_by')) {
                $table->unsignedBigInteger('repairer_reviewed_by')->nullable()->after('repairer_assessment_note');
            }

            if (!Schema::hasColumn('pos_refunds', 'repairer_reviewed_at')) {
                $table->timestamp('repairer_reviewed_at')->nullable()->after('repairer_reviewed_by');
            }

            $table->index(['module_type', 'repairer_status'], 'pos_refunds_repairer_stage_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_refunds', function (Blueprint $table) {
            $table->dropIndex('pos_refunds_repairer_stage_idx');

            if (Schema::hasColumn('pos_refunds', 'repairer_reviewed_at')) {
                $table->dropColumn('repairer_reviewed_at');
            }

            if (Schema::hasColumn('pos_refunds', 'repairer_reviewed_by')) {
                $table->dropColumn('repairer_reviewed_by');
            }

            if (Schema::hasColumn('pos_refunds', 'evidence_snapshot')) {
                $table->dropColumn('evidence_snapshot');
            }

            if (Schema::hasColumn('pos_refunds', 'repairer_assessment_note')) {
                $table->dropColumn('repairer_assessment_note');
            }

            if (Schema::hasColumn('pos_refunds', 'repairer_status')) {
                $table->dropColumn('repairer_status');
            }
        });
    }
};
