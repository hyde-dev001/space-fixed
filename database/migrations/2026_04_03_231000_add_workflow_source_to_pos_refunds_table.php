<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_refunds', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_refunds', 'workflow_source')) {
                $table->string('workflow_source', 40)->default('pos')->after('module_reference_id');
            }

            $table->index(['module_type', 'workflow_source'], 'pos_refunds_workflow_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_refunds', function (Blueprint $table) {
            $table->dropIndex('pos_refunds_workflow_source_idx');

            if (Schema::hasColumn('pos_refunds', 'workflow_source')) {
                $table->dropColumn('workflow_source');
            }
        });
    }
};
